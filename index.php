<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

// DB config and connection
include('admin/config.php');
require 'noaa_api_request.php'; 
$pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASSWORD);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 1) build schema map
$station = '41112';
$columns = getNoaaSchema($station);

// 2) open the real data file
$dataUrl = "https://www.ndbc.noaa.gov/data/realtime2/{$station}.txt";
$file    = @fopen($dataUrl, 'r');
if (! $file) {
    die("Cannot open data for station {$station}");
}

// 3) loop & import
while (! feof($file)) {
    $line = fgets($file);
    // strip BOM
    $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);

    // skip header/comments/empty
    if (preg_match('/^\s*#/', $line) || trim($line) === '') {
        continue;
    }

    // split into fields
    $vals = preg_split('/\s+/', trim($line));
    // must match or exceed schema length, and year must be numeric
    if (count($vals) < count($columns) || ! is_numeric($vals[0])) {
        continue;
    }

    // map name→value
    $row = array_combine($columns, array_slice($vals, 0, count($columns)));

    // now insert the 15 fields you care about
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO buoy_data (
          year, month, day, hour, minute,
          wvht, swh, swp, wwh, wwp,
          swd, wwd, steepness, apd, mwd
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $row['YY'], $row['MM'], $row['DD'], $row['hh'], $row['mm'],
        $row['WVHT'], $row['SwH'], $row['SwP'], $row['WWH'], $row['WWP'],
        $row['SWD'], $row['WWD'], $row['STEEPNESS'], $row['APD'], $row['MWD'],
    ]);
}

fclose($file);
echo "Import complete.\n";