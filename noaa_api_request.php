<?php
// NOAA Buoy Data Fetcher: 41112.spec

$url = "https://www.ndbc.noaa.gov/data/realtime2/41112.spec";

// Open remote file
$file = @fopen($url, "r");
if (!$file) {
    die("Unable to open remote NOAA file.");
}

try {
    $conn = new PDO("mysql:host=localhost;dbname=your_database", "your_username", "your_password");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    while (!feof($file)) {
        $line = fgets($file);

        // Skip comment lines
        if (substr($line, 0, 1) === "#") {
            continue;
        }

        $data = preg_split("/[\s]+/", trim($line));
        if (count($data) < 15) {
            continue; // Skip incomplete lines
        }

        list($year, $month, $day, $hour, $minute, $wvht, $swh, $swp, $wwh, $wwp, $swd, $wwd, $steepness, $apd, $mwd) = $data;

        // Prepare and execute insert statement
        $stmt = $conn->prepare("INSERT INTO buoy_data (year, month, day, hour, minute, wvht, swh, swp, wwh, wwp, swd, wwd, steepness, apd, mwd)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$year, $month, $day, $hour, $minute, $wvht, $swh, $swp, $wwh, $wwp, $swd, $wwd, $steepness, $apd, $mwd]);
    }

    echo "Data import complete.";
} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage();
}

fclose($file);
?>
