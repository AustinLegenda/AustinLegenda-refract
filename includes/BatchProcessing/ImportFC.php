<?php

declare(strict_types=1);

namespace Legenda\NormalSurf\BatchProcessing;

use PDO;
use Throwable;

use Legenda\NormalSurf\Repositories\TideRepo;
use Legenda\NormalSurf\Repositories\WaveForecastRepo;
use Legenda\NormalSurf\Repositories\WindForecastRepo;


final class ImportFC
{
    /** Create a PDO using constants from config.php */
public static function pdo(): PDO
{
    require_once \dirname(__DIR__, 2) . '/config.php';

    // Build a CLI-safe DSN that honors port/socket
    $dsn = 'mysql:';
    if (defined('DB_SOCKET') && DB_SOCKET) {
        $dsn .= 'unix_socket=' . DB_SOCKET . ';';
    } else {
        $dsn .= 'host=' . DB_HOST . ';';
        $dsn .= 'port=' . (defined('DB_PORT') ? DB_PORT : '3306') . ';';
    }
    $dsn .= 'dbname=' . DB_NAME . ';charset=utf8mb4';

    return new PDO(
        $dsn,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
}

    /** Resolve common paths used by the batch job */
    public static function paths(): array
    {
        $root = \realpath(\dirname(__DIR__, 2)) ?: \getcwd();

        return [
            'root'       => $root,
            'logs'       => $root . '/logs',
            // Default example file; override via options if different:
            'tides_xml'  => $root . '/assets/xml_data/8720587_annual.xml',
            'waves_dir'  => $root . '/data/wave-forecast',
        ];
    }

    private static function log(string $msg): void
    {
        $paths = self::paths();
        if (!\is_dir($paths['logs'])) {
            @\mkdir($paths['logs'], 0775, true);
        }
        @\file_put_contents(
            $paths['logs'] . '/refresh_all.log',
            '[' . \gmdate('c') . '] ' . $msg . PHP_EOL,
            \FILE_APPEND
        );
    }

    /* =========================
     * Tides import (annual XML) → tides_{station}
     * ========================= */

    public static function import_tides_from_xml(PDO $pdo, string $xmlPath, ?string $tableName = null): string
    {
        return TideRepo::importAnnualHLXml($pdo, $xmlPath, $tableName);
    }

    public static function tides_window(PDO $pdo, string $stationId, string $nowUtc): array
    {
        $prev = TideRepo::getPrevHL($pdo, $stationId, $nowUtc);
        $next = TideRepo::getNextHL($pdo, $stationId, $nowUtc, 2);
        return [$prev, $next];
    }

    public static function tides_window_by_table(PDO $pdo, string $table, string $nowUtc): array
    {
        $prev = TideRepo::getPrevHLByTable($pdo, $table, $nowUtc);
        $next = TideRepo::getNextHLByTable($pdo, $table, $nowUtc, 2);
        return [$prev, $next];
    }

    /* =========================
     * Wave forecast (JSON files) → waves_{station}
     * ========================= */

    public static function import_waves_from_json(
        PDO $pdo,
        string $jsonPath,
        ?string $tableName = null,
        string $localTz = 'America/New_York'
    ): string {
        return WaveForecastRepo::importJson($pdo, $jsonPath, $tableName, $localTz);
    }

    public static function import_waves_from_dir(
        PDO $pdo,
        string $dirPath,
        string $localTz = 'America/New_York'
    ): array {
        return WaveForecastRepo::importDirectory($pdo, $dirPath, $localTz);
    }

    public static function waves_next(PDO $pdo, string $stationId, string $nowUtc, int $limit = 8): array
    {
        return WaveForecastRepo::getNext($pdo, $stationId, $nowUtc, $limit);
    }

    public static function waves_prev(PDO $pdo, string $stationId, string $nowUtc): ?array
    {
        return WaveForecastRepo::getPrev($pdo, $stationId, $nowUtc);
    }

    public static function waves_range(
        PDO $pdo,
        string $stationId,
        string $startUtc,
        string $endUtc,
        int $limit = 500
    ): array {
        return WaveForecastRepo::getRange($pdo, $stationId, $startUtc, $endUtc, $limit);
    }


    /* =========================
     * Wind forecast → winds_fcst_{key}
     * ========================= */

    /**
     * $defs = [['key'=>'41112','office'=>'JAX','x'=>71,'y'=>80], ...]
     */
    public static function winds_fcst_refresh(PDO $pdo, array $defs): array
    {
        return WindForecastRepo::refreshMany($pdo, $defs);
    }

    public static function winds_fcst_latest(PDO $pdo, string $key): ?array
    {
        return WindForecastRepo::latest($pdo, $key);
    }

    public static function winds_fcst_range(
        PDO $pdo,
        string $key,
        string $startUtc,
        string $endUtc,
        int $limit = 2000
    ): array {
        return WindForecastRepo::range($pdo, $key, $startUtc, $endUtc, $limit);
    }

    /* =========================
     * Orchestrator
     * ========================= */

    public static function refresh_all(array $opts = []): array
    {
        $t0  = \microtime(true);
        $out = [];

        // Ensure logs dir exists
        $paths = self::paths();
        if (!\is_dir($paths['logs'])) {
            @\mkdir($paths['logs'], 0775, true);
        }

        $pdo = $opts['pdo'] ?? self::pdo();

        // --- Tides (annual XML) ---
        try {
            $tidesXml = $opts['tides_xml'] ?? $paths['tides_xml'];
            if ($tidesXml && \is_file($tidesXml)) {
                $out['tides'] = self::import_tides_from_xml($pdo, $tidesXml);
            } else {
                self::log("tides_xml missing or not a file: " . ($tidesXml ?? '(null)'));
            }
        } catch (Throwable $e) {
            self::log('tides import failed: ' . $e->getMessage());
            $out['tides_error'] = $e->getMessage();
        }

        // --- Wave forecast (JSON dir) ---
        try {
            $wavesDir = $opts['waves_dir'] ?? $paths['waves_dir'];
            if ($wavesDir && \is_dir($wavesDir)) {
                $out['waves'] = self::import_waves_from_dir($pdo, $wavesDir);
            } else {
                self::log("waves_dir missing or not a dir: " . ($wavesDir ?? '(null)'));
            }
        } catch (Throwable $e) {
            self::log('waves import failed: ' . $e->getMessage());
            $out['waves_error'] = $e->getMessage();
        }

        // --- Wind forecast (NWS gridpoint) ---
        try {
            $defs = $opts['winds_fcst_defs'] ?? [
                ['key' => '41112',  'office' => 'JAX', 'x' => 71, 'y' => 80],
                ['key' => 'median', 'office' => 'JAX', 'x' => 74, 'y' => 68],
                ['key' => '41117',  'office' => 'JAX', 'x' => 83, 'y' => 45],
            ];
            if (\is_array($defs) && !empty($defs)) {
                $out['winds_fcst'] = self::winds_fcst_refresh($pdo, $defs);
            }
        } catch (Throwable $e) {
            self::log('winds_fcst refresh failed: ' . $e->getMessage());
            $out['winds_fcst_error'] = $e->getMessage();
        }


        $dt = \sprintf('%.2fs', \microtime(true) - $t0);
        self::log("refresh_all OK ($dt)");

        return $out;
    }
}
