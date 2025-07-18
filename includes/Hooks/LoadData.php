<?php

namespace Legenda\NormalSurf\Hooks;

use PDO;
use Legenda\NormalSurf\Repositories\NoaaRepository;

class LoadData
{
    public static function conn_report(string $station = '41112'): array
    {
        require_once dirname(__DIR__, 2) . '/config.php';

        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $table = "station_" . preg_replace('/\D/', '', $station);

        $stmt = $pdo->query("DESCRIBE `$table`");
        $columns = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
        $colsList = implode(',', array_filter($columns, fn($c) => $c !== 'ts'));

        return [$pdo, $station, array_filter($columns, fn($c) => $c !== 'ts'), $colsList, $table];
    }

    public static function insert_data(PDO $pdo, string $station, array $rows): void
    {
        if (empty($rows)) {
            echo "No data to insert for station $station.\n";
            return;
        }

        $table = "station_" . preg_replace('/\D/', '', $station);
        $columns = array_keys($rows[0]);
        $columns = array_unique($columns);

        if (!in_array('ts', $columns)) {
            error_log("No 'ts' field found in rows for station $station");
            return;
        }

        $colList = implode(',', $columns);
        $placeholders = implode(',', array_fill(0, count($columns), '?'));
        $sql = "INSERT IGNORE INTO `$table` ($colList) VALUES ($placeholders)";
        $stmt = $pdo->prepare($sql);

        $inserted = 0;

        foreach ($rows as $row) {
            $values = array_map(fn($c) => $row[$c] ?? null, $columns);
            if (!$stmt->execute($values)) {
                error_log("Insert failed for station $station");
                error_log("SQL: $sql");
                error_log("Values: " . print_r($values, true));
                error_log("Error: " . implode(' | ', $stmt->errorInfo()));
            } else {
                $inserted++;
            }
        }

        echo "Insert complete for station $station. Total inserted: $inserted of " . count($rows) . " rows\n";
    }
}
