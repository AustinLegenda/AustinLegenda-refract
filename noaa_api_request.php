<?php
/**
 * Parses .spec file for station 41112 and outputs cleaned JSON.
 * Handles directional text (ESE) → degrees, and skips 'N/A'.
 */

function directionToDegrees($dir) {
    static $map = [
        'N'=>0, 'NNE'=>22, 'NE'=>45, 'ENE'=>67, 'E'=>90, 'ESE'=>112,
        'SE'=>135, 'SSE'=>157, 'S'=>180, 'SSW'=>202, 'SW'=>225, 'WSW'=>247,
        'W'=>270, 'WNW'=>292, 'NW'=>315, 'NNW'=>337
    ];
    return $map[$dir] ?? null;
}

$station = '41112';
$url = "https://www.ndbc.noaa.gov/data/realtime2/{$station}.spec";
$lines = @file($url, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

if (!$lines || count($lines) < 3) {
    http_response_code(500);
    echo json_encode(['error' => 'Unable to read or parse .spec file']);
    exit;
}

// First line is column names
$columns = preg_split('/\s+/', trim($lines[0]));

// Data rows begin after second line (units)
$data = [];
for ($i = 2; $i < count($lines); $i++) {
    $parts = preg_split('/\s+/', trim($lines[$i]));
    if (count($parts) !== count($columns)) continue;
    $row = array_combine($columns, $parts);

    // Normalize fields
    $row['SwD'] = directionToDegrees($row['SwD']);
    $row['WWD'] = directionToDegrees($row['WWD']);
    $row['STEEPNESS'] = $row['STEEPNESS'] !== 'N/A' ? $row['STEEPNESS'] : null;

    foreach ($row as $k => &$v) {
        if (in_array($k, ['SwD', 'WWD', 'MWD']) && $v !== null) {
            $v = (int) $v;
        } elseif (is_numeric($v)) {
            $v = strpos($v, '.') !== false ? (float) $v : (int) $v;
        } elseif ($v === 'N/A') {
            $v = null;
        }
    }

    $data[] = $row;
}

header('Content-Type: application/json');
echo json_encode([
    'station' => $station,
    'columns' => $columns,
    'count'   => count($data),
    'data'    => $data
], JSON_PRETTY_PRINT);
