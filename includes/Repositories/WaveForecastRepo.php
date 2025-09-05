<?php
declare(strict_types=1);

namespace Legenda\NormalSurf\Repositories;

use PDO;

final class WaveForecastRepo
{
    /**
     * Import one JSON file.
     * Accepts either:
     *   A) { meta:{...}, data:[ {time, Hs_m, Dir_deg, Per_s}, ... ] }   (current files)
     *   B) [ {t_utc, hs_m, dir_deg, per_s}, ... ]                       (legacy flat rows)
     *
     * If $tableName is null, infer from filename: wave_point_(\d+)\.json -> waves_<ID>.
     */
    public static function importJson(
        PDO $pdo,
        string $jsonPath,
        ?string $tableName = null,
        string $localTz = 'America/New_York' // kept for parity
    ): string {
        if (!\is_file($jsonPath)) return "SKIP: not a file: $jsonPath";

        $base = \basename($jsonPath);

        if ($tableName === null) {
            if (\preg_match('/wave_point_(\d+)\.json$/', $base, $m)) {
                $tableName = 'waves_' . $m[1];
            } else {
                return "SKIP: cannot infer table for $base";
            }
        }

        $raw = \file_get_contents($jsonPath);
        if ($raw === false) return "SKIP: read error $jsonPath";

        $json = \json_decode($raw, true);
        if (!\is_array($json)) return "SKIP: bad json $base";

        $rows = self::normalizeRows($json);
        if (!$rows) return "SKIP: no usable rows in $base";

        self::ensureTable($pdo, $tableName);

        $pdo->beginTransaction();
        try {
            $sql = "INSERT INTO `$tableName` (t_utc, hs_m, per_s, dir_deg)
                    VALUES (:t_utc, :hs_m, :per_s, :dir_deg)
                    ON DUPLICATE KEY UPDATE
                        hs_m=VALUES(hs_m),
                        per_s=VALUES(per_s),
                        dir_deg=VALUES(dir_deg)";
            $stmt = $pdo->prepare($sql);

            foreach ($rows as $r) {
                $stmt->execute([
                    ':t_utc'   => $r['t_utc'],
                    ':hs_m'    => $r['hs_m'],
                    ':per_s'   => $r['per_s'],
                    ':dir_deg' => $r['dir_deg'],
                ]);
            }

            $pdo->commit();
            return "OK $base -> $tableName rows=" . \count($rows);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return "ERR $base -> $tableName: " . $e->getMessage();
        }
    }

    /** Import all matching JSON files under a directory (recursive). */
    public static function importDirectory(PDO $pdo, string $dir, string $localTz = 'America/New_York'): array
    {
        $stats = ['files' => 0, 'ok' => 0, 'skip' => 0, 'err' => 0, 'messages' => []];

        if (!\is_dir($dir)) {
            $stats['messages'][] = "MISSING dir: $dir";
            return $stats;
        }

        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($rii as $spl) {
            if (!$spl->isFile()) continue;
            $name = $spl->getFilename();

            if (!\preg_match('/^wave_point_(\d+)\.json$/', $name)) continue;

            $stats['files']++;
            $res = self::importJson($pdo, $spl->getPathname(), null, $localTz);
            $stats['messages'][] = $res;

            if (\str_starts_with($res, 'OK'))       $stats['ok']++;
            elseif (\str_starts_with($res, 'SKIP')) $stats['skip']++;
            else                                     $stats['err']++;
        }

        return $stats;
    }

    // ===== Query helpers used by Interpolator/ImportFC =====

    public static function getPrev(PDO $pdo, string $stationId, string $nowUtc): ?array
    {
        $table = self::tableForStation($stationId);
        $sql = "SELECT t_utc, hs_m, per_s, dir_deg
                  FROM `$table`
                 WHERE t_utc <= :now
              ORDER BY t_utc DESC
                 LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->bindValue(':now', $nowUtc, PDO::PARAM_STR);
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function getNext(PDO $pdo, string $stationId, string $nowUtc, int $limit = 8): array
    {
        $table = self::tableForStation($stationId);
        $sql = "SELECT t_utc, hs_m, per_s, dir_deg
                  FROM `$table`
                 WHERE t_utc >= :now
              ORDER BY t_utc ASC
                 LIMIT :lim";
        $st = $pdo->prepare($sql);
        $st->bindValue(':now', $nowUtc, PDO::PARAM_STR);
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function getRange(PDO $pdo, string $stationId, string $startUtc, string $endUtc, int $limit = 500): array
    {
        $table = self::tableForStation($stationId);
        $sql = "SELECT t_utc, hs_m, per_s, dir_deg
                  FROM `$table`
                 WHERE t_utc >= :start AND t_utc <= :end
              ORDER BY t_utc ASC
                 LIMIT :lim";
        $st = $pdo->prepare($sql);
        $st->bindValue(':start', $startUtc, PDO::PARAM_STR);
        $st->bindValue(':end',   $endUtc,   PDO::PARAM_STR);
        $st->bindValue(':lim',   $limit,    PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // ===== Internals =====

    private static function normalizeRows(array $json): array
    {
        // Case A: { meta:{...}, data:[ ... ] }
        if (isset($json['data']) && \is_array($json['data'])) {
            $out = [];
            foreach ($json['data'] as $r) {
                if (!\is_array($r) || !isset($r['time'])) continue;

                $tUtc = self::toUtcMinute((string)$r['time']);
                if ($tUtc === null) continue;

                $hs  = self::numOrNull($r['Hs_m']    ?? $r['hs_m']    ?? null);
                $per = self::numOrNull($r['Per_s']   ?? $r['per_s']   ?? null);
                $dir = self::numOrNull($r['Dir_deg'] ?? $r['dir_deg'] ?? null);

                $out[] = [
                    't_utc'   => $tUtc,
                    'hs_m'    => $hs,
                    'per_s'   => $per,
                    'dir_deg' => $dir,
                ];
            }
            return $out;
        }

        // Case B: legacy flat list
        if (self::isList($json)) {
            $out = [];
            foreach ($json as $r) {
                if (!\is_array($r) || !isset($r['t_utc'])) continue;
                $out[] = [
                    't_utc'   => self::toUtcMinute((string)$r['t_utc']) ?? (string)$r['t_utc'],
                    'hs_m'    => self::numOrNull($r['hs_m']    ?? null),
                    'per_s'   => self::numOrNull($r['per_s']   ?? null),
                    'dir_deg' => self::numOrNull($r['dir_deg'] ?? null),
                ];
            }
            return $out;
        }

        return [];
    }

    private static function numOrNull($v): ?float
    {
        return \is_numeric($v) ? (float)$v : null;
    }

    private static function toUtcMinute(string $t): ?string
    {
        try {
            $dt = new \DateTime($t, new \DateTimeZone('UTC'));
            return $dt->format('Y-m-d H:i:00');
        } catch (\Throwable) {
            if (\ctype_digit($t)) {
                $dt = (new \DateTime('@' . $t))->setTimezone(new \DateTimeZone('UTC'));
                return $dt->format('Y-m-d H:i:00');
            }
            return null;
        }
    }

    private static function ensureTable(PDO $pdo, string $table): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `$table` (
                `t_utc`   DATETIME NOT NULL,
                `hs_m`    DOUBLE NULL,
                `per_s`   DOUBLE NULL,
                `dir_deg` DOUBLE NULL,
                PRIMARY KEY (`t_utc`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }

    private static function tableForStation(string $stationId): string
    {
        if (!\preg_match('/^\d+$/', $stationId)) {
            throw new \InvalidArgumentException('Bad station id: ' . $stationId);
        }
        return 'waves_' . $stationId;
    }

    private static function isList(array $array): bool
    {
        if (\function_exists('array_is_list')) {
            return \array_is_list($array);
        }
        $i = 0;
        foreach ($array as $k => $_) {
            if ($k !== $i++) return false;
        }
        return true;
    }
}
