<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

// DB config and connection
include('admin/config.php');
$pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASSWORD);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Set time zone
date_default_timezone_set('America/New_York');

// Step 1: Fetch NOAA data
$url = 'https://www.ndbc.noaa.gov/data/realtime2/41112.spec';
$response = file_get_contents($url);
$lines = explode("\n", $response);
$header = array_shift($lines); // remove column headers

// Step 2: Insert new records
foreach ($lines as $line) {
    if (trim($line) === '') continue;
    $parts = preg_split('/\s+/', $line);
    if (count($parts) < 15) continue;

    // Build timestamp for this row
    list($year, $month, $day, $hour, $minute) = array_slice($parts, 0, 5);
    $timestamp = "$year-$month-$day $hour:$minute:00";

    // Check if this timestamp exists already
    $check = $pdo->prepare("
        SELECT COUNT(*) FROM buoy_data 
        WHERE year = ? AND month = ? AND day = ? AND hour = ? AND minute = ?
    ");
    $check->execute([$year, $month, $day, $hour, $minute]);
    if ($check->fetchColumn() > 0) continue;

    // Insert new row
    $insert = $pdo->prepare("
        INSERT INTO buoy_data (year, month, day, hour, minute, wvht, swh, swp, wwh, wwp, swd, wwd, steepness, apd, mwd)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $insert->execute(array_slice($parts, 0, 15));
}

// Step 3: Find the row closest to current time
$now = date('Y-m-d H:i:s');
$query = "
    SELECT *, 
           TIMESTAMP(year, month, day, hour, minute, 0) AS buoy_time
    FROM buoy_data
    ORDER BY ABS(TIMESTAMPDIFF(SECOND,
                TIMESTAMP(year, month, day, hour, minute, 0),
                ?)) ASC
    LIMIT 1
";
$stmt = $pdo->prepare($query);
$stmt->execute([$now]);
$result = $stmt->fetch(PDO::FETCH_ASSOC);
?>

<html>
<head>
    <title>Buoy Data</title>
    <link rel="stylesheet" type="text/css" href="style.css">
</head>
<body onload="getCurrentTime()">
    <h1>Buoy Data</h1>
    <h2 id="date"></h2>
    <p id="hour"></p>

    <?php if ($result): ?>
        <table border="1" cellpadding="5">
            <?php foreach ($result as $key => $value): ?>
                <tr>
                    <th><?php echo htmlspecialchars($key); ?></th>
                    <td><?php echo htmlspecialchars($value); ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <p>No data available.</p>
    <?php endif; ?>

    <footer>
        <script src="functions.js"></script>
    </footer>
</body>
</html>
