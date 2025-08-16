<?php
// scripts/import_tides.php (CLI or browser)
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/config.php';

use Legenda\NormalSurf\Hooks\LoadData;

$pdo = new PDO(
    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$xmlPath = dirname(__DIR__, 2) . '/assets/xml_data/8720030_annual.xml';
$table = LoadData::import_tides_from_xml($pdo, $xmlPath);

echo "Imported tides into table: {$table}\n";
