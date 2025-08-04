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

// 1) First, declare your columns:
$station_columns = ['ts','WVHT','SwH','SwP','WWH','WWP','SwD','WWD','APD','MWD','STEEPNESS'];

// 2) Always start with the two real buoys:


// 3a) Compute the _absolute_ midpoint (50/50 mean):
$absolute_mid = [];
foreach ($station_columns as $col) {
  $v1 = $data1[$col] ?? null;
  $v2 = $data2[$col] ?? null;
  $absolute_mid[$col] = (is_numeric($v1) && is_numeric($v2))
    ? ($v1 + $v2) / 2
    : null;
}
$station_rows = [
  '41112' => $data1,
 'Interpolated Median' => $absolute_mid,
  '41117'  => $data2,
];

function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $R = 6371000; // Earth radius in meters
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2) * sin($dLat/2)
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
       * sin($dLon/2) * sin($dLon/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $R * $c;
}

// 2) Define your two buoy coords here:
$stationCoords = [
  '41112' => ['lat' => 33.450, 'lon' => -81.900],  // ← replace with real lat/lon
  '41117' => ['lat' => 30.300, 'lon' => -81.500],  // ← replace with real lat/lon
];

// 3) Fetch every spot:
$allSpots = $pdo
  ->query('SELECT spot_name, spot_lat, spot_lon FROM surf_spots')
  ->fetchAll(PDO::FETCH_ASSOC);

// 4) Loop & echo:
echo '<h3>All Region Interpolations</h3><ul>';
foreach ($allSpots as $spot) {
    // compute distances (in meters)
    $d1 = haversine(
      $stationCoords['41112']['lat'],
      $stationCoords['41112']['lon'],
      $spot['spot_lat'],
      $spot['spot_lon']
    );
    $d2 = haversine(
      $stationCoords['41117']['lat'],
      $stationCoords['41117']['lon'],
      $spot['spot_lat'],
      $spot['spot_lon']
    );
    // interpolate your “virtual buoy”
    $interp = $report->interpolate_midpoint_row(
      $data1, 
      $data2, 
      ['distance1' => $d1, 'distance2' => $d2]
    );
    // round & echo the MWD
    $mwd = isset($interp['MWD']) ? round($interp['MWD'], 2) : '—';
    echo '<li>' . h($spot['spot_name'])
       . ' — Interpolated MWD: ' . $mwd . '&deg;</li>';
}
echo '</ul>';

/*// spot‐weighted midpoint:
if (! empty($matchingSpots)) {
  $distances    = $matchingSpots[0];
  $spot_weighted = $report->interpolate_midpoint_row($data1, $data2, $distances);
  $station_rows['Spot-Weighted Midpoint'] = $spot_weighted;
}*/
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

  <h2>Interpolated Surf Report</h2>
  <h5>Using station data from <?= h($station1) ?> and <?= h($station2) ?> at <?= h($data1['ts']) ?> UTC</h5>
<h3>WVHT @ (dominate period) & (MWD)º</h3>
  <h3>Ideal Spots Based on Dominate Period and Median Direction</h3>
  <h5>Adjusted For Refraction</h5>
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
