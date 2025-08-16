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
function h($v): string
{
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// ————— Compute absolute midpoint —————
$station_columns = [
  'ts',
  'WVHT',
  'SwH',
  'SwP',
  'WWH',
  'WWP',
  'SwD',
  'WWD',
  'APD',
  'MWD',
  'STEEPNESS'
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
  '41112'   => ['label' => 'St. Marys Entrance', 'data' => $data1],
  'median'  => ['label' => 'St. Johns Approach (interpolated)',   'data' => $absolute_mid],
  '41117'   => ['label' => 'St. Augustine',         'data' => $data2],
];




// inject into each station row
$report = new Report();
foreach ($station_rows as $key => $row) {
  $station_rows[$key]['dominant_period'] =
    $report->computeDominantPeriod($row['data']);
}

$table  = 'tides_8720030'; // make sure this matches the station you're comparing
$nowUtc = Convert::UTC_time(); // already UTC "Y-m-d H:i:00"

$stmt = $pdo->prepare("
  SELECT t_local, t_utc, height_ft
  FROM `{$table}`
  WHERE hl_type = 'H' AND t_utc >= :now
  ORDER BY t_utc ASC
  LIMIT 1
");
$stmt->execute([':now' => $nowUtc]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if ($row) {
    // 1) Render LOCAL time without any re-conversion:
    $dtLocal = DateTime::createFromFormat(
        'Y-m-d H:i:s',
        $row['t_local'],
        new DateTimeZone('America/New_York')
    );
    echo 'Next High: ' .
         $dtLocal->format('l, F j, g:i A') .
         ' (' . round((float)$row['height_ft'], 2) . ' ft MLLW)';

    // Optional sanity: verify offset is exactly -4 in summer
    // echo ' [offset hrs: ' . ((strtotime($row['t_local']) - strtotime($row['t_utc']))/3600) . ']';
} else {
    echo 'No upcoming High tide found.';
}

// ————— Find matching spots —————
$waveData      = new WaveData();
$report        = new Report();
$matchingSpots = $report->station_interpolation(
  $pdo,
  $data1,
  $data2,
  $waveData
);
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <title>Normal Surf</title>
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

    .station-report {
      display: flex;
      gap: 20px;
      /* if you want them the same width: */
      /* justify-content: space-between; */
    }

    .station-report__item {
      flex: 1;
      padding: 10px;
      border: 1px solid #ccc;
      border-radius: 4px;
      text-align: center;
    }
    footer{
      width:100%;
      height:400px;
      position:relative;
      bottom:0;
    }
  </style>
</head>

<body>
<header><h1>Normal Surf</h1></header>
<hr>
  <section aria-labelledby="surf-report-heading">
    <h2 id="surf-report-heading">Surf Report For North East Florida </h2>
    <div class="station-report">
      <?php foreach ($station_rows as $key => $row): ?>
        <div class="station-report__item">
          <h4><?= h($row['label']) ?></h4>
          <h3>
            <?php if (is_numeric($row['data']['WVHT'])): ?>
              <?= round(Convert::metersToFeet((float)$row['data']['WVHT']), 2) ?>'
            <?php else: ?>
              &mdash; ft
            <?php endif; ?>

            @

            <?php if (isset($row['dominant_period']) && is_numeric($row['dominant_period'])): ?>
              <?= round((float)$row['dominant_period'], 1) ?>s
            <?php else: ?>
              &mdash; s
            <?php endif; ?>

            &
            <?php if (is_numeric($row['data']['MWD'])): ?>
              <?= round((float)$row['data']['MWD'], 0) ?>&deg;
            <?php else: ?>
              &mdash; &deg;
            <?php endif; ?>
          </h3>
        </div>
      <?php endforeach; ?>
    </div>
    <hr>
  </section>

  <section aria-labelledby="ideal-spots-heading">
    <h2 id="ideal-spots-heading"> List Of Optimal Spots: </h2>
    <ul>
      <?php if (empty($matchingSpots)): ?>
        <li>Observed conditions are less than ideal for your region. Check back later.</li>
        
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
  <p>
    The spots list is derived from zones indicating significant wave concentration; from St. Mary’s Entrance to 13th Ave. S., Jacksonville Beach. Each zone is adjusted for refraction across various swell directions and periods over prominent bathymetry. Continued mapping southward will follow in the near future, although initial tests show few high-energy zones between South Jacksonville Beach and the Vilano shoals at the southern end of "South Ponte Vedra".
  </p>
  <p>
    Future versions will integrate tide and wind data into the spot-selection model, along with forecasting and other features that support local intuition.
  </p>
<footer>
  <hr>
<strong>Patent Pending</strong>
<p>© 2023 – 2025 Legenda LLC</p>   </footer>
</body>

</html>