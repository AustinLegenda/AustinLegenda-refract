<?php
declare(strict_types=1);

namespace Legenda\NormalSurf\API;

use PDO;
use Exception;
use Legenda\NormalSurf\BatchProcessing\NDBCWindParser;
use Legenda\NormalSurf\BatchProcessing\ImportCC;

/**
 * Fetch + parse realtime wind TXT from NDBC and (optionally) insert to per-station tables.
 *
 * Example stations:
 *  - FRDF1 (Fernandina)   : https://www.ndbc.noaa.gov/data/realtime2/FRDF1.txt
 *  - MYPF1 (Mayport)      : https://www.ndbc.noaa.gov/data/realtime2/MYPF1.txt
 *  - SAUF1 (St Augustine) : https://www.ndbc.noaa.gov/data/realtime2/SAUF1.txt
 */
final class NDBCWindStationsRequest
{
    /** Build the realtime2 TXT URL */
    public static function url(string $stationCode): string
    {
        return "https://www.ndbc.noaa.gov/data/realtime2/{$stationCode}.txt";
    }

    /** Fetch raw lines from NDBC realtime2 TXT */
    public static function fetch_raw_txt(string $stationCode): array
    {
        $url = self::url($stationCode);
        $lines = @file($url, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) {
            throw new Exception("NDBCWindStationsRequest: unable to fetch TXT for {$stationCode}");
        }
        return $lines;
    }

    /** Fetch + parse into normalized wind rows */
    public static function fetch_rows(string $stationCode): array
    {
        $lines = self::fetch_raw_txt($stationCode);
        return NDBCWindParser::rows($lines);
    }

    /**
     * Optional: persist to DB (per-station table).
     * Table: winds_<STATION> (e.g., winds_FRDF1)
     * Schema:
     *   id (PK AI), ts (DATETIME UTC, UNIQUE), WDIR INT NULL,
     *   WSPD_ms DECIMAL(6,2) NULL, WSPD_kt DECIMAL(6,2) NULL
     */
    public static function upsert_rows(PDO $pdo, string $stationCode, array $rows): void
    {
        $table = 'winds_' . $stationCode;

        // Ensure table exists
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `{$table}` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `ts` DATETIME NOT NULL,
                `WDIR` INT NULL,
                `WSPD_ms` DECIMAL(6,2) NULL,
                `WSPD_kt` DECIMAL(6,2) NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_ts` (`ts`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

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
    }

    /** Batch fetch + upsert */
    public static function refresh_many(array $stationCodes, ?PDO $pdo = null): array
    {
        $out = [];
        foreach ($stationCodes as $code) {
            try {
                $rows = self::fetch_rows($code);
                if ($pdo instanceof PDO) {
                    self::upsert_rows($pdo, $code, $rows);
                }
                $out[$code] = $rows;
            } catch (Exception $e) {
                error_log("NDBC wind fetch failed for {$code}: " . $e->getMessage());
                $out[$code] = [];
            }
        }
        return $out;
    }

    // ——————————————————————————————————
    // WordPress cron glue
    // ——————————————————————————————————

    public static function register_cron(): void
    {
        if (function_exists('add_action')) {
            add_action('wp', [__CLASS__, 'schedule_event']);
            add_action('nrml_refresh_ndbc_wind', [__CLASS__, 'cron_refresh']);
        }
    }

    public static function schedule_event(): void
    {
        if (function_exists('wp_next_scheduled') && function_exists('wp_schedule_event')) {
            if (!wp_next_scheduled('nrml_refresh_ndbc_wind')) {
                wp_schedule_event(time(), 'hourly', 'nrml_refresh_ndbc_wind');
            }
        }
    }

    /** Default cron task */
    public static function cron_refresh(): void
    {
        $stations = ['SAUF1'];

        try {
            [$pdo] = ImportCC::conn_winds();
            self::refresh_many($stations, $pdo);
        } catch (Exception $e) {
            error_log('NDBCWindStationsRequest cron error: ' . $e->getMessage());
        }
    }
}
