<?php
// index.php
error_reporting(E_ALL);
ini_set('display_errors', '1');

// 0) Load config & importer
include 'admin/config.php';            // defines DB_HOST, DB_NAME, DB_USER, DB_PASS
require 'noaa_api_request.php';        // fetchNoaaSpectralData()

try {
    // 1) Fetch & parse the live .spec data
    $station = '41112';
    $result  = fetchNoaaSpectralData($station);
    $cols    = $result['columns'];    // e.g. ['YY','MM','DD','hh','mm','WVHT',...]
    $rows    = $result['data'];       // each row: ['ts'=>..., 'WVHT'=>..., etc.]

    // 2) Connect to MySQL
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // 3) Insert spectral rows into wave_data (ignore duplicates)
    // We store everything except the raw YY/MM/DD/hh/mm
    $dataCols = array_filter($cols, fn($c) => !in_array($c, ['YY','MM','DD','hh','mm']));
    $dbCols   = array_merge(['ts'], $dataCols);
    $ph       = implode(',', array_fill(0, count($dbCols), '?'));
    $insertSql = sprintf(
        "INSERT IGNORE INTO wave_data (%s) VALUES (%s)",
        implode(',', $dbCols),
        $ph
    );
    $insertStmt = $pdo->prepare($insertSql);

    foreach ($rows as $r) {
        $params = [$r['ts']];
        foreach ($dataCols as $col) {
            $params[] = $r[$col];
        }
        $insertStmt->execute($params);
    }

    // 4) Pull the latest 50 for the main table
    $colsList = implode(',', $dataCols);
    $latestStmt = $pdo->query(
        "SELECT ts, {$colsList}
           FROM wave_data
          ORDER BY ts DESC
          LIMIT 50"
    );
    $latest = $latestStmt->fetchAll(PDO::FETCH_ASSOC);

    // 5) Compute the user’s local “now” in UTC
    $userTz   = new DateTimeZone('America/New_York');
    $userNow  = new DateTime('now', $userTz);
    $userNow->setTimezone(new DateTimeZone('UTC'));
    $targetTs = $userNow->format('Y-m-d H:i:00');

    // 6) Find the single row closest to that timestamp
    $closestStmt = $pdo->prepare("
        SELECT ts, {$colsList},
               ABS(TIMESTAMPDIFF(SECOND, ts, ?)) AS diff
          FROM wave_data
         ORDER BY diff
         LIMIT 1
    ");
    $closestStmt->execute([$targetTs]);
    $closest = $closestStmt->fetch(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

// Helper to safely output strings/nulls
function h($s) {
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Spectral Wave Data — Station <?= h($station) ?></title>
  <style>
    body { font-family: sans-serif; margin: 20px; }
    table { border-collapse: collapse; width:100%; margin-bottom:40px; }
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
        <?php foreach ($dataCols as $col): ?>
          <th><?= h($col) ?></th>
        <?php endforeach ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($latest as $row): ?>
      <tr>
        <td><?= h($row['ts']) ?></td>
        <?php foreach ($dataCols as $col): ?>
          <td><?= h($row[$col]) ?></td>
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
        <?php foreach ($dataCols as $col): ?>
          <th><?= h($col) ?></th>
        <?php endforeach ?>
      </tr>
    </thead>
    <tbody>
      <tr>
        <?php if ($closest): ?>
          <td><?= h($closest['ts']) ?></td>
          <?php foreach ($dataCols as $col): ?>
            <td><?= h($closest[$col]) ?></td>
          <?php endforeach ?>
        <?php else: ?>
          <td colspan="<?= count($dataCols) + 1 ?>">No matching data found</td>
        <?php endif ?>
      </tr>
    </tbody>
  </table>
</body>
</html>
