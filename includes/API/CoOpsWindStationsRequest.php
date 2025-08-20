<?php

declare(strict_types=1);

namespace Legenda\NormalSurf\API;

use PDO;
use Exception;
use DateTime;
use DateTimeZone;
use Legenda\NormalSurf\Hooks\LoadData;

/**
 * Fetch + parse realtime wind from NOAA CO-OPS API and (optionally) insert to per-station tables.
 *
 * Stations:
 *  - 8720030 (Fernandina Beach)
 *  - 8720218 (Mayport)
 *  - 8720587 (St Augustine)
 */
final class CoOpsWindStationsRequest
{
    /** Build the CO-OPS API URL */
    public static function url(string $stationId): string
    {
        return "https://api.tidesandcurrents.noaa.gov/api/prod/datagetter?"
            . "product=wind&date=latest&station={$stationId}&time_zone=gmt&units=english&format=json";
    }

    /** Fetch JSON from CO-OPS API */
    public static function fetch_json(string $stationId): array
    {
        $url = self::url($stationId);
        $json = @file_get_contents($url);
        if (!$json) {
            throw new Exception("CoOpsWindStationsRequest: unable to fetch JSON for {$stationId}");
        }
        $data = json_decode($json, true);
        if (!isset($data['data'][0])) {
            throw new Exception("CoOpsWindStationsRequest: malformed/no data for {$stationId}");
        }
        return $data['data'];
    }

    /** Normalize rows */
    public static function fetch_rows(string $stationId): array
    {
        $rows = self::fetch_json($stationId);
        $out = [];
        foreach ($rows as $r) {
            $tsRaw = $r['t'] ?? null;
            if (!$tsRaw) {
                continue; // skip row if timestamp missing
            }
            $ts = new DateTime($tsRaw, new DateTimeZone('UTC'));
           $out[] = [
    'ts'      => $ts->format('Y-m-d H:i:s'),
    'WDIR'    => isset($r['d']) ? (int)$r['d'] : null,   // degrees
    'WSPD_ms' => isset($r['s']) ? round((float)$r['s'] * 0.514444, 2) : null, // kt → m/s
    'WSPD_kt' => isset($r['s']) ? round((float)$r['s'], 2) : null,
];

        }
        return $out;
    }

    /** Upsert rows to DB */
    public static function upsert_rows(PDO $pdo, string $stationId, array $rows): void
    {
        $table = 'winds_' . $stationId;

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

    /** Batch refresh */
    public static function refresh_many(array $stationIds, ?PDO $pdo = null): array
    {
        $out = [];
        foreach ($stationIds as $id) {
            try {
                $rows = self::fetch_rows($id);
                if ($pdo instanceof PDO) {
                    self::upsert_rows($pdo, $id, $rows);
                }
                $out[$id] = $rows;
            } catch (Exception $e) {
                error_log("CO-OPS wind fetch failed for {$id}: " . $e->getMessage());
                $out[$id] = [];
            }
        }
        return $out;
    }

    /** WP Cron glue */
    public static function register_cron(): void
    {
        if (function_exists('add_action')) {
            add_action('wp', [__CLASS__, 'schedule_event']);
            add_action('nrml_refresh_coops_wind', [__CLASS__, 'cron_refresh']);
        }
    }

    public static function schedule_event(): void
    {
        if (function_exists('wp_next_scheduled') && function_exists('wp_schedule_event')) {
            if (!wp_next_scheduled('nrml_refresh_coops_wind')) {
                wp_schedule_event(time(), 'hourly', 'nrml_refresh_coops_wind');
            }
        }
    }

    /** Default cron task */
    public static function cron_refresh(): void
    {
        $stations = ['8720030', '8720218', '8720587'];
        try {
            [$pdo] = LoadData::conn_report();
            self::refresh_many($stations, $pdo);
        } catch (Exception $e) {
            error_log('CoOpsWindStationsRequest cron error: ' . $e->getMessage());
        }
    }
}
