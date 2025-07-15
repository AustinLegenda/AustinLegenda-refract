<?php
// index.php
error_reporting(E_ALL);
ini_set('display_errors', '1');
require_once __DIR__ . '/vendor/autoload.php';

use Legenda\NormalSurf\Hooks\Convert;
use Legenda\NormalSurf\Hooks\LoadData;

// 1) Load the NOAA wave data into the DB
[$pdo, $station, $dataCols] = LoadData::conn_report();

// 4) LOAD LATEST 50 ROWS
$colsList = implode(',', $dataCols);
$stmtLatest = $pdo->query("SELECT ts, {$colsList} FROM wave_data ORDER BY ts DESC LIMIT 50");
$latest = $stmtLatest->fetchAll(PDO::FETCH_ASSOC);

// 5) FIND MOST RECENT READING CLOSEST TO USER TIME
$targetTs = Convert::UTC_time();
$stmt = $pdo->prepare("
    SELECT ts, {$colsList}
    FROM wave_data
    WHERE ts <= ?
    ORDER BY ts DESC
    LIMIT 1
");
$stmt->execute([$targetTs]);
$closest = $stmt->fetch(PDO::FETCH_ASSOC);

// 6) COMPUTE SURF PERIOD
$surfPeriod = null;
if ($closest) {
    $swH = $closest['SwH'] ?? 0;
    $swP = $closest['SwP'] ?? 0;
    $wwH = $closest['WWH'] ?? 0;
    $wwP = $closest['WWP'] ?? 0;

    $E_sw = ($swH * $swH) * $swP;
    $E_ww = ($wwH * $wwH) * $wwP;

    $surfPeriod = ($E_sw >= $E_ww) ? $swP : $wwP;
}

// 7) MATCH SURF SPOTS BY MWD ANGLE
$matchingSpots = [];
if ($closest && isset($closest['MWD'])) {
    $mwd = (int)$closest['MWD'];
    $stmtSpots = $pdo->prepare("
        SELECT id, spot_name, spot_angle, ABS(spot_angle - ?) AS distance
        FROM surf_spots
        ORDER BY distance ASC
    ");
    $stmtSpots->execute([$mwd]);
    $matchingSpots = $stmtSpots->fetchAll(PDO::FETCH_ASSOC);
}

// HTML ESCAPE
function h($v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Spectral Data & Surf Spots</title>
  <style>
    body { font-family: sans-serif; margin:20px; }
    table { border-collapse: collapse; width:100%; margin-bottom:30px; }
    th, td { padding:6px 10px; border:1px solid #ccc; text-align:center; }
    th { background:#eee; }
    h1,h2 { margin-bottom:10px; }
  </style>
</head>
<body>
  <h1>Station <?= h($station) ?> — Latest 50 Spectral Rows</h1>
  <table>
    <thead>
      <tr>
        <th>Timestamp (UTC)</th>
        <?php foreach ($dataCols as $c): ?>
          <th><?= h($c) ?></th>
        <?php endforeach ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($latest as $row): ?>
        <tr>
          <td><?= h($row['ts']) ?></td>
          <?php foreach ($dataCols as $c): ?>
            <td><?= h($row[$c]) ?></td>
          <?php endforeach ?>
        </tr>
      <?php endforeach ?>
    </tbody>
  </table>

  <h2>
    Row Closest to Your Time (<?= h($targetTs) ?> UTC)
    <?php if ($surfPeriod !== null): ?>
      — <em>Surf Period:</em> <?= h(number_format($surfPeriod,1)) ?> s
    <?php endif ?>
  </h2>
  <table>
    <thead>
      <tr>
        <th>Timestamp (UTC)</th>
        <?php foreach ($dataCols as $c): ?>
          <th><?= h($c) ?></th>
        <?php endforeach ?>
      </tr>
    </thead>
    <tbody>
      <?php if ($closest): ?>
        <tr>
          <td><?= h($closest['ts']) ?></td>
          <?php foreach ($dataCols as $c): ?>
            <td><?= h($closest[$c]) ?></td>
          <?php endforeach ?>
        </tr>
      <?php else: ?>
        <tr>
          <td colspan="<?= count($dataCols) + 1 ?>">No data found</td>
        </tr>
      <?php endif ?>
    </tbody>
  </table>

  <h2>Surf Spots by Angle Difference (MWD = <?= h($closest['MWD'] ?? 'n/a') ?>°)</h2>
  <ul>
    <?php foreach ($matchingSpots as $s): ?>
      <li>
        <?= h($s['spot_name']) ?>
        (angle: <?= h($s['spot_angle']) ?>°, Δ=<?= h($s['distance']) ?>°)
      </li>
    <?php endforeach ?>
  </ul>
</body>
</html>
