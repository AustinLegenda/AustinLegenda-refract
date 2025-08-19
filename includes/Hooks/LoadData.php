<?php

namespace Legenda\NormalSurf\Hooks;

use Legenda\NormalSurf\BatchProcessing\SpectralDataParser;
use Legenda\NormalSurf\Repositories\WaveBuoyRepo;
use Legenda\NormalSurf\Repositories\TideRepo;
use Legenda\NormalSurf\Repositories\WaveForecastRepo;

use PDO;

class LoadData
{
    public static function conn_report(string $station = '41112'): array
    {
        // 1) Fetch and filter data
        $rawData = WaveBuoyRepo::get_data($station);
        $data    = SpectralDataParser::filter($rawData);

        $dataCols = $data['columns'];
        $dataRows = $data['data'];

        // 2) Connect to DB
        require_once dirname(__DIR__, 2) . '/config.php';
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $table = "station_" . preg_replace('/\D/', '', $station);

        // 3) Prepare insert statement
        $insertCols = array_merge(['ts'], $dataCols);
        $placeholders = implode(',', array_fill(0, count($insertCols), '?'));

        $sqlInsert = sprintf(
            "INSERT IGNORE INTO `%s` (%s) VALUES (%s)",
            $table,
            implode(',', $insertCols),
            $placeholders
        );
        $stmt = $pdo->prepare($sqlInsert);

        // 4) Insert rows
        foreach ($dataRows as $row) {
            $params = [$row['ts']];
            foreach ($dataCols as $col) {
                $params[] = $row[$col] ?? null;
            }
            $stmt->execute($params);
        }

        // 5) Fetch recent data
        $colsList = implode(',', $dataCols);
        $stmtLatest = $pdo->query("SELECT ts, {$colsList} FROM `$table` ORDER BY ts DESC LIMIT 50");
        $latest = $stmtLatest->fetchAll(PDO::FETCH_ASSOC);

        return [$pdo, $station, $dataCols, $colsList, $table];
    }
    // TIDES
    public static function import_tides_from_xml(PDO $pdo, string $xmlPath, ?string $tableName = null): string
    {
        // lets the repo derive table from the filename (e.g., 8720030_annual.xml → tides_8720030)
        // or you can pass 'tides_8720030' explicitly via $tableName
        return TideRepo::importAnnualHLXml($pdo, $xmlPath, $tableName);
    }

    /**
     * Convenience (by station id): prev + next two HLs at $nowUtc.
     * Uses table 'tides_{stationId}'.
     */
    public static function tides_window(PDO $pdo, string $stationId, string $nowUtc): array
    {
        $prev = TideRepo::getPrevHL($pdo, $stationId, $nowUtc);
        $next = TideRepo::getNextHL($pdo, $stationId, $nowUtc, 2);
        return [$prev, $next];
    }

    /**
     * Convenience (by table name): same as above, but when you already know the table.
     * Example: $tbl = 'tides_8720030';
     */
    public static function tides_window_by_table(PDO $pdo, string $table, string $nowUtc): array
    {
        $prev = TideRepo::getPrevHLByTable($pdo, $table, $nowUtc);
        $next = TideRepo::getNextHLByTable($pdo, $table, $nowUtc, 2);
        return [$prev, $next];
    }

    //wave forecast
        // ---- WAVES (GFS-Wave JSON → per-station tables like waves_41112) ----

    /**
     * Import a single wave JSON file (e.g., .data/wave-forecast/wave_point_41112.json).
     * Returns the table used, e.g. 'waves_41112'.
     */
    public static function import_waves_from_json(
        PDO $pdo,
        string $jsonPath,
        ?string $tableName = null,
        string $localTz = 'America/New_York'
    ): string {
        return WaveForecastRepo::importJson($pdo, $jsonPath, $tableName, $localTz);
    }

    /**
     * Import all wave JSON files from a directory (e.g., .data/wave-forecast).
     * Returns an array of ['file' => ..., 'table' => ...].
     */
    public static function import_waves_from_dir(
        PDO $pdo,
        string $dirPath,
        string $localTz = 'America/New_York'
    ): array {
        return WaveForecastRepo::importDirectory($pdo, $dirPath, $localTz);
    }

    /**
     * Convenience: next N rows from now (UTC) for a station id like '41112'.
     */
    public static function waves_next(PDO $pdo, string $stationId, string $nowUtc, int $limit = 8): array
    {
        return WaveForecastRepo::getNext($pdo, $stationId, $nowUtc, $limit);
    }

    /**
     * Convenience: previous row strictly before now (UTC) for a station id.
     */
    public static function waves_prev(PDO $pdo, string $stationId, string $nowUtc): ?array
    {
        return WaveForecastRepo::getPrev($pdo, $stationId, $nowUtc);
    }

    /**
     * Convenience: explicit range [startUtc, endUtc] (good for “tomorrow”).
     */
    public static function waves_range(PDO $pdo, string $stationId, string $startUtc, string $endUtc, int $limit = 500): array
    {
        return WaveForecastRepo::getRange($pdo, $stationId, $startUtc, $endUtc, $limit);
    }

}
