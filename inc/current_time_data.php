<?php 
// Include the configuration file and connect to the database
include('admin/config.php');
$conn = new PDO("mysql:host=$servername;dbname=$database", $username, $password);

// Execute the SQL query and retrieve the result as an associative array
$query = "SELECT * FROM buoy_data WHERE CONCAT(year, '-', month, '-', day, ' ', hour, ':', minute, ':00') <= NOW() ORDER BY CONCAT(year, '-', month, '-', day, ' ', hour, ':', minute, ':', second) DESC LIMIT 1";
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

// Display the data in the header
echo "Latest data: WVHT=$wvht, SWH=$swh, SWP=$swp, WWH=$wwh, WWP=$wwp, SWD=$swd, WWD=$wwd, STEEPNESS=$steepness, APD=$apd, MWD=$mwd";
?>