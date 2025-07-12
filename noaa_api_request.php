<?php
/**
 * spec_parser.php
 *
 * Fetches and parses NDBC’s .spec file for a buoy station,
 * producing a JSON map of column‐codes to human-readable descriptions.
 */

// — CONFIGURATION —
$station = '41112';
$specUrl = "https://www.ndbc.noaa.gov/data/realtime2/{$station}.spec";

// — FETCH & CLEAN —
$lines = @file($specUrl, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if (! $lines) {
    http_response_code(500);
    echo json_encode(['error' => "Unable to fetch spec for station {$station}"]);
    exit;
}

// — 1) Find header row & extract column codes —
$columns = [];
foreach ($lines as $i => $line) {
    if (preg_match('/^\s*#\s*YY/', $line)) {
        // drop leading ‘#’ then split on whitespace
        $columns = preg_split('/\s+/', trim(substr($line, 1)));
        $headerIndex = $i;
        break;
    }
}
if (empty($columns)) {
    http_response_code(500);
    echo json_encode(['error' => "Spec header not found in {$specUrl}"]);
    exit;
}

// — 2) Parse definitions from the lines _after_ the header —
$definitions = [];
for ($j = $headerIndex + 1; $j < count($lines); $j++) {
    $line = trim($lines[$j]);
    // match lines like “#YR Year”
    if (preg_match('/^#\s*([A-Za-z0-9]+)\s+(.*)$/', $line, $m)) {
        list(, $code, $desc) = $m;
        $definitions[$code] = $desc;
    }
}

// — 3) Output JSON —
header('Content-Type: application/json');
echo json_encode([
    'station'     => $station,
    'columns'     => $columns,
    'definitions' => $definitions,
], JSON_PRETTY_PRINT);
