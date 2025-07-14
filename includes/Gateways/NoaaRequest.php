<?php
namespace Legenda\NormalSurf\Gateways;

class NoaaRequest
{
    public static function fetch_raw_spec(string $station): array
    {
        $url = "https://www.ndbc.noaa.gov/data/realtime2/{$station}.spec";
        $lines = @file($url, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if (!$lines) {
            throw new \Exception("Unable to fetch .spec for station {$station}");
        }

        return $lines;
    }
}
