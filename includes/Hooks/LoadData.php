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

public static function insert_data(PDO $pdo, string $station, array $parsedData): void
{
    $table = "station_" . preg_replace('/\D/', '', $station);
    $columns = array_keys($parsedData[0]);
    $placeholders = implode(',', array_fill(0, count($columns), '?'));
    $colList = implode(',', $columns);

    $stmt = $pdo->prepare("
        INSERT IGNORE INTO `$table` ($colList)
        VALUES ($placeholders)
    ");

    foreach ($parsedData as $row) {
        $values = array_map(fn($v) => $v === '' ? null : $v, array_values($row));
        $stmt->execute($values);
    }
}

}
