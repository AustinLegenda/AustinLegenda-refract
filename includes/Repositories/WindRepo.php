<?php
declare(strict_types=1);

namespace Legenda\NormalSurf\Repositories;

use PDO;
use Exception;
use Legenda\NormalSurf\API\CoOpsWindStationsRequest;
use Legenda\NormalSurf\API\NDBCWindStationsRequest;

final class WindRepo
{
    /** Table name helper */
    public static function table(string $stationCode): string
    {
        return 'winds_' . strtoupper($stationCode);
    }

    /** Create table if not exists */
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

    // ------------------------------
    // Insert helpers
    // ------------------------------

    public static function insertCoOpsRows(PDO $pdo, string $stationCode, array $rows): int
    {
        $table = self::table($stationCode);
        self::ensureTable($pdo, $table);

        if (empty($rows)) return 0;

        $stmt = $pdo->prepare("
            INSERT INTO `{$table}` (`ts`, `WDIR`, `WSPD_ms`, `WSPD_kt`)
            VALUES (:ts, :wdir, :ms, :kt)
            ON DUPLICATE KEY UPDATE
              `WDIR` = VALUES(`WDIR`),
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

    public static function insertNDBCRows(PDO $pdo, string $stationCode, array $rows): int
    {
        $table = self::table($stationCode);
        self::ensureTable($pdo, $table);

        if (empty($rows)) return 0;

        $stmt = $pdo->prepare("
            INSERT INTO `{$table}` (`ts`, `WDIR`, `WSPD_ms`, `WSPD_kt`)
            VALUES (:ts, :wdir, :ms, :kt)
            ON DUPLICATE KEY UPDATE
              `WDIR` = VALUES(`WDIR`),
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

    // ------------------------------
    // Refresh wrappers
    // ------------------------------

    public static function refreshCoOps(PDO $pdo, string $stationCode): int
    {
        $rows = CoOpsWindStationsRequest::fetch_rows($stationCode);
        return self::insertCoOpsRows($pdo, $stationCode, $rows);
    }

    public static function refreshNDBC(PDO $pdo, string $stationCode): int
    {
        $rows = NDBCWindStationsRequest::fetch_rows($stationCode);
        return self::insertNDBCRows($pdo, $stationCode, $rows);
    }

    // ------------------
    // Batch
    // ------------------

 public static function refreshMany(PDO $pdo, array $stationCodes): array
{
    $out = [];
    foreach ($stationCodes as $code) {
        try {
            if (ctype_digit($code)) {
                // All numeric → CO-OPS
                $out[$code] = self::refreshCoOps($pdo, $code);
            } else {
                // Alpha → NDBC
                $out[$code] = self::refreshNDBC($pdo, $code);
            }
        } catch (Exception $e) {
            error_log("WindRepo refresh failed for {$code}: " . $e->getMessage());
            $out[$code] = 0;
        }
    }
    return $out;
}


    // ------------------
    // Read convenience
    // ------------------

    public static function latest(PDO $pdo, string $stationCode): ?array
    {
        $table = self::table($stationCode);
        self::ensureTable($pdo, $table);
        $sql = "SELECT ts, WDIR, WSPD_ms, WSPD_kt FROM `{$table}` ORDER BY ts DESC LIMIT 1";
        $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function prev(PDO $pdo, string $stationCode, string $utcTs): ?array
    {
        $table = self::table($stationCode);
        $stmt = $pdo->prepare("SELECT ts, WDIR, WSPD_ms, WSPD_kt
                               FROM `{$table}` WHERE ts < :ts
                               ORDER BY ts DESC LIMIT 1");
        $stmt->execute([':ts' => $utcTs]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function next(PDO $pdo, string $stationCode, string $utcTs): ?array
    {
        $table = self::table($stationCode);
        $stmt = $pdo->prepare("SELECT ts, WDIR, WSPD_ms, WSPD_kt
                               FROM `{$table}` WHERE ts > :ts
                               ORDER BY ts ASC LIMIT 1");
        $stmt->execute([':ts' => $utcTs]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function range(PDO $pdo, string $stationCode, string $startUtc, string $endUtc, int $limit = 500): array
    {
        $table = self::table($stationCode);
        $stmt = $pdo->prepare("SELECT ts, WDIR, WSPD_ms, WSPD_kt
                               FROM `{$table}`
                               WHERE ts BETWEEN :start AND :end
                               ORDER BY ts ASC
                               LIMIT {$limit}");
        $stmt->execute([':start' => $startUtc, ':end' => $endUtc]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
