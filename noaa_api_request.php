<?php
/**
 * noaa_api_request.php
 *
 * Fetch and parse NDBC’s .spec file for spectral wave data.
 * Returns an array with 'columns' and 'data'.
 */

function fetchNoaaSpectralData(string $station = '41112'): array
{
    // 1) Grab the .spec file
    $url    = "https://www.ndbc.noaa.gov/data/realtime2/{$station}.spec";
    $lines  = @file($url, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
    if (!$lines) {
        throw new Exception("Unable to fetch .spec for station {$station}");
    }

    // 2) Find header row and skip the next (units) line
    $cols     = [];
    $startRow = null;
    foreach ($lines as $i => $line) {
        if (preg_match('/^\s*#\s*YY\s+MM\s+DD/', $line)) {
            // drop leading “#” then split
            $cols     = preg_split('/\s+/', trim(substr($line,1)));
            $startRow = $i + 2;  // data begins two lines later
            break;
        }
    }
    if (!$cols || $startRow === null) {
        throw new Exception("Spec header not found in {$url}");
    }

    // 3) Direction‐to‐degrees map
    $dirMap = [
        'N'=>0,'NNE'=>22,'NE'=>45,'ENE'=>67,
        'E'=>90,'ESE'=>112,'SE'=>135,'SSE'=>157,
        'S'=>180,'SSW'=>202,'SW'=>225,'WSW'=>247,
        'W'=>270,'WNW'=>292,'NW'=>315,'NNW'=>337
    ];

    // 4) Parse each data line
    $data = [];
    for ($i = $startRow; $i < count($lines); $i++) {
        $line = trim($lines[$i]);
        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }
        $vals = preg_split('/\s+/', $line);
        if (count($vals) < count($cols)) {
            continue;
        }

        // Build a UTC timestamp
        list($YY, $MM, $DD, $hh, $mn) = array_slice($vals, 0, 5);
        $ts = sprintf('%04d-%02d-%02d %02d:%02d:00', $YY, $MM, $DD, $hh, $mn);

        // Map & sanitize fields
        $row = ['ts' => $ts];
        foreach ($cols as $idx => $col) {
            $raw = $vals[$idx] ?? null;
            // N/A → null
            if ($raw === null || trim(strtoupper($raw)) === 'N/A' || $raw === '') {
                $row[$col] = null;
                continue;
            }
            // Direction columns
            if (in_array($col, ['SwD','WWD'])) {
                $row[$col] = $dirMap[$raw] ?? null;
            }
            // Steepness stays string
            elseif ($col === 'STEEPNESS') {
                $row[$col] = $raw;
            }
            // All others numeric
            else {
                $row[$col] = is_numeric($raw) ? floatval($raw) : null;
            }
        }

        $data[] = $row;
    }

    return ['columns' => $cols, 'data' => $data];
}
