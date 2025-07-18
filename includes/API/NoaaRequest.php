<?php

namespace Legenda\NormalSurf\API;

use Legenda\NormalSurf\Hooks\SpectralDataParser;

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
            echo "<p><strong>NOAA fetch failed for station {$station}:</strong> </p>";


            try {
                $parsed = self::fetch_parsed_spec($station);
                set_transient("noaa_spec_{$station}", $parsed, 30 * MINUTE_IN_SECONDS);

                require_once dirname(__DIR__, 2) . '/config.php';
                $pdo = new \PDO(
                    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                    DB_USER,
                    DB_PASS,
                    [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
                );

                $filtered = \Legenda\NormalSurf\Hooks\SpectralDataParser::filter($parsed);
                \Legenda\NormalSurf\Hooks\LoadData::insert_data($pdo, $station, $filtered['data']);
            } catch (\Exception $e) {
                error_log("NOAA fetch failed for station {$station}: " . $e->getMessage());
            }
        }
    }
}
