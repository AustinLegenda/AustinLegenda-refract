<?php
// NOAA Buoy Data Importer for Station 41112

$url = "https://www.ndbc.noaa.gov/data/realtime2/41112.spec";

// Attempt to open the remote file
$file = @fopen($url, "r");
if (!$file) {
    die("Unable to open remote NOAA file.");
}

$cardinalMap = [
    'N'   => 0,   'NNE' => 22.5, 'NE'  => 45,   'ENE' => 67.5,
    'E'   => 90,  'ESE' => 112.5,'SE'  => 135,  'SSE' => 157.5,
    'S'   => 180, 'SSW' => 202.5,'SW'  => 225,  'WSW' => 247.5,
    'W'   => 270, 'WNW' => 292.5,'NW'  => 315,  'NNW' => 337.5,
    'VRB' => 0 // variable wind -> treat as 0 deg (or convert to null if preferred)
];

try {
    // 1) Fetch and parse the .spec file to build a header map
    $specLines = file($specUrl, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $headerLine = null;
    foreach ($specLines as $line) {
        if (preg_match('/^\s*#\s*YY/', $line)) {
            $headerLine = trim(substr($line, 1));
            break;
        }
    }
    if (!$headerLine) {
        throw new Exception('Could not locate spec header in: ' . $specUrl);
    }
    $columns = preg_split('/\s+/', $headerLine);

    // 2) Establish PDO connection
    $dsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // 3) Open the .txt data file
    $file = @fopen($dataUrl, 'r');
    if (!$file) {
        throw new Exception('Unable to open data file: ' . $dataUrl);
    }

    // 4) Process each data line
    while (!feof($file)) {
        $line = fgets($file);
        $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);
        if (preg_match('/^\s*#/', $line) || trim($line) === '') {
            continue;
        }

        $values = preg_split('/\s+/', trim($line));
        if (count($values) < count($columns) || !is_numeric($values[0])) {
            continue;
        }

        // Combine header keys with values
        $row = array_combine($columns, array_slice($values, 0, count($columns)));

        // Convert cardinal SWD/WWD to integer degrees
        foreach (['SWD', 'WWD'] as $dir) {
            if (isset($row[$dir])) {
                $v = $row[$dir];
                if (is_numeric($v)) {
                    $row[$dir] = (int) $v;
                } elseif (isset($cardinalMap[$v])) {
                    $row[$dir] = (int) round($cardinalMap[$v]);
                } else {
                    // unknown cardinal -> null
                    $row[$dir] = null;
                }
            }
        }

        // Prepare and execute INSERT IGNORE
        $stmt = $pdo->prepare(
            "INSERT IGNORE INTO buoy_data (
                year, month, day, hour, minute,
                wvht, swh, swp, wwh, wwp,
                swd, wwd, steepness, apd, mwd
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $stmt->execute([
            $row['YY'], $row['MM'], $row['DD'], $row['hh'], $row['mm'],
            $row['WVHT'], $row['SwH'], $row['SwP'], $row['WWH'], $row['WWP'],
            $row['SWD'], $row['WWD'], $row['STEEPNESS'], $row['APD'], $row['MWD']
        ]);
    }
    fclose($file);
    echo "NOAA buoy data import completed successfully.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>