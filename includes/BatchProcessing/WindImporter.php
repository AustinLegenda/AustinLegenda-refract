<?php
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/config.php';

use Legenda\NormalSurf\Repositories\WindRepo;

$pdo = new PDO(
  "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
  DB_USER, DB_PASS,
  [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

// --- Refresh CO-OPS stations ---
$coopsStations = ['8720030','8720218'];
$countsCoOps = WindRepo::refreshMany($pdo, $coopsStations, 'coops');

// --- Refresh NDBC stations ---
$ndbcStations = ['SAUF1'];
$countsNDBC = WindRepo::refreshMany($pdo, $ndbcStations, 'ndbc');

// Merge counts for reporting
$counts = array_merge($countsCoOps, $countsNDBC);
var_dump($counts);

// --- Verify ---
foreach (array_merge($coopsStations, $ndbcStations) as $c) {
    $tbl = WindRepo::table($c);
    $n = $pdo->query("SELECT COUNT(*) AS n FROM `{$tbl}`")->fetch()['n'];
    echo $tbl." rows: ".$n.PHP_EOL;
    print_r($pdo->query("SELECT * FROM `{$tbl}` ORDER BY ts DESC LIMIT 3")->fetchAll());
}
