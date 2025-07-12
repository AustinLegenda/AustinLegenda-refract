<?php
/**
 * spec_units.php
 *
 * Parses NDBC’s .spec file for station 41112
 * to build a map of COLUMN → UNIT.
 */

//— CONFIG
$station = '41112';
$specUrl = "https://www.ndbc.noaa.gov/data/realtime2/{$station}.spec";
$dataUrl = "https://www.ndbc.noaa.gov/data/realtime2/{$station}.txt";

//— 1) Fetch and parse the .spec (units) line
$specContents = @file_get_contents($specUrl);
if ($specContents === false) {
    die("❌ Unable to fetch spec file at {$specUrl}");
}
// Remove any BOM and leading ‘#’
$specLine = preg_replace('/^\xEF\xBB\xBF#/', '', trim($specContents));
// Split on whitespace to get unit codes, e.g. ['yr','mo','dy','hr','mn','m',…]
$unitCodes = preg_split('/\s+/', $specLine);

//— 2) Open the .txt file just to grab its header (column codes)
$file = @fopen($dataUrl, 'r');
if (! $file) {
    die("❌ Unable to open data file at {$dataUrl}");
}
$columnCodes = [];
while (! feof($file)) {
    $line = fgets($file);
    // Look for the very first header row (#YY …)
    if (preg_match('/^\s*#\s*YY/', $line)) {
        // Strip BOM/#, then split to get ['YY','MM','DD','hh','mm','WVHT',…]
        $columnCodes = preg_split('/\s+/', trim(substr(preg_replace('/^\xEF\xBB\xBF/', '', $line), 1)));
        break;
    }
}
fclose($file);

if (count($columnCodes) !== count($unitCodes)) {
    die("❌ Column count (".count($columnCodes).") does not match unit count (".count($unitCodes).")");
}

//— 3) Combine into a map: CODE => UNIT
$unitMap = array_combine($columnCodes, $unitCodes);

//— 4) Output as JSON
header('Content-Type: application/json');
echo json_encode([
    'station'  => $station,
    'columns'  => $columnCodes,
    'units'    => $unitMap,
], JSON_PRETTY_PRINT);
