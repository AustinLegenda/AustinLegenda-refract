<?php
// NOAA Buoy Data Importer for Station 41112

$url = "https://www.ndbc.noaa.gov/data/realtime2/41112.spec";

// Attempt to open the remote file
$file = @fopen($url, "r");
if (!$file) {
    die("Unable to open remote NOAA file.");
}

try {
    // 1) Fetch and parse the .spec file to build a header map
    $specLines = file($specUrl, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $headerLine = null;
    foreach ($specLines as $line) {
        // Find the first line starting with '# YY'
        if (preg_match('/^\s*#\s*YY/', $line)) {
            // Drop the leading '#'
            $headerLine = trim(substr($line, 1));
            break;
        }
    }
    if (!$headerLine) {
        throw new Exception('Could not locate spec header in: ' . $specUrl);
    }
    // Columns array: ['YY','MM','DD','hh','mm','WVHT','SwH',...]
    $columns = preg_split('/\s+/', $headerLine);

    // 2) Establish PDO connection
    $dsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Optional: ensure unique index on timestamp to prevent duplicates
    // $pdo->exec("ALTER TABLE buoy_data ADD UNIQUE KEY uniq_time (year, month, day, hour, minute)");

    // 3) Open the real data file (.txt)
    $file = @fopen($dataUrl, 'r');
    if (!$file) {
        throw new Exception('Unable to open data file: ' . $dataUrl);
    }

    // 4) Process each line of the data
    while (!feof($file)) {
        $line = fgets($file);
        // Strip UTF-8 BOM if present
        $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);

        // Skip comment/header or empty lines
        if (preg_match('/^\s*#/', $line) || trim($line) === '') {
            continue;
        }

        $values = preg_split('/\s+/', trim($line));
        // Must have at least as many fields as columns and first value must be numeric
        if (count($values) < count($columns) || !is_numeric($values[0])) {
            continue;
        }

        // Combine into associative row: ['YY'=>2025, 'MM'=>07, ...]
        $row = array_combine($columns, array_slice($values, 0, count($columns)));

        // Prepare INSERT (ignore duplicates if unique index exists)
        $stmt = $pdo->prepare(
            "INSERT IGNORE INTO buoy_data (
                year, month, day, hour, minute,
                wvht, swh, swp, wwh, wwp,
                swd, wwd, steepness, apd, mwd
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $stmt->execute([
            // Map spec columns to DB order
            $row['YY'],        // year
            $row['MM'],        // month
            $row['DD'],        // day
            $row['hh'],        // hour
            $row['mm'],        // minute
            $row['WVHT'],      // significant wave height
            $row['SwH'],       // swell height
            $row['SwP'],       // swell period
            $row['WWH'],       // wind wave height
            $row['WWP'],       // wind wave period
            $row['SWD'],       // swell direction
            $row['WWD'],       // wind wave direction
            $row['STEEPNESS'], // steepness
            $row['APD'],       // average period
            $row['MWD'],       // mean wave direction
        ]);
    }
    fclose($file);

    echo "NOAA buoy data import completed successfully.\n";

} catch (Exception $e) {
    // Handle errors gracefully
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}?>