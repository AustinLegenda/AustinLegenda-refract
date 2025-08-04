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
  <h2>Surf Report</h2>
<?php foreach ($station_rows as $label => $row): ?>
  <h4><?= h($label) ?></h4>
  <h3>
    <?= is_numeric($row['WVHT']) ? round($row['WVHT'],2) : '&mdash;' ?>
    @
    <?= is_numeric($row['SwP'])  ? round($row['SwP'],2)  :'&mdash;' ?> s
    &amp;
    <?= is_numeric($row['MWD'])  ? round($row['MWD'],0)  :'&mdash;' ?>&deg;
  </h3>
<?php endforeach; ?>
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
</ul>

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

<h5>Release Notes:</h5>
 <p>List of spot includes zones indicating significant wave concentration from St. Mary's Entrance to 13th Ave. S. in Jacksonville Beach and are adjusted for refraction at various swell directions, periods, and prominant bathematry. Mapping southward of the 13th Ave. S. will ensue in the near future. Though at first glance few indicators of wave concentration exist from South Jacksonville Beach to the Vilano shoals at the southern end of South Ponte Vedra.   </p>
<p>Future verisions will implement tide and wind data, as well as forecasting.</p>
</body>

</html>
