<?php

namespace Legenda\NormalSurf\Hooks;

use Legenda\NormalSurf\Hooks\SpectralDataParser;
use Legenda\NormalSurf\Repositories\NoaaRepository;
use Legenda\NormalSurf\Repositories\NoaaTideRepository;
use PDO;

class LoadData
{
    public static function conn_report(string $station = '41112'): array
    {
        // 1) Fetch and filter data
        $rawData = NoaaRepository::get_data($station);
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
        return NoaaTideRepository::importAnnualHLXml($pdo, $xmlPath, $tableName);
    }

    /**
     * Convenience (by station id): prev + next two HLs at $nowUtc.
     * Uses table 'tides_{stationId}'.
     */
    public static function tides_window(PDO $pdo, string $stationId, string $nowUtc): array
    {
        $prev = NoaaTideRepository::getPrevHL($pdo, $stationId, $nowUtc);
        $next = NoaaTideRepository::getNextHL($pdo, $stationId, $nowUtc, 2);
        return [$prev, $next];
    }

    /**
     * Convenience (by table name): same as above, but when you already know the table.
     * Example: $tbl = 'tides_8720030';
     */
    public static function tides_window_by_table(PDO $pdo, string $table, string $nowUtc): array
    {
        $prev = NoaaTideRepository::getPrevHLByTable($pdo, $table, $nowUtc);
        $next = NoaaTideRepository::getNextHLByTable($pdo, $table, $nowUtc, 2);
        return [$prev, $next];
    }
}
