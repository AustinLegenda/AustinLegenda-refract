<?php
declare(strict_types=1);

namespace Legenda\NormalSurf\Hooks;

use PDO;
use Legenda\NormalSurf\BatchProcessing\SpectralDataParser;
use Legenda\NormalSurf\Repositories\WaveBuoyRepo;
use Legenda\NormalSurf\Repositories\TideRepo;
use Legenda\NormalSurf\Repositories\WaveForecastRepo;
use Legenda\NormalSurf\Repositories\WindRepo;
use Legenda\NormalSurf\Repositories\WindForecastRepo;

class LoadData
{
    /* =========================
     * Core: DB handle + paths
     * ========================= */

    public static function pdo(): PDO
    {
        // config.php must define DB_HOST, DB_NAME, DB_USER, DB_PASS
        require_once \dirname(__DIR__, 2) . '/config.php';

        return new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }

    public static function paths(): array
    {
        $root = \realpath(\dirname(__DIR__, 2));

        return [
            'root'       => $root,
            'logs'       => $root . '/logs',
            'tides_xml'  => $root . '/assets/xml_data/8720587_annual.xml',
            'waves_dir'  => $root . '/data/wave-forecast',
        ];
    }

    /* =========================
     * Buoy observations → station_{id}
     * ========================= */

    /**
     * Pulls buoy spectral observations via WaveBuoyRepo and INSERT IGNOREs into station_{id}.
     * Returns tuple: [PDO $pdo, string $station, array $dataCols, string $colsList, string $table]
     */
// in LoadData.php
public static function conn_report(string|array $station = '41112'): array
{
    // If array: import many, return just [$pdo] for compatibility with [$conn] = ...
    if (is_array($station)) {
        $pdo = self::pdo(); // new helper you added earlier, or inline your PDO construction
        foreach ($station as $s) {
            self::conn_report_one($pdo, (string)$s);
        }
        return [$pdo]; // backward-compatible: first element is PDO
    }

    // Single-station behavior (preserves your previous return shape)
    $pdo = self::pdo();
    [$dataCols, $dataRows] = self::fetch_and_filter_station((string)$station); // helper for clarity
    $table = "station_" . preg_replace('/\D/', '', $station);

    $insertCols   = array_merge(['ts'], $dataCols);
    $placeholders = implode(',', array_fill(0, count($insertCols), '?'));
    $sqlInsert = sprintf(
        "INSERT IGNORE INTO `%s` (%s) VALUES (%s)",
        $table,
        implode(',', $insertCols),
        $placeholders
    );
    $stmt = $pdo->prepare($sqlInsert);

    $inserted = 0;
    foreach ($dataRows as $row) {
        $params = [$row['ts']];
        foreach ($dataCols as $col) { $params[] = $row[$col] ?? null; }
        $stmt->execute($params);
        $inserted += $stmt->rowCount();
    }

    // Optional breadcrumb
    @file_put_contents(
        dirname(__DIR__,2).'/logs/conn_report.log',
        sprintf("[%s] station=%s inserted=%d/%d\n", gmdate('c'), $station, $inserted, count($dataRows)),
        FILE_APPEND
    );

    $colsList = implode(',', $dataCols);
    return [$pdo, (string)$station, $dataCols, $colsList, $table];
}

    /* =========================
     * Wind observations → CoOps Wind Stations
     * ========================= */

 public static function conn_winds(string|array $stations = ['8720030','8720218','SAUF1'], ?int $ttlSec = null): array
    {
        $pdo = self::pdo();

        // TTL guard (optional): skip if recently refreshed
        if ($ttlSec !== null) {
            $root = \realpath(\dirname(__DIR__, 2));
            $flag = $root.'/.ns_winds.flag';
            $stale = !\is_file($flag) || (time() - \filemtime($flag) > $ttlSec);
            if (!$stale) {
                return [$pdo, ['skipped' => true]];
            }
            @\touch($flag);
        }

        $list = \is_array($stations) ? $stations : [$stations];
        $counts = WindRepo::refreshMany($pdo, $list);

        // tiny breadcrumb (non-fatal if logs/ missing)
        $logs = self::paths()['logs'] ?? (\realpath(\dirname(__DIR__, 2)).'/logs');
        @\mkdir($logs, 0775, true);
        @\file_put_contents(
            $logs.'/conn_winds.log',
            '['.\gmdate('c').'] '.json_encode(['stations'=>$list,'counts'=>$counts])."\n",
            \FILE_APPEND
        );

        return [$pdo, $counts];
    }

/* Helpers to keep things tidy. If you don’t like helpers, inline in conn_report(). */
private static function fetch_and_filter_station(string $station): array {
    $raw   = \Legenda\NormalSurf\Repositories\WaveBuoyRepo::get_data($station);
    $data  = \Legenda\NormalSurf\BatchProcessing\SpectralDataParser::filter($raw);
    return [$data['columns'], $data['data']];
}

private static function conn_report_one(\PDO $pdo, string $station): void {
    [$dataCols, $dataRows] = self::fetch_and_filter_station($station);
    $table = "station_" . preg_replace('/\D/', '', $station);

    $insertCols   = array_merge(['ts'], $dataCols);
    $placeholders = implode(',', array_fill(0, count($insertCols), '?'));
    $sqlInsert = sprintf(
        "INSERT IGNORE INTO `%s` (%s) VALUES (%s)",
        $table,
        implode(',', $insertCols),
        $placeholders
    );
    $stmt = $pdo->prepare($sqlInsert);

    foreach ($dataRows as $row) {
        $params = [$row['ts']];
        foreach ($dataCols as $col) { $params[] = $row[$col] ?? null; }
        $stmt->execute($params);
    }
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

    public static function waves_range(PDO $pdo, string $stationId, string $startUtc, string $endUtc, int $limit = 500): array
    {
        return WaveForecastRepo::getRange($pdo, $stationId, $startUtc, $endUtc, $limit);
    }

    /* =========================
     * Wind observations → winds_{station}
     * ========================= */

    /**
     * Refresh observed winds for many stations.
     * If your WindRepo::refreshMany supports a $source ('coops'|'ndbc'), call it directly instead.
     */
    public static function winds_refresh(PDO $pdo, array $stationCodes, ?string $source = null): array
    {
        // If your repo supports source as 3rd arg, pass it through:
        if ($source !== null) {
            return WindRepo::refreshMany($pdo, $stationCodes, $source);
        }
        return WindRepo::refreshMany($pdo, $stationCodes);
    }

    public static function winds_latest(PDO $pdo, string $stationCode): ?array
    {
        return WindRepo::latest($pdo, $stationCode);
    }

    public static function winds_range(PDO $pdo, string $stationCode, string $startUtc, string $endUtc, int $limit = 500): array
    {
        return WindRepo::range($pdo, $stationCode, $startUtc, $endUtc, $limit);
    }

    public static function winds_prev(PDO $pdo, string $stationCode, string $nowUtc): ?array
    {
        return WindRepo::prev($pdo, $stationCode, $nowUtc);
    }

    public static function winds_next(PDO $pdo, string $stationCode, string $nowUtc): ?array
    {
        return WindRepo::next($pdo, $stationCode, $nowUtc);
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

    public static function winds_fcst_range(PDO $pdo, string $key, string $startUtc, string $endUtc, int $limit = 2000): array
    {
        return WindForecastRepo::range($pdo, $key, $startUtc, $endUtc, $limit);
    }

    /* =========================
     * Orchestrator
     * ========================= */

    /**
     * One entrypoint to refresh everything. Pass options to override defaults.
     *
     * Options (all optional):
     *  - tides_xml: string path to an annual tides XML
     *  - waves_dir: string dir containing wave forecast JSONs
     *  - winds_fcst_defs: array of ['key','office','x','y']
     *  - winds_obs_coops: string[] CO-OPS station codes (e.g. ['8720030','8720218'])
     *  - winds_obs_ndbc:  string[] NDBC station codes  (e.g. ['SAUF1'])
     *  - pdo: PDO handle (otherwise created automatically)
     */
    public static function refresh_all(array $opts = []): array
    {
        $paths = self::paths();
        // Ensure logs dir exists (best-effort)
        if (!\is_dir($paths['logs'])) {
            @\mkdir($paths['logs'], 0775, true);
        }

        $pdo  = $opts['pdo'] ?? self::pdo();
        $out  = [];
        $t0   = \microtime(true);

        // Tides
        $tidesXml = $opts['tides_xml'] ?? $paths['tides_xml'];
        if ($tidesXml && \is_file($tidesXml)) {
            $out['tides'] = self::import_tides_from_xml($pdo, $tidesXml);
        }

        // Wave forecast (JSON dir)
        $wavesDir = $opts['waves_dir'] ?? $paths['waves_dir'];
        if ($wavesDir && \is_dir($wavesDir)) {
            $out['waves'] = self::import_waves_from_dir($pdo, $wavesDir);
        }

        // Wind forecast (NWS gridpoint)
        $defs = $opts['winds_fcst_defs'] ?? [
            ['key' => '41112',  'office' => 'JAX', 'x' => 71, 'y' => 80],
            ['key' => 'median', 'office' => 'JAX', 'x' => 74, 'y' => 68],
            ['key' => '41117',  'office' => 'JAX', 'x' => 83, 'y' => 45],
        ];
        if (\is_array($defs) && !empty($defs)) {
            $out['winds_fcst'] = self::winds_fcst_refresh($pdo, $defs);
        }

        // Wind observations (CO-OPS + NDBC)
        $coops = $opts['winds_obs_coops'] ?? ['8720030', '8720218'];
        $ndbc  = $opts['winds_obs_ndbc']  ?? ['SAUF1'];

        if (\is_array($coops) && !empty($coops)) {
            // If your WindRepo::refreshMany supports source 'coops', pass it:
            try {
                $out['winds_obs_coops'] = WindRepo::refreshMany($pdo, $coops, 'coops');
            } catch (\ArgumentCountError $e) {
                // fallback to two-arg signature
                $out['winds_obs_coops'] = WindRepo::refreshMany($pdo, $coops);
            }
        }
        if (\is_array($ndbc) && !empty($ndbc)) {
            try {
                $out['winds_obs_ndbc'] = WindRepo::refreshMany($pdo, $ndbc, 'ndbc');
            } catch (\ArgumentCountError $e) {
                $out['winds_obs_ndbc'] = WindRepo::refreshMany($pdo, $ndbc);
            }
        }

        $dt = \sprintf('%.2fs', \microtime(true) - $t0);
        @\file_put_contents($paths['logs'] . '/refresh_all.log', '[' . \gmdate('c') . "] refresh_all OK ($dt)\n", \FILE_APPEND);

        return $out;
    }
}

/* ============================================================
 * Optional CLI entrypoint: run this file directly to refresh.
 * ============================================================

Usage examples:
  php src/Hooks/LoadData.php --all
  php src/Hooks/LoadData.php --waves_dir=/abs/path/to/data/wave-forecast
  php src/Hooks/LoadData.php --tides_xml=/abs/path/to/8720587_annual.xml \
                             --coops=8720030,8720218 --ndbc=SAUF1

Exit codes: 0 on success, 1 on error.
*/
if (\PHP_SAPI === 'cli' && \basename(__FILE__) === \basename($_SERVER['argv'][0] ?? '')) {
    $args = $_SERVER['argv'];
    \array_shift($args); // script

    $opts = [];
    foreach ($args as $a) {
        if ($a === '--all') { continue; }
        if (\str_starts_with($a, '--tides_xml='))    { $opts['tides_xml']     = \substr($a, 12); continue; }
        if (\str_starts_with($a, '--waves_dir='))    { $opts['waves_dir']     = \substr($a, 12); continue; }
        if (\str_starts_with($a, '--coops='))        { $opts['winds_obs_coops'] = \array_filter(\explode(',', \substr($a, 8))); continue; }
        if (\str_starts_with($a, '--ndbc='))         { $opts['winds_obs_ndbc']  = \array_filter(\explode(',', \substr($a, 7))); continue; }
        if (\str_starts_with($a, '--defs=')) {
            // JSON array for wind-forecast defs
            $json = \substr($a, 7);
            $defs = \json_decode($json, true);
            if (\is_array($defs)) { $opts['winds_fcst_defs'] = $defs; }
            continue;
        }
    }

    try {
        $out = LoadData::refresh_all($opts);
        echo \json_encode(['ok' => true, 'result' => $out], \JSON_PRETTY_PRINT) . \PHP_EOL;
        exit(0);
    } catch (\Throwable $e) {
        \fwrite(\STDERR, '[' . \gmdate('c') . '] ERROR ' . $e->getMessage() . \PHP_EOL);
        exit(1);
    }
}
