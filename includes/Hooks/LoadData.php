<?php

namespace Legenda\NormalSurf\Hooks;

use Legenda\NormalSurf\Hooks\SpectralDataParser;
use Legenda\NormalSurf\Repositories\NoaaRepository;
use PDO;

class LoadData
{
    public static function conn_report(): array
    {
        // 1) Fetch and filter data
        $station = '41112';
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

        // 3) Prepare insert statement
        $insertCols = array_merge(['ts'], $dataCols);
        $placeholders = implode(',', array_fill(0, count($insertCols), '?'));

        $sqlInsert = sprintf(
            "INSERT IGNORE INTO wave_data (%s) VALUES (%s)",
            implode(',', $insertCols),
            $placeholders
        );
        $stmt = $pdo->prepare($sqlInsert);

        // 4) Insert rows
        foreach ($dataRows as $row) {
            $params = [$row['ts']];
            foreach ($dataCols as $col) {
                $params[] = $row[$col];
            }
            $stmt->execute($params);
            //Load all data (50)
            $colsList = implode(',', $dataCols);
            $stmtLatest = $pdo->query("SELECT ts, {$colsList} FROM wave_data ORDER BY ts DESC LIMIT 50");
            $latest = $stmtLatest->fetchAll(PDO::FETCH_ASSOC);
        }
        return [$pdo, $station, $dataCols];
    }
}
