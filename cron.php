<?php

namespace Legenda\NormalSurf\Hooks;

use Legenda\NormalSurf\Hooks\ReportLoader;

class Cron
{
    public static function register(): void
    {
        add_action('wp', [__CLASS__, 'schedule_event']);
        add_action('nrml_conn_report_event', [LoadData::class, 'conn_report']);
    }

    public static function schedule_event(): void
    {
        if (!wp_next_scheduled('nrml_conn_report_event')) {
            wp_schedule_event(time(), 'hourly', 'nrml_conn_report_event');
        }
    }
}
