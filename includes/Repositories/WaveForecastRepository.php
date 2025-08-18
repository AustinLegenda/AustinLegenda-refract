<?php
namespace Legenda\NormalSurf\Repositories;

use PDO;

class WaveForecastRepository
{
    /**
     * Import a JSON forecast (from your Python) into a per-station table.
     * - If $tableName null, derive from filename like wave_point_41112.json -> waves_41112
     * - Expects payload: { meta:{...}, data:[ {time, Hs_m, Dir_deg, Per_s}, ... ] }
     * - Stores: UTC+local timestamps, heights in m+ft, dir deg + 16-pt compass, period s
     *
     * @return string Table name used (e.g., waves_41112)
     */
    public static function importJson(
        PDO $pdo,
        string $jsonPath,
        ?string $tableName = null,
        string $localTz = 'America/New_York'
    ): string {
        if (!is_file($jsonPath)) {
            throw new \RuntimeException("JSON not found: {$jsonPath}");
        }
        $raw = file_get_contents($jsonPath);
        $payload = json_decode($raw, true);
        if (!is_array($payload) || !isset($payload['data']) || !is_array($payload['data'])) {
            throw new \RuntimeException("Invalid JSON structure in {$jsonPath}");
        }

        // Resolve table
        $table = $tableName ?: self::deriveTableNameFromFilename($jsonPath);

        // Ensure table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `{$table}` (
              id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              t_local       DATETIME        NOT NULL,
              t_utc         DATETIME        NOT NULL,
              hs_m          DECIMAL(6,3)    NULL,
              hs_ft         DECIMAL(6,3)    NULL,
              dir_deg       DECIMAL(6,2)    NULL,
              dir_compass   CHAR(3)         NULL,
              per_s         DECIMAL(6,2)    NULL,
              src_model     VARCHAR(32)     NOT NULL DEFAULT 'gfswave',
              created_at    TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
              updated_at    TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              UNIQUE KEY uq_time (t_utc),
              KEY idx_time (t_utc),
              KEY idx_local (t_local)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $ins = $pdo->prepare("
            INSERT INTO `{$table}` (t_local, t_utc, hs_m, hs_ft, dir_deg, dir_compass, per_s, src_model)
            VALUES (:t_local, :t_utc, :hs_m, :hs_ft, :dir_deg, :dir_compass, :per_s, :src_model)
            ON DUPLICATE KEY UPDATE
              hs_m = VALUES(hs_m),
              hs_ft = VALUES(hs_ft),
              dir_deg = VALUES(dir_deg),
              dir_compass = VALUES(dir_compass),
              per_s = VALUES(per_s),
              updated_at = CURRENT_TIMESTAMP
        ");

        $tzLocal = new \DateTimeZone($localTz);
        $model   = (string)($payload['meta']['model'] ?? 'gfswave');

        foreach ($payload['data'] as $row) {
            if (!isset($row['time'])) continue;

            // Time is UTC ISO8601; normalize
            $utc = self::parseUtcIso($row['time']);
            if (!$utc) continue;

            $local = clone $utc;
            $local->setTimezone($tzLocal);

            $hs_m  = self::toNullableFloat($row['Hs_m'] ?? null);
            $hs_ft = is_null($hs_m) ? null : $hs_m * 3.28084;

            $dir   = self::toNullableFloat($row['Dir_deg'] ?? null);
            $comp  = is_null($dir) ? null : self::degToCompass($dir);

            $per_s = self::toNullableFloat($row['Per_s'] ?? null);

            $ins->execute([
                ':t_local'     => $local->format('Y-m-d H:i:00'),
                ':t_utc'       => $utc->format('Y-m-d H:i:00'),
                ':hs_m'        => self::roundOrNull($hs_m, 3),
                ':hs_ft'       => self::roundOrNull($hs_ft, 2),
                ':dir_deg'     => self::roundOrNull($dir, 2),
                ':dir_compass' => $comp,
                ':per_s'       => self::roundOrNull($per_s, 2),
                ':src_model'   => $model,
            ]);
        }

        return $table;
    }

    /** Bulk import all JSONs in a directory (e.g., .data/wave-forecast). */
    public static function importDirectory(PDO $pdo, string $dir, string $localTz = 'America/New_York'): array
    {
        if (!is_dir($dir)) throw new \RuntimeException("Dir not found: {$dir}");
        $imported = [];
        foreach (glob(rtrim($dir, '/')."/*.json") as $path) {
            $table = self::importJson($pdo, $path, null, $localTz);
            $imported[] = ['file' => basename($path), 'table' => $table];
        }
        return $imported;
    }

    /** Get a forward time window (UTC) for UI/api. */
    public static function getRange(PDO $pdo, string $stationId, string $startUtc, string $endUtc, int $limit = 500): array
    {
        $table = self::tableFor($stationId);
        $stmt = $pdo->prepare("
            SELECT t_utc, t_local, hs_m, hs_ft, dir_deg, dir_compass, per_s
            FROM `{$table}`
            WHERE t_utc >= :start AND t_utc <= :end
            ORDER BY t_utc ASC
            LIMIT :lim
        ");
        $stmt->bindValue(':start', $startUtc);
        $stmt->bindValue(':end',   $endUtc);
        $stmt->bindValue(':lim',   $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Next N forecast rows from now (UTC). */
    public static function getNext(PDO $pdo, string $stationId, string $nowUtc, int $limit = 8): array
    {
        $table = self::tableFor($stationId);
        $stmt = $pdo->prepare("
            SELECT t_utc, t_local, hs_m, hs_ft, dir_deg, dir_compass, per_s
            FROM `{$table}`
            WHERE t_utc >= :now
            ORDER BY t_utc ASC
            LIMIT :lim
        ");
        $stmt->bindValue(':now', $nowUtc);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Latest row strictly before now (UTC). */
    public static function getPrev(PDO $pdo, string $stationId, string $nowUtc): ?array
    {
        $table = self::tableFor($stationId);
        $stmt = $pdo->prepare("
            SELECT t_utc, t_local, hs_m, hs_ft, dir_deg, dir_compass, per_s
            FROM `{$table}`
            WHERE t_utc < :now
            ORDER BY t_utc DESC
            LIMIT 1
        ");
        $stmt->execute([':now' => $nowUtc]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    // ---------------- helpers ----------------

    /** Map 'wave_point_41112.json' → 'waves_41112'. */
    private static function deriveTableNameFromFilename(string $jsonPath): string
    {
        $base = basename($jsonPath);
        if (preg_match('/(\d{5,9})/', $base, $m)) {
            return 'waves_' . $m[1];
        }
        throw new \RuntimeException("Unable to derive table name from filename; pass \$tableName.");
    }

    /** Map '41112' → 'waves_41112'. */
    private static function tableFor(string $stationId): string
    {
        return 'waves_' . preg_replace('/\D+/', '', $stationId);
    }

    private static function toNullableFloat($v): ?float
    {
        if ($v === null) return null;
        if ($v === '') return null;
        if (!is_numeric($v)) return null;
        return (float)$v;
    }

    private static function roundOrNull(?float $v, int $decimals): ?float
    {
        return is_null($v) ? null : round($v, $decimals);
    }

    private static function parseUtcIso(string $iso): ?\DateTime
    {
        // Normalize variants like "2025-08-18T06:00:00" and "...Z"
        $s = rtrim($iso);
        if (!preg_match('/Z$/', $s)) $s .= 'Z';
        $dt = \DateTime::createFromFormat(\DateTime::ISO8601, $s);
        if ($dt === false) {
            // Fallback
            try {
                $dt = new \DateTime($s, new \DateTimeZone('UTC'));
            } catch (\Exception $e) {
                return null;
            }
        }
        $dt->setTimezone(new \DateTimeZone('UTC'));
        return $dt;
    }

    /** 16-point compass (coming-from). */
    private static function degToCompass(float $deg): string
    {
        static $dirs = ["N","NNE","NE","ENE","E","ESE","SE","SSE",
                        "S","SSW","SW","WSW","W","WNW","NW","NNW"];
        $i = (int)floor((fmod(($deg + 11.25), 360.0)) / 22.5);
        return $dirs[$i % 16];
    }
}
