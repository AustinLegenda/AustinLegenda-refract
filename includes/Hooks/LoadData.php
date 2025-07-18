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

public static function insert_data(PDO $pdo, string $station, array $rows): void
{
    if (empty($rows)) {
        echo "No rows passed to insert_data for station $station";
        return;
    }

    $table = "station_" . preg_replace('/\D/', '', $station);
    $columns = array_keys($rows[0]);
    $columns = array_filter($columns, fn($c) => $c !== 'ts');

    $colList = implode(',', array_merge(['ts'], $columns));
    $placeholders = implode(',', array_fill(0, count($columns) + 1, '?'));
    $sql = "INSERT IGNORE INTO `$table` ($colList) VALUES ($placeholders)";
    $stmt = $pdo->prepare($sql);

    foreach ($rows as $row) {
        $values = array_merge([$row['ts']], array_map(fn($c) => $row[$c] ?? null, $columns));

        try {
            $stmt->execute($values);
        } catch (\PDOException $e) {
            echo "<pre>";
            echo "Insert failed for station $station\n";
            echo "SQL: $sql\n";
            echo "Values: " . print_r($values, true);
            echo "Error: " . $e->getMessage();
            echo "</pre>";
            return;
        }
    }

    echo "Insert complete for station $station. Total: " . count($rows) . " rows\n";
}
}
