
<?php  
// $url = "https://www.ndbc.noaa.gov/data/realtime2/41112.spec";

// $file = fopen($url, "r") or die("Unable to open file!");
// try {
//      $conn = new PDO("mysql:host=localhost;dbname=your_database", "username", "password");   
//     $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

//     while (!feof($file)) {
//         $line = fgets($file);

//         if (substr($line, 0, 1) !== "#") {
//             $data = preg_split("/[\s]+/", trim($line));
//             list($year, $month, $day, $hour, $minute, $wvht, $swh, $swp, $wwh, $wwp, $swd, $wwd, $steepness, $apd, $mwd) = $data;

//             // Check if all the values have been extracted successfully
//             if (isset($year) && isset($month) && isset($day) && isset($hour) && isset($minute) && isset($wvht) && isset($swh) && isset($swp) && isset($wwh) && isset($wwp) && isset($swd) && isset($wwd) && isset($steepness) && isset($apd) && isset($mwd)) {
//                 // Get the current date and time
//                 $currentYear = date('Y');
//                 $currentMonth = date('m');
//                 $currentDay = date('d');
//                 $currentHour = date('H');
//                 $currentMinute = date('i');

//                 // Check if the data is equal to or greater than the current time
//                 if ($year > $currentYear || ($year == $currentYear && $month > $currentMonth) || ($year == $currentYear && $month == $currentMonth && $day > $currentDay) || ($year == $currentYear && $month == $currentMonth && $day == $currentDay && $hour > $currentHour) || ($year == $currentYear && $month == $currentMonth && $day == $currentDay && $hour == $currentHour && $minute >= $currentMinute)) {
//                     $stmt = $conn->prepare("INSERT INTO buoy_data (year, month, day, hour, minute, wvht, swh, swp, wwh, wwp, swd, wwd, steepness, apd, mwd) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
//                     $stmt->execute([$year, $month, $day, $hour, $minute, $wvht, $swh, $swp, $wwh, $wwp, $swd, $wwd, $steepness, $apd, $mwd]);
//                 }
//                    }
//                 }
//             }

//     echo "Data successfully inserted.";

// } catch (PDOException $e) {
//     // Catch and handle the exception
// }  

$url = "https://www.ndbc.noaa.gov/data/realtime2/41112.spec";

$file = fopen($url, "r") or die("Unable to open file!");
try {
    $conn = new PDO("mysql:host=gator3189.hostgator.com;dbname=legenda1_refract", "legenda1_austin", "3NKDyhRSfFgRujst");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Delete all existing data from the table
$conn->exec("DELETE FROM buoy_data");

    while (!feof($file)) {
        $line = fgets($file);

        if (substr($line, 0, 1) !== "#") {
            $data = preg_split("/[\s]+/", trim($line));
            list($year, $month, $day, $hour, $minute, $wvht, $swh, $swp, $wwh, $wwp, $swd, $wwd, $steepness, $apd, $mwd) = $data;

            // Check if all the values have been extracted successfully
            if (isset($year) && isset($month) && isset($day) && isset($hour) && isset($minute) && isset($wvht) && isset($swh) && isset($swp) && isset($wwh) && isset($wwp) && isset($swd) && isset($wwd) && isset($steepness) && isset($apd) && isset($mwd)) {
                  // Convert meters to feet
                  $wvht = $wvht * 3.2808;
                  $swh = $swh * 3.2808;
                  $wwh = $wwh * 3.2808;
                
                // Get the current date and time
                $currentYear = date('Y');
                $currentMonth = date('m');
                $currentDay = date('d');
                $currentHour = date('H');
                $currentMinute = date('i');


                // Check if the data is equal to or greater than the current time
                if ($year > $currentYear || ($year == $currentYear && $month > $currentMonth) || ($year == $currentYear && $month == $currentMonth && $day > $currentDay) || ($year == $currentYear && $month == $currentMonth && $day == $currentDay && $hour > $currentHour) || ($year == $currentYear && $month == $currentMonth && $day == $currentDay && $hour == $currentHour && $minute >= $currentMinute)) {
                    $stmt = $conn->prepare("INSERT INTO buoy_data (year, month, day, hour, minute, wvht, swh, swp, wwh, wwp, swd, wwd, steepness, apd, mwd) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$year, $month, $day, $hour, $minute, $wvht, $swh, $swp, $wwh, $wwp, $swd, $wwd, $steepness, $apd, $mwd]);
                }
                   }
                }
            }

    echo "Data successfully inserted.";

} catch (PDOException $e) {
    // Catch and handle the exception
}
?>