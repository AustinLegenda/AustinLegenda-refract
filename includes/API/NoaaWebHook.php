<?php
namespace Legenda\NormalSurf\API;

use Legenda\NormalSurf\Gateways\NoaaGateway;
use Legenda\NormalSurf\Gateways\NoaaRequest;
use Legenda\NormalSurf\Helpers\NoaaParser;
use Legenda\NormalSurf\Helpers\SpectralDataParser;

class NoaaWebhook
{
    public static function fetch_and_parse(string $station = '41112'): array
    {
        $lines = NoaaRequest::fetch_raw_spec($station);
        return SpectralDataParser::parse($lines);
    }
}

class NoaaSpectralCron
{
    public static function register()
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
        $stations = ['41112', 'Fernandian Buoy'];
        foreach ($stations as $station) {
            try {
                $data = NoaaWebhook::fetch_and_parse($station);
                set_transient("noaa_spec_{$station}", $data, 30 * MINUTE_IN_SECONDS);
            } catch (\Exception $e) {
                error_log($e->getMessage());
            }
        }
    }
}