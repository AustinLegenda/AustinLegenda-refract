<?php

namespace Legenda\NormalSurf\API;

use Legenda\NormalSurf\Hooks\SpectralDataParser;
use Legenda\NormalSurf\Hooks\LoadData;

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

    public static function fetch_parsed_spec(string $station): array
    {
        $lines = self::fetch_raw_spec($station);
        return SpectralDataParser::parse($lines);
    }

    public static function register_cron()
    {
        add_action('wp', [__CLASS__, 'schedule_event']);
        add_action('nrml_refresh_noaa_data', [__CLASS__, 'refresh_data']);
    }

    public static function schedule_event()
    {
        if (!wp_next_scheduled('nrml_refresh_noaa_data')) {
            wp_schedule_event(time(), 'hourly', 'nrml_refresh_noaa_data');
        }
    }

    public static function refresh_data()
    {
        $stations = ['41112', '41117'];

        foreach ($stations as $station) {
            try {
                error_log("Refreshing data for station $station");

                // This will fetch, parse, insert, and return DB connection + latest data
                [$pdo, $station, $cols, $colsList, $table] = \Legenda\NormalSurf\Hooks\LoadData::conn_report($station);

                error_log("Insert complete for station $station");
            } catch (\Exception $e) {
                error_log("NOAA fetch failed for station {$station}: " . $e->getMessage());
            }
        }
    }
}
