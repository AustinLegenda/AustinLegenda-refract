<?php

namespace Legenda\NormalSurf\Repositories;

use Legenda\NormalSurf\API\NoaaRequest;

// Fallbacks for non-WordPress environments
if (!function_exists('get_transient')) {
    function get_transient($key)
    {
        return false; // bypass cache in dev
    }

    function set_transient($key, $value, $expire)
    {
        return true;
    }

    if (!defined('MINUTE_IN_SECONDS')) {
        define('MINUTE_IN_SECONDS', 60);
    }
}
/**
 * cron job
 * */

class NoaaRepository
{
    public static function get_data(string $station = '41112'): array
    {
        $key = "noaa_spec_{$station}";
        $cached = get_transient($key);

        if ($cached !== false) {
            return $cached;
        }

        try {
            $data = NoaaRequest::fetch_parsed_spec($station);
            set_transient($key, $data, 30 * MINUTE_IN_SECONDS);
            return $data;
        } catch (\Exception $e) {
            error_log("NOAA Repository Error: " . $e->getMessage());
            return ['columns' => [], 'data' => []];
        }
    }
}
