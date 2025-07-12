<?php
/**
 * Fetch the first “#YY MM DD…” header line from the .spec file
 * and return an array of column keys: ['YY','MM','DD','hh','mm','WDIR',…]
 */
function getNoaaSchema(string $station = '41112'): array
{
    $specUrl = "https://www.ndbc.noaa.gov/data/realtime2/{$station}.spec";
    $lines   = @file($specUrl, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
    if (! $lines) {
        throw new Exception("Unable to fetch spec for station {$station}");
    }

    foreach ($lines as $line) {
        // look for the header row
        if (preg_match('/^\s*#\s*YY/', $line)) {
            // drop the leading “#” and split on whitespace
            return preg_split('/\s+/', trim(substr($line, 1)));
        }
    }

    throw new Exception("Spec header not found in {$specUrl}");
}
