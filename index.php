<?php
// index.php
error_reporting(E_ALL);
ini_set('display_errors', '1');

// ----- CONFIGURE YOUR DATABASE HERE -----
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database');
define('DB_USER', 'your_username');
define('DB_PASS', 'your_password');
// -----------------------------------------

// 1) FETCH & PARSE THE .spec DATA
$station = '41112';
$specUrl = "https://www.ndbc.noaa.gov/data/realtime2/{$station}.spec";
$lines   = @file($specUrl, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
if (!$lines) {
    die("Unable to fetch .spec for station {$station}");
}

// 1a) Find the header line and skip units line
$cols     = []; $startRow = null;
foreach ($lines as $i => $line) {
    if (preg_match('/^\s*#\s*YY\s+MM\s+DD/', $line)) {
        $cols     = preg_split('/\s+/', trim(substr($line,1)));
        $startRow = $i + 2; // skip next (units) line too
        break;
    }
}
if (!$cols) die("Spec header not found");

// 1b) Direction → degrees map
$dirMap = [
    'N'=>0,'NNE'=>22,'NE'=>45,'ENE'=>67,
    'E'=>90,'ESE'=>112,'SE'=>135,'SSE'=>157,
    'S'=>180,'SSW'=>202,'SW'=>225,'WSW'=>247,
    'W'=>270,'WNW'=>292,'NW'=>315,'NNW'=>337
];

// 1c) Build an array of parsed rows
$dataRows = [];
for ($i = $startRow; $i < count($lines); $i++) {
    $line = trim($lines[$i]);
    if ($line === '' || strpos($line, '#') === 0) continue;
    $vals = preg_split('/\s+/', $line);
    if (count($vals) < count($cols)) continue;

    // build timestamp
    list($YY, $MM, $DD, $hh, $mn) = array_slice($vals,0,5);
    $ts = sprintf('%04d-%02d-%02d %02d:%02d:00', $YY,$MM,$DD,$hh,$mn);

    // map & sanitize each column
    $row = ['ts' => $ts];
    foreach ($cols as $idx => $col) {
        $raw = $vals[$idx] ?? '';
        if ($raw === '' || strtoupper($raw) === 'N/A') {
            $row[$col] = null;
        } elseif (in_array($col, ['SwD','WWD'])) {
            $row[$col] = $dirMap[$raw] ?? null;
        } elseif ($col === 'STEEPNESS') {
            $row[$col] = $raw;
        } else {
            $row[$col] = is_numeric($raw) ? floatval($raw) : null;
        }
    }
    $dataRows[] = $row;
}

// 2) CONNECT TO DATABASE
$pdo = new PDO(
    "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// 3) INSERT NEW WAVE DATA
// drop the raw date cols: YY,MM,DD,hh,mn
$dataCols = array_filter($cols, fn($c) => !in_array($c, ['YY','MM','DD','hh','mn']));
$dbCols   = array_merge(['ts'], $dataCols);
$ph       = implode(',', array_fill(0, count($dbCols), '?'));
$sqlIns   = sprintf(
    "INSERT IGNORE INTO wave_data (%s) VALUES (%s)",
    implode(',', $dbCols), $ph
);
$stmtIns = $pdo->prepare($sqlIns);
foreach ($dataRows as $r) {
    $params = [$r['ts']];
    foreach ($dataCols as $c) {
        $params[] = $r[$c];
    }
    $stmtIns->execute($params);
}

// 4) LOAD LATEST 50 ROWS
$colsList   = implode(',', $dataCols);
$stmtLatest = $pdo->query(
    "SELECT ts, {$colsList} FROM wave_data ORDER BY ts DESC LIMIT 50"
);
$latest = $stmtLatest->fetchAll(PDO::FETCH_ASSOC);

// 5) COMPUTE USER’S “NOW” IN UTC
$userTz  = new DateTimeZone('America/New_York');
$userNow = new DateTime('now', $userTz);
$userNow->setTimezone(new DateTimeZone('UTC'));
$targetTs = $userNow->format('Y-m-d H:i:00');

// 6) FETCH THE ROW CLOSEST TO targetTs
$stmtClose = $pdo->prepare("
    SELECT ts, {$colsList},
           ABS(TIMESTAMPDIFF(SECOND, ts, ?)) AS diff
      FROM wave_data
     ORDER BY diff
     LIMIT 1
");
$stmtClose->execute([$targetTs]);
$closest = $stmtClose->fetch(PDO::FETCH_ASSOC) ?: null;

// 7) FIND SURF SPOTS MATCHING MWD
$matchingSpots = [];
if ($closest && isset($closest['MWD'])) {
    $mwd = (int)$closest['MWD'];
    $stmtSpots = $pdo->prepare("
        SELECT id, spot_name, spot_window_min, spot_window_max
          FROM surf_spots
         WHERE spot_window_min <= ? AND spot_window_max >= ?
         ORDER BY spot_name
    ");
    $stmtSpots->execute([$mwd, $mwd]);
    $matchingSpots = $stmtSpots->fetchAll(PDO::FETCH_ASSOC);
}

// Safe HTML helper
function h($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
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
        <?php foreach ($dataCols as $c): ?><th><?= h($c) ?></th><?php endforeach ?>
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

  <h2>Row Closest to Your Time (<?= h($targetTs) ?> UTC)</h2>
  <table>
    <thead>
      <tr>
        <th>Timestamp (UTC)</th>
        <?php foreach ($dataCols as $c): ?><th><?= h($c) ?></th><?php endforeach ?>
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
        <tr><td colspan="<?= count($dataCols)+1 ?>">No data found</td></tr>
      <?php endif ?>
    </tbody>
  </table>

  <h2>Surf Spots Matching MWD = <?= h($closest['MWD'] ?? '-') ?>°</h2>
  <?php if (empty($matchingSpots)): ?>
    <p>No surf spots match that wave direction.</p>
  <?php else: ?>
    <ul>
      <?php foreach ($matchingSpots as $s): ?>
      <li>
        <?= h($s['spot_name']) ?> 
        (<?= h($s['spot_window_min']) ?>&ndash;<?= h($s['spot_window_max']) ?>°)
      </li>
      <?php endforeach ?>
    </ul>
  <?php endif ?>
</body>
</html>
