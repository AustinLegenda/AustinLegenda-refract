<?php

namespace Legenda\NormalSurf\Hooks;

use Legenda\NormalSurf\Hooks\SpectralDataParser;
use Legenda\NormalSurf\Repositories\NoaaRepository;
use PDO;

class LoadData
{
    public static function conn_report(string $station = '41112'): array
    {
        require_once dirname(__DIR__, 2) . '/config.php';
        $pdo = new \PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );

        $table = "station_" . preg_replace('/\D/', '', $station); // sanitized table name

        $stmt = $pdo->query("DESCRIBE `$table`");
        $columns = array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'Field');
        $colsList = implode(',', array_filter($columns, fn($c) => $c !== 'ts'));

        return [$pdo, $station, array_filter($columns, fn($c) => $c !== 'ts'), $colsList, $table];
    }

    public static function insert_data(PDO $pdo, string $station): void
    {
        $table = "station_" . preg_replace('/\D/', '', $station);
        $data = \Legenda\NormalSurf\Repositories\NoaaRepository::get_data($station);
        $filtered = \Legenda\NormalSurf\Hooks\SpectralDataParser::filter($data);

        $columns = $filtered['columns'];
        $rows = $filtered['data'];

        if (empty($rows)) return;

        $colList = implode(',', array_merge(['ts'], $columns));
        $placeholders = implode(',', array_fill(0, count($columns) + 1, '?'));

        $sql = "INSERT IGNORE INTO `$table` ($colList) VALUES ($placeholders)";
        $stmt = $pdo->prepare($sql);

        foreach ($rows as $row) {
            $values = array_merge([$row['ts']], array_map(fn($c) => $row[$c] ?? null, $columns));
            $stmt->execute($values);
        }
    }
}
