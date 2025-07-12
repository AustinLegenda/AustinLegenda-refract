
<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Include the configuration file and connect to the database
include('admin/config.php');
$conn = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASSWORD);

// Set the time zone to Eastern Time
date_default_timezone_set('America/New_York');

// Calculate the time one hour past the current standard Eastern Time
$one_hour_past_est = date('Y-m-d H:i:s', strtotime('+1 hour'));

// Execute the SQL query and retrieve the result as an associative array
$query = "SELECT * FROM buoy_data WHERE CONCAT(year, '-', month, '-', day, ' ', hour, ':', minute, ':00') <= '$one_hour_past_est' ORDER BY CONCAT(year, '-', month, '-', day, ' ', hour, ':', minute) DESC LIMIT 1";
$stmt = $conn->prepare($query);
$stmt->execute();
$result = $stmt->fetch(PDO::FETCH_ASSOC);

// Extract the relevant data from the array
$wvht = $result['wvht'];
$swh = $result['swh'];
$swp = $result['swp'];
$wwh = $result['wwh'];
$wwp = $result['wwp'];
$swd = $result['swd'];
$wwd = $result['wwd'];
$steepness = $result['steepness'];
$apd = $result['apd'];
$mwd = $result['mwd'];
?>

<html>
    <head>
          <link rel="stylesheet" type="text/css" href="style.css">
    </head>
    <body onload="getCurrentTime()">
        <!--current date and time-->
        <h1>Buoy Data</h1>
        <!--echo data one hour past current time-->
        <h2 id="date"></h2>
        <p id="hour"></p>
        <?php // Display the data
echo "WVHT=$wvht, SWH=$swh, SWP=$swp, WWH=$wwh, WWP=$wwp, SWD=$swd, WWD=$wwd, STEEPNESS=$steepness, APD=$apd, MWD=$mwd";
?>
        
                    
<footer>
<script src="functions.js"></script>
</footer>