<?php
// index.php
error_reporting(E_ALL);
ini_set('display_errors', '1');

include 'admin/config.php';        // defines DB_HOST, DB_NAME, DB_USER, DB_PASS
require 'noaa_api_request.php';

$userTz   = new DateTimeZone('America/New_York');
$userNow  = new DateTime('now', $userTz);
$userNow->setTimezone(new DateTimeZone('UTC'));
$targetTs = $userNow->format('Y-m-d H:i:00');  // round to the minute

// 2) Prepare & execute the “closest” query
$closestStmt = $pdo->prepare("
    SELECT ts, WVHT, SwH, SwP, WWH, WWP, SwD, WWD, STEEPNESS, APD, MWD,
           ABS(TIMESTAMPDIFF(SECOND, ts, ?)) AS diff
      FROM wave_data
     ORDER BY diff
     LIMIT 1
");
$closestStmt->execute([$targetTs]);
$closest = $closestStmt->fetch(PDO::FETCH_ASSOC); ?>

<h2>Data Closest to Your Time (<?php echo htmlspecialchars($targetTs) ?> UTC)</h2>
<table>
    <thead>
        <tr>
            <th>ts</th>
            <th>WVHT</th>
            <th>SwH</th>
            <th>SwP</th>
            <th>WWH</th>
            <th>WWP</th>
            <th>SwD</th>
            <th>WWD</th>
            <th>STEEPNESS</th>
            <th>APD</th>
            <th>MWD</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <?php if ($closest): ?>
                <?php foreach (['ts', 'WVHT', 'SwH', 'SwP', 'WWH', 'WWP', 'SwD', 'WWD', 'STEEPNESS', 'APD', 'MWD'] as $c): ?>
                    <td><?php echo htmlspecialchars((string)($closest[$c] ?? '')) ?></td>
                <?php endforeach ?>
            <?php else: ?>
                <td colspan="11">No data found</td>
            <?php endif ?>
        </tr>
    </tbody>
</table>