<?php

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/config.php';

use Legenda\NormalSurf\Hooks\LoadData;

$pdo = new PDO(
    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// import all JSONs in the directory
$dir = dirname(__DIR__, 2) . '/data/wave-forecast';
$results = LoadData::import_waves_from_dir($pdo, $dir);

foreach ($results as $r) {
    echo "Imported {$r['file']} → {$r['table']}\n";
}
