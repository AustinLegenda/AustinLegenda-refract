<?php
namespace Legenda\NormalSurf\Repositories;

use Legenda\NormalSurf\API\NoaaWebhook;

class NoaaRepository
{
    public static function get_data(string $station = '41112'): array
    {
        $transient_key = "noaa_spec_{$station}";
        $cached = get_transient($transient_key);

        if ($cached !== false) return $cached;

        try {
            $data = NoaaWebhook::fetch_and_parse($station);
            set_transient($transient_key, $data, 30 * MINUTE_IN_SECONDS);
            return $data;
        } catch (\Exception $e) {
            error_log($e->getMessage());
            return ['columns' => [], 'data' => []];
        }
    }
}
