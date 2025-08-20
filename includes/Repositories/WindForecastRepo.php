<?php
declare(strict_types=1);

namespace Legenda\NormalSurf\Repositories;

use PDO;
use Exception;
use Legenda\NormalSurf\API\NWSGridpointWindRequest;

final class WindForecastRepo
{
    /** Table name helper (use your logical key) e.g., '41112','median','41117' */
    public static function table(string $key): string
    {
        return 'winds_fcst_' . strtolower($key); // winds_fcst_41112, winds_fcst_median, winds_fcst_41117
    }

    private static function ensureTable(PDO $pdo, string $table): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `{$table}` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `ts` DATETIME NOT NULL,
                `WDIR` INT NULL,
                `WSPD_ms` DECIMAL(6,2) NULL,
                `WSPD_kt` DECIMAL(6,2) NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_ts` (`ts`),
                KEY `idx_ts` (`ts`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }

    private static function insertRows(PDO $pdo, string $key, array $rows): int
    {
        $table = self::table($key);
        self::ensureTable($pdo, $table);
        if (empty($rows)) return 0;

        $stmt = $pdo->prepare("
            INSERT INTO `{$table}` (`ts`, `WDIR`, `WSPD_ms`, `WSPD_kt`)
            VALUES (:ts, :wdir, :ms, :kt)
            ON DUPLICATE KEY UPDATE
              `WDIR`    = VALUES(`WDIR`),
              `WSPD_ms` = VALUES(`WSPD_ms`),
              `WSPD_kt` = VALUES(`WSPD_kt`)
        ");

        foreach ($rows as $r) {
            $stmt->execute([
                ':ts'   => $r['ts'],
                ':wdir' => $r['WDIR'],
                ':ms'   => $r['WSPD_ms'],
                ':kt'   => $r['WSPD_kt'],
            ]);
        }
        return count($rows);
    }

    /** Refresh one gridpoint and upsert */
    public static function refresh(PDO $pdo, string $key, string $office, int $x, int $y): int
    {
        $rows = NWSGridpointWindRequest::fetch_rows($office, $x, $y);
        return self::insertRows($pdo, $key, $rows);
    }

    /** Batch refresh for many */
    public static function refreshMany(PDO $pdo, array $defs): array
    {
        // $defs = [ ['key'=>'41112','office'=>'JAX','x'=>71,'y'=>80], ... ]
        $out = [];
        foreach ($defs as $d) {
            try {
                $out[$d['key']] = self::refresh($pdo, $d['key'], $d['office'], (int)$d['x'], (int)$d['y']);
            } catch (Exception $e) {
                error_log("ForecastWindRepo refresh failed for {$d['key']}: ".$e->getMessage());
                $out[$d['key']] = 0;
            }
        }
        return $out;
    }

    // Reads
    public static function latest(PDO $pdo, string $key): ?array
    {
        $table = self::table($key);
        self::ensureTable($pdo, $table);
        $sql = "SELECT ts, WDIR, WSPD_ms, WSPD_kt FROM `{$table}` ORDER BY ts DESC LIMIT 1";
        $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function range(PDO $pdo, string $key, string $startUtc, string $endUtc, int $limit = 2000): array
    {
        $table = self::table($key);
        $stmt = $pdo->prepare("
            SELECT ts, WDIR, WSPD_ms, WSPD_kt
            FROM `{$table}`
            WHERE ts BETWEEN :start AND :end
            ORDER BY ts ASC
            LIMIT {$limit}
        ");
        $stmt->execute([':start'=>$startUtc, ':end'=>$endUtc]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
