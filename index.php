<?php
// index.php
error_reporting(E_ALL);
ini_set('display_errors', '1');
require_once __DIR__ . '/vendor/autoload.php';

use Legenda\NormalSurf\Hooks\Convert;
use Legenda\NormalSurf\Hooks\LoadData;
use Legenda\NormalSurf\Hooks\WaveData;
use Legenda\NormalSurf\Hooks\Report;

//forecast helpers
function firstNumeric(array $row, array $candidates): ?float
{
  foreach ($candidates as $k) {
    if (array_key_exists($k, $row) && is_numeric($row[$k])) {
      return (float)$row[$k];
    }
  }
  return null;
}
function fmtFt(?float $meters): string
{
  return is_null($meters) ? '—' : round(\Legenda\NormalSurf\Hooks\Convert::metersToFeet($meters), 2) . "'";
}
function fmtSec(?float $seconds): string
{
  return is_null($seconds) ? '—' : round($seconds, 1) . 's';
}
function fmtDeg(?float $deg): string
{
  return is_null($deg) ? '—' : round($deg) . '°';
}

// ————— Load the two buoys —————
[$pdo, $station1, $cols1, $colsList1, $table1] = LoadData::conn_report('41112');
[$_,      $station2, $cols2, $colsList2, $table2] = LoadData::conn_report('41117');

// ————— Load Forecast —————
$waveJsonDir = __DIR__ . '/data/wave-forecast';
LoadData::import_waves_from_dir($pdo, $waveJsonDir);

$targetTs = Convert::UTC_time();

$stmt1 = $pdo->prepare("SELECT ts, {$colsList1} FROM {$table1} WHERE ts <= ? ORDER BY ts DESC LIMIT 1");
$stmt1->execute([$targetTs]);
$data1 = $stmt1->fetch(PDO::FETCH_ASSOC);

$stmt2 = $pdo->prepare("SELECT ts, {$colsList2} FROM {$table2} WHERE ts <= ? ORDER BY ts DESC LIMIT 1");
$stmt2->execute([$targetTs]);
$data2 = $stmt2->fetch(PDO::FETCH_ASSOC);

if (!$data1 || !$data2) {
  die('Missing data for one or both buoys.');
}

function h($v): string
{
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

//test forecast load

// ————— Compute absolute midpoint —————
$station_columns = ['ts', 'WVHT', 'SwH', 'SwP', 'WWH', 'WWP', 'SwD', 'WWD', 'APD', 'MWD', 'STEEPNESS'];

$absolute_mid = [];
foreach ($station_columns as $col) {
  $v1 = $data1[$col] ?? null;
  $v2 = $data2[$col] ?? null;
  $absolute_mid[$col] = (is_numeric($v1) && is_numeric($v2)) ? ($v1 + $v2) / 2 : null;
}

$station_rows = [
  '41112'   => ['label' => 'St. Marys Entrance',                'data' => $data1],
  'median'  => ['label' => 'St. Johns Approach (interpolated)', 'data' => $absolute_mid],
  '41117'   => ['label' => 'St. Augustine',                      'data' => $data2],
];
//forecast rows
$nowUtc = Convert::UTC_time();
$fcst41112 = LoadData::waves_next($pdo, '41112', $nowUtc, 120); // next 8 rows
$fcst41117 = LoadData::waves_next($pdo, '41117', $nowUtc, 120);


$report = new Report();
foreach ($station_rows as $key => $row) {
  $station_rows[$key]['dominant_period'] = $report->computeDominantPeriod($row['data']);
}

// ————— Find matching spots —————
$waveData      = new WaveData();
$report        = new Report();
$matchingSpots = $report->station_interpolation($pdo, $data1, $data2, $waveData);

// ——— Lists ———
$list1 = []; // ideal: dir+period ok AND tide match within 60 min (has prefs)
$list2 = []; // optional: dir+period ok but (no prefs OR not in window)
$list3 = []; // high energy override: WVHT > 0.56 m regardless of tide

// Use blended WVHT from absolute midpoint as proxy for energy
$wvhtBlend = is_numeric($absolute_mid['WVHT'] ?? null) ? (float)$absolute_mid['WVHT'] : 0.0;
$WVHT_THRESHOLD = 0.56; // meters (~1.8 ft)

foreach ($matchingSpots as $s) {
  // ————— Build the full parenthetical for every spot —————
  $wvhtFt = (isset($s['WVHT']) && is_numeric($s['WVHT']))
    ? round(Convert::metersToFeet((float)$s['WVHT']), 2) . "'"
    : "— ft";

  $period = isset($s['dominant_period'])  ? "Period: {$s['dominant_period']} s" : null;
  $dir    = isset($s['interpolated_mwd']) ? "Dir: " . round($s['interpolated_mwd'], 0) . "°" : null;

  $tide = null;
  if (!empty($s['tide_reason']) && !empty($s['tide_reason_time'])) {
    $tide = "Tide: {$s['tide_reason']} @ {$s['tide_reason_time']}";
  } elseif (!empty($s['next_pref']) && !empty($s['next_pref_time'])) {
    $tide = "Tide: {$s['next_pref']} @ {$s['next_pref_time']}";
  } elseif (!empty($s['tide_note'])) {
    $tide = "Tide: {$s['tide_note']}";
  }

  $parts = array_filter([$period, $dir, $tide]);
  // WVHT always first
  $s['parenthetical'] = '(' . $wvhtFt . (count($parts) ? ', ' . implode(', ', $parts) : '') . ')';

  // ————— Bucket logic (use per-spot weighted WVHT) —————
  $WVHT_UNDER_THRESHOLD = 0.6; // meters
  $spotWvht = (isset($s['WVHT']) && is_numeric($s['WVHT'])) ? (float)$s['WVHT'] : 0.0;

  if ($spotWvht < $WVHT_UNDER_THRESHOLD) {
    // Sub-threshold surf (exclusive)
    $list3[] = $s;
  } elseif (!empty($s['has_tide_prefs']) && !empty($s['tide_ok'])) {
    // Ideal: prefs exist AND within 60-min window
    $list1[] = $s;
  } else {
    // Otherwise, optional
    $list2[] = $s;
  }
}
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

    .station-report {
      display: flex;
      gap: 20px;
    }

    .station-report__item {
      flex: 1;
      padding: 10px;
      border: 1px solid #ccc;
      border-radius: 4px;
      text-align: center;
    }

    .muted {
      color: #666;
    }

    .badge {
      display: inline-block;
      padding: 0 .4rem;
      margin-left: .35rem;
      border-radius: .3rem;
      background: #eef;
      color: #223;
      font-size: 12px;
    }
  </style>
</head>

<body>
  <header>
    <h1>Normal Surf</h1>
  </header>
  <hr>

  <section aria-labelledby="surf-report-heading">
    <h2 id="surf-report-heading">Surf Report For North East Florida </h2>
    <div class="station-report">
      <?php foreach ($station_rows as $row): ?>
        <div class="station-report__item">
          <h4><?= h($row['label']) ?></h4>
          <h3>
            <?= is_numeric($row['data']['WVHT']) ? round(Convert::metersToFeet((float)$row['data']['WVHT']), 2) . "'" : "&mdash; ft" ?>
            @
            <?= isset($row['dominant_period']) && is_numeric($row['dominant_period']) ? round((float)$row['dominant_period'], 1) . "s" : "&mdash; s" ?>
            &
            <?= is_numeric($row['data']['MWD']) ? round((float)$row['data']['MWD'], 0) . "&deg;" : "&mdash; &deg;" ?>
          </h3>
        </div>
      <?php endforeach; ?>
    </div>
    <hr>
  </section>

  <section aria-labelledby="forecast-heading">
    <h2 id="forecast-heading">GFS-Wave Forecast (Next 8)</h2>

    <div class="station-report">
      <!-- 41112 -->
      <div class="station-report__item">
        <h4>St. Marys Entrance (41112)</h4>
        <table>
          <thead>
            <tr>
              <th>Local Time</th>
              <th>Hs</th>
              <th>Per</th>
              <th>Dir</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($fcst41112 as $r): ?>
              <?php
              // common column aliases used by importers / WW3 mappings
              $hs_m = firstNumeric($r, ['Hs_m', 'hs_m', 'Hs', 'SWH', 'HTSGW']);
              $per  = firstNumeric($r, ['Per_s', 'per_s', 'Per', 'Tm', 'MWP']);
              $dir  = firstNumeric($r, ['Dir_deg', 'dir_deg', 'Dir', 'MWD', 'DIRPW']);

              $tLocal = $r['t_local'] ?? null;
              ?>
              <tr>
                <td>
                  <?= !empty($r['t_utc'])
                    ? h(Convert::toLocalTime($r['t_utc'], 'America/New_York'))
                    : (!empty($r['t_local']) ? h($r['t_local']) : '—') ?>
                </td>
                <td><?= h(fmtFt($hs_m)) ?></td>
                <td><?= h(fmtSec($per)) ?></td>
                <td><?= h(fmtDeg($dir)) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($fcst41112)): ?>
              <tr>
                <td colspan="4" class="muted">No forecast rows found.</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- 41117 -->
      <div class="station-report__item">
        <h4>St. Augustine (41117)</h4>
        <table>
          <thead>
            <tr>
              <th>Local Time</th>
              <th>Hs</th>
              <th>Per</th>
              <th>Dir</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($fcst41117 as $r): ?>
              <?php
              // common column aliases used by importers / WW3 mappings
              $hs_m = firstNumeric($r, ['Hs_m', 'hs_m', 'Hs', 'SWH', 'HTSGW']);
              $per  = firstNumeric($r, ['Per_s', 'per_s', 'Per', 'Tm', 'MWP']);
              $dir  = firstNumeric($r, ['Dir_deg', 'dir_deg', 'Dir', 'MWD', 'DIRPW']);

              $tLocal = $r['t_local'] ?? null;
              ?>
              <tr>
                <td>
                  <?= !empty($r['t_utc'])
                    ? h(Convert::toLocalTime($r['t_utc'], 'America/New_York'))
                    : (!empty($r['t_local']) ? h($r['t_local']) : '—') ?>
                </td>
                <td><?= h(fmtFt($hs_m)) ?></td>
                <td><?= h(fmtSec($per)) ?></td>
                <td><?= h(fmtDeg($dir)) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($fcst41112)): ?>
              <tr>
                <td colspan="4" class="muted">No forecast rows found.</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      </table>
    </div>
    </div>
    <hr>
  </section>


  <section aria-labelledby="spot-list">
    <h2>List 1 <span class="badge">ideal</span></h2>
    <ul>
      <?php if (empty($list1)): ?>
        <li class="muted">No ideal spots right now.</li>
      <?php else: ?>
        <?php foreach ($list1 as $row): ?>
          <li><?= h($row['spot_name']) ?> <span class="muted"><?= h($row['parenthetical']) ?></span></li>
        <?php endforeach; ?>
      <?php endif; ?>
    </ul>

    <h2>List 2 <span class="badge">optional</span></h2>
    <ul>
      <?php if (empty($list2)): ?>
        <li class="muted">No optional spots right now.</li>
      <?php else: ?>
        <?php foreach ($list2 as $row): ?>
          <li><?= h($row['spot_name']) ?> <span class="muted"><?= h($row['parenthetical']) ?></span></li>
        <?php endforeach; ?>
      <?php endif; ?>
    </ul>

    <h2>List 3 <span class="badge">sub-threshold (WVHT &lt; 0.56 m)</span></h2>
    <ul>
      <?php if (empty($list3)): ?>
        <li class="muted">No high energy spots right now.</li>
      <?php else: ?>
        <?php foreach ($list3 as $row): ?>
          <li><?= h($row['spot_name']) ?> <span class="muted"><?= h($row['parenthetical']) ?></span></li>
        <?php endforeach; ?>
      <?php endif; ?>
    </ul>
  </section>

  <section>
    <h2>Latest Observations</h2>
    <table>
      <thead>
        <tr>
          <th>Station</th>
          <?php foreach ($station_columns as $col): ?><th><?= h($col) ?></th><?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($station_rows as $station => $info): ?>
          <tr>
            <td><?= h($station) ?></td>
            <?php foreach ($station_columns as $col): ?>
              <td><?= is_numeric($info['data'][$col] ?? null) ? round($info['data'][$col], 2) : h($info['data'][$col] ?? '—') ?></td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>

  <footer>
    <hr>
    <h5>Release Notes:</h5>
    <p>The spots list is derived from zones indicating significant wave concentration ...</p>
    <p>Future versions will integrate tide and wind data ...</p>
    <strong>Patent Pending</strong>
    <p>© 2023 – 2025 Legenda LLC</p>
  </footer>
</body>

</html>