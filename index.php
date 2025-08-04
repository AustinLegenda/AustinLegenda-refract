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

// ————— Load the two buoys —————
[$pdo, $station1, $cols1, $colsList1, $table1] = LoadData::conn_report('41112');
[$_,      $station2, $cols2, $colsList2, $table2] = LoadData::conn_report('41117');

$targetTs = Convert::UTC_time();

$stmt1 = $pdo->prepare(
    "SELECT ts, {$colsList1}
     FROM {$table1}
     WHERE ts <= ?
     ORDER BY ts DESC
     LIMIT 1"
);
$stmt1->execute([$targetTs]);
$data1 = $stmt1->fetch(PDO::FETCH_ASSOC);

$stmt2 = $pdo->prepare(
    "SELECT ts, {$colsList2}
     FROM {$table2}
     WHERE ts <= ?
     ORDER BY ts DESC
     LIMIT 1"
);
$stmt2->execute([$targetTs]);
$data2 = $stmt2->fetch(PDO::FETCH_ASSOC);

if (!$data1 || !$data2) {
    die('Missing data for one or both buoys.');
}

// simple HTML-escape helper
function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// ————— Compute absolute midpoint —————
$station_columns = [
    'ts','WVHT','SwH','SwP','WWH','WWP','SwD','WWD','APD','MWD','STEEPNESS'
];

$absolute_mid = [];
foreach ($station_columns as $col) {
    $v1 = $data1[$col] ?? null;
    $v2 = $data2[$col] ?? null;
    $absolute_mid[$col] = (is_numeric($v1) && is_numeric($v2))
        ? ($v1 + $v2) / 2
        : null;
}

// ————— Assemble rows: ID ⇒ [ label + data ] —————
$station_rows = [
    '41112'   => [ 'label' => 'St. Marys Entrance', 'data' => $data1 ],
    'median'  => [ 'label' => 'St. Johns Entrance (interpolated)',   'data' => $absolute_mid ],
    '41117'   => [ 'label' => 'St. Augustine',         'data' => $data2 ],
];

// ————— Find matching spots —————
$waveData      = new WaveData();
$report        = new Report();
$matchingSpots = $report->station_interpolation(
    $pdo, $data1, $data2, $waveData
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Normal Surf</title>
  <style>
    body { font-family: sans-serif; margin: 20px; }
    table { border-collapse: collapse; width: 100%; margin-bottom: 30px; }
    th, td { padding: 6px 10px; border: 1px solid #ccc; text-align: center; }
    th { background: #eee; }
    h1,h2 { margin-bottom: 10px; }
  </style>
</head>
<body>

  <section aria-labelledby="surf-report-heading">
    <h2 id="surf-report-heading">Surf Report</h2>
    <?php foreach ($station_rows as $key => $row): ?>
      <h4><?= h($row['label']) ?></h4>
      <h3>
        <?php if (is_numeric($row['data']['WVHT'])): ?>
          <?= round(Convert::metersToFeet((float)$row['data']['WVHT']), 2) ?>&nbsp;ft
        <?php else: ?>
          &mdash; ft
        <?php endif; ?>
         @
        <?php if (is_numeric($row['data']['SwP'])): ?>
          <?= round((float)$row['data']['SwP'], 2) ?>&nbsp;s
        <?php else: ?>
          &mdash; s
        <?php endif; ?>
         &amp;
        <?php if (is_numeric($row['data']['MWD'])): ?>
          <?= round((float)$row['data']['MWD'], 0) ?>&deg;
        <?php else: ?>
          &mdash; &deg;
        <?php endif; ?>
      </h3>
    <?php endforeach; ?>
  </section>

  <section aria-labelledby="ideal-spots-heading">
    <h2 id="ideal-spots-heading">Ideal Spots Based on Dominant Period &amp; Median Direction</h2>
    <ul>
      <?php if (empty($matchingSpots)): ?>
        <li>No spots match your criteria.</li>
      <?php else: ?>
        <?php foreach ($matchingSpots as $s): ?>
          <li>
            <?= h($s['spot_name']) ?>
            (Period: <?= h($s['dominant_period']) ?>&nbsp;s,
             Dir: <?= h($s['interpolated_mwd']) ?>&deg;)
          </li>
        <?php endforeach; ?>
      <?php endif; ?>
    </ul>
  </section>

  <section aria-labelledby="latest-observations-heading">
    <h2 id="latest-observations-heading">Latest Observations</h2>
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
        <?php foreach ($station_rows as $station => $info): ?>
          <tr>
            <td><?= h($station) ?></td>
            <?php foreach ($station_columns as $col): ?>
              <td>
                <?php
                  $val = $info['data'][$col] ?? null;
                  echo is_numeric($val) ? round($val, 2) : h($val ?? '—');
                ?>
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>

  <h5>Release Notes:</h5>
  <p>List of spots includes zones indicating significant wave concentration from St. Mary's Entrance to 13th Ave. S in Jacksonville Beach and are adjusted for refraction at various swell directions, periods, and prominent bathymetry. Mapping southward of the 13th Ave. S will ensue in the near future. Though at first glance few indicators of wave concentration exist from South Jacksonville Beach to the Vilano shoals at the southern end of Ponte Vedra.</p>
  <p>Future versions will implement tide and wind data, as well as forecasting.</p>

</body>
</html>
