<?php
// NOAA Buoy Data Importer for Station 41112

$url = "https://www.ndbc.noaa.gov/data/realtime2/41112.spec";

// Attempt to open the remote file
$file = @fopen($url, "r");
if (!$file) {
    die("Unable to open remote NOAA file.");
}

try {
    // Update with your actual DB credentials
    $conn = new PDO("mysql:host=localhost;dbname=your_database", "your_username", "your_password");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    while (!feof($file)) {
        $line = fgets($file);

        // Skip comment or empty lines
        if (strpos(trim($line), '#') === 0 || trim($line) === '') {
            continue;
        }

        $data = preg_split("/[\s]+/", trim($line));
        if (count($data) !== 15 || !is_numeric($data[0])) {
            continue; // Skip malformed rows
        }

        list($year, $month, $day, $hour, $minute,
             $wvht, $swh, $swp, $wwh, $wwp,
             $swd, $wwd, $steepness, $apd, $mwd) = $data;

        $stmt = $conn->prepare("INSERT INTO buoy_data (
            year, month, day, hour, minute,
            wvht, swh, swp, wwh, wwp,
            swd, wwd, steepness, apd, mwd
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $stmt->execute([
            $year, $month, $day, $hour, $minute,
            $wvht, $swh, $swp, $wwh, $wwp,
            $swd, $wwd, $steepness, $apd, $mwd
        ]);
    }

    echo "NOAA buoy data import completed successfully.";
} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage();
}

fclose($file);
?>
