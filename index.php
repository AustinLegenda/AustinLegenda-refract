<?php
// index.php
error_reporting(E_ALL);
ini_set('display_errors', '1');
require_once __DIR__ . '/vendor/autoload.php';

use Legenda\NormalSurf\Hooks\Convert;
use Legenda\NormalSurf\Hooks\LoadData;
use Legenda\NormalSurf\Hooks\WaveData;
use Legenda\NormalSurf\Models\RefractionModel;

// 1. Load NOAA data
[$pdo, $station, $dataCols, $colsList] = LoadData::conn_report();

// 2. Get latest buoy reading
$targetTs = Convert::UTC_time();
$stmt = $pdo->prepare("
    SELECT ts, {$colsList}
    FROM station_41112
    WHERE ts <= ?
    ORDER BY ts DESC
    LIMIT 1
");
$stmt->execute([$targetTs]);
$closest = $stmt->fetch(PDO::FETCH_ASSOC);

// 3. Prepare data
$wvht = (float)($closest['WVHT'] ?? 1.0);
$period = (float)($closest['SwP'] ?? $closest['WWP'] ?? 10);

// 4. HTML escape helper
function h($v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

// 5. Wave + Spot Modeling
$waveData = new WaveData();
$matchingSpots = [];

if ($closest && isset($closest['MWD'])) {
    $mwd = (float)$closest['MWD'];

    $stmtSpots = $pdo->query("SELECT id, spot_name, spot_angle FROM surf_spots");

    while ($spot = $stmtSpots->fetch(PDO::FETCH_ASSOC)) {
        $spotAngle = (float)$spot['spot_angle'];
        $aoiRaw = $waveData->AOI($spotAngle, $mwd);
        $aoiAdjusted = RefractionModel::safeRefractionAOI($aoiRaw, $period, $wvht);

        $spot['aoi'] = $aoiRaw;
        $spot['aoi_adjusted'] = $aoiAdjusted;
        $spot['aoi_category'] = $waveData->AOI_category($aoiAdjusted);
        $spot['longshore'] = $waveData->longshoreRisk($aoiAdjusted);

        $matchingSpots[] = $spot;
    }

    usort($matchingSpots, fn($a, $b) => $a['aoi_adjusted'] <=> $b['aoi_adjusted']);
}
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

<h2>Current Observations</h2>
<h3>
  Station: <?= h($station) ?>, <?= h($closest['ts']) ?> (UTC)
</h3>

<table>
  <thead>
    <tr>
      <th>Timestamp</th>
      <?php foreach ($dataCols as $c): ?>
        <th><?= h($c) ?></th>
      <?php endforeach ?>
    </tr>
  </thead>
  <tbody>
    <?php if ($closest): ?>
      <tr>
        <td><?= h(Convert::toLocalTime($closest['ts'])) ?></td>
        <?php foreach ($dataCols as $c): ?>
          <td>
            <?php if (in_array($c, ['WVHT', 'SwH', 'WWH'], true)): ?>
              <?= h(Convert::metersToFeet((float)$closest[$c])) ?>
            <?php else: ?>
              <?= h($closest[$c]) ?>
            <?php endif ?>
          </td>
        <?php endforeach ?>
      </tr>
    <?php else: ?>
      <tr>
        <td colspan="<?= count($dataCols) + 1 ?>">No data found</td>
      </tr>
    <?php endif ?>
  </tbody>
</table>

<h2>Spots by Adjusted Angle of Incidence (Refraction Applied)</h2>
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
