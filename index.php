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

// Step 2: Insert new records
foreach ($lines as $line) {
    // Strip any UTF-8 BOM
    $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);

    // Skip blank lines or any line whose first non-space char is '#'
    if (preg_match('/^\s*#/', $line) || trim($line) === '') {
        continue;
    }

    // Split on whitespace
    $parts = preg_split('/\s+/', trim($line));
    if (count($parts) < 15) {
        continue; // too few columns
    }

    // Make sure we really have numeric year/month/day etc.
    if (!is_numeric($parts[0])) {
        continue;
    }

    // Check for existing row
    list($year, $month, $day, $hour, $minute) = array_slice($parts, 0, 5);
    $check = $pdo->prepare("
        SELECT COUNT(*) FROM buoy_data 
         WHERE year=? AND month=? AND day=? AND hour=? AND minute=?
    ");
    $check->execute([$year, $month, $day, $hour, $minute]);
    if ($check->fetchColumn() > 0) {
        continue;
    }

    // Insert new row
    $insert = $pdo->prepare("
      INSERT INTO buoy_data (
        year, month, day, hour, minute,
        wvht, swh, swp, wwh, wwp,
        swd, wwd, steepness, apd, mwd
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    // take exactly the first 15 fields
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
