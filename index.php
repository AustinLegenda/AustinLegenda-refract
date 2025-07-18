<?php
// index.php
error_reporting(E_ALL);
ini_set('display_errors', '1');
require_once __DIR__ . '/vendor/autoload.php';

use Legenda\NormalSurf\Hooks\Convert;
use Legenda\NormalSurf\Hooks\LoadData;
use Legenda\NormalSurf\Hooks\WaveData;
use Legenda\NormalSurf\Models\RefractionModel;
use Legenda\NormalSurf\Hooks\Report;

use Legenda\NormalSurf\API\NoaaRequest;


[$pdo, $station1, $cols1, $colsList1, $table1] = LoadData::conn_report('41112');
[$_, $station2, $cols2, $colsList2, $table2] = LoadData::conn_report('41117');

$targetTs = Convert::UTC_time();

// Buoy 41112
$stmt1 = $pdo->prepare("SELECT ts, {$colsList1} FROM {$table1} WHERE ts <= ? ORDER BY ts DESC LIMIT 1");
$stmt1->execute([$targetTs]);
$data1 = $stmt1->fetch(PDO::FETCH_ASSOC);

// Buoy 41117
$stmt2 = $pdo->prepare("SELECT ts, {$colsList2} FROM {$table2} WHERE ts <= ? ORDER BY ts DESC LIMIT 1");
$stmt2->execute([$targetTs]);
$data2 = $stmt2->fetch(PDO::FETCH_ASSOC);

if (!$data1 || !$data2) {
  die('Missing data for one or both buoys.');
}

function h($v): string {
  return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

$waveData = new WaveData();
$report = new Report();
$matchingSpots = $report->station_interpolation($pdo, $data1, $data2, $waveData);

usort($matchingSpots, fn($a, $b) => $a['aoi_adjusted'] <=> $b['aoi_adjusted']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <style>
    body { font-family: sans-serif; margin: 20px; }
    table { border-collapse: collapse; width: 100%; margin-bottom: 30px; }
    th, td { padding: 6px 10px; border: 1px solid #ccc; text-align: center; }
    th { background: #eee; }
    h1, h2 { margin-bottom: 10px; }
  </style>
</head>
<body>
<h2>Interpolated Spot Report</h2>
<p>Using station data from <?= h($station1) ?> and <?= h($station2) ?> at <?= h($data1['ts']) ?> UTC</p>

<h3>Spots by Adjusted Angle of Incidence (Refraction Applied)</h3>
<ul>
  <?php foreach ($matchingSpots as $s): ?>
    <li>
      <?= h($s['spot_name']) ?>  
      (AOI: <?= h(round($s['aoi_adjusted'])) ?>°, 
      Category: <?= h($s['aoi_category']) ?>, 
      Longshore: <?= h($s['longshore']) ?>)
    </li>
  <?php endforeach ?>
</ul>

</body>
</html>
