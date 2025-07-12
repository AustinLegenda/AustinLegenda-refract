<?php
// index.php
error_reporting(E_ALL);
ini_set('display_errors', '1');

include 'admin/config.php';        // defines DB_HOST, DB_NAME, DB_USER, DB_PASS
require 'noaa_api_request.php';

try {
    // 1) Fetch & parse
    $station = '41112';
    $result  = fetchNoaaSpectralData($station);
    $cols    = $result['columns'];    // e.g. ['YY','MM','DD','hh','mm','WVHT',...]
    $rows    = $result['data'];       // array of assoc arrays with ts + each col

    // 2) Connect to MySQL
    $pdo = new PDO(
      "mysql:host=".DB_HOST.";dbname=".DB_NAME,
      DB_USER, DB_PASS,
      [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // 3) Prepare INSERT IGNORE
    // We’ll store all columns except the raw YY/MM/DD/hh/mm fields,
    // since we have ts. So filter those out:
    $dataCols = array_filter($cols, function($c){
        return !in_array($c, ['YY','MM','DD','hh','mm']);
    });
    // Build SQL
    $dbCols   = array_merge(['ts'], $dataCols);
    $ph       = implode(',', array_fill(0, count($dbCols), '?'));
    $insertSql = sprintf(
      "INSERT IGNORE INTO wave_data (%s) VALUES (%s)",
      implode(',', $dbCols),
      $ph
    );
    $stmt = $pdo->prepare($insertSql);

    // 4) Insert each row
    foreach ($rows as $r) {
        $params = [];
        // timestamp first
        $params[] = $r['ts'];
        // then each data column
        foreach ($dataCols as $col) {
            $params[] = $r[$col];
        }
        $stmt->execute($params);
    }

    // 5) Render last 50 rows
    $out = $pdo->query(
      "SELECT ts, ".implode(',', $dataCols).
      " FROM wave_data ORDER BY ts DESC LIMIT 50"
    )->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Spectral Wave Data (Station <?php echo htmlspecialchars($station) ?>)</title>
  <style>
    table { border-collapse: collapse; width:100%; }
    th, td { padding:4px 8px; border:1px solid #ccc; text-align:center; }
    th { background:#eee; }
  </style>
</head>
<body>
  <h1>Station <?php echo htmlspecialchars($station) ?> — Latest 50 Records</h1>
  <table>
    <thead>
      <tr>
        <th>Timestamp (UTC)</th>
        <?php foreach ($dataCols as $col): ?>
          <th><?php echo htmlspecialchars($col) ?></th>
        <?php endforeach ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($out as $row): ?>
      <tr>
        <td><?php echo htmlspecialchars($row['ts']) ?></td>
        <?php foreach ($dataCols as $col): ?>
          <td><?php echo htmlspecialchars($row[$col]) ?></td>
        <?php endforeach ?>
      </tr>
      <?php endforeach ?>
    </tbody>
  </table>
</body>
</html>
