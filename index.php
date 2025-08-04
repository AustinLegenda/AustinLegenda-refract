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

function h($v): string
{
  return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

$waveData = new WaveData();
$report = new Report();
$matchingSpots = $report->station_interpolation($pdo, $data1, $data2, $waveData);

// Compute weighted midpoint using first spot’s distances as reference

$distances = $matchingSpots[0];
$midpoint_row = $report->interpolate_midpoint_row($data1, $data2, $distances);

$station_rows = [
  '41112' => $data1,
  'Weighted Midpoint' => $midpoint_row,
  '41117' => $data2,
  
];

$station_columns = ['ts', 'WVHT', 'SwH', 'SwP', 'WWH', 'WWP', 'SwD', 'WWD', 'APD', 'MWD', 'STEEPNESS'];
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <style>
    body {
      font-family: sans-serif;
      margin: 20px;
    }

    table {
      border-collapse: collapse;
      width: 100%;
      margin-bottom: 30px;
    }

    th,
    td {
      padding: 6px 10px;
      border: 1px solid #ccc;
      text-align: center;
    }

    th {
      background: #eee;
    }

    h1,
    h2 {
      margin-bottom: 10px;
    }
  </style>
</head>

<body>
  <h2>Latest Observations</h2>
  <table>
    <thead>
      <tr>
        <th>Station</th>
        <?php foreach ($station_columns as $col): ?>
          <th><?= h($col) ?></th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($station_rows as $station => $data): ?>
        <tr>
          <td><?= h($station) ?></td>
          <?php foreach ($station_columns as $col): ?>
            <td>
              <?= is_numeric($data[$col] ?? null) ? round($data[$col], 2) : h($data[$col] ?? '—') ?>
            </td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <h2>Interpolated Spot Report</h2>
  <p>Using station data from <?= h($station1) ?> and <?= h($station2) ?> at <?= h($data1['ts']) ?> UTC</p>

  <h3>Ideal Spots Based on Dominate Period and Median Direction</h3>
<ul>
  <?php if (empty($matchingSpots)): ?>
    <li>No spots match your criteria.</li>
  <?php else: ?>
    <?php foreach ($matchingSpots as $s): ?>
      <li>
        <?= h($s['spot_name']) ?>
        (Period: <?= h($s['dominant_period']) ?>s,
         Dir: <?= h($s['interpolated_mwd']) ?>&deg;)
      </li>
    <?php endforeach; ?>
  <?php endif; ?>
</ul></body>

</html>
