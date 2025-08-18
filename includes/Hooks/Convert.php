<?php

namespace Legenda\NormalSurf\Hooks;

class Convert
{
    //convert UTC to users time
    public static function UTC_time(string $tz = 'America/New_York'): string
    {
        $userTz = new \DateTimeZone($tz);
        $now = new \DateTime('now', $userTz);
        $now->setTimezone(new \DateTimeZone('UTC'));
        return $now->format('Y-m-d H:i:00');
    }
    //convert UTC to east coast time
    // in Legenda\NormalSurf\Hooks\Convert
    public static function toLocalTime(string $utcTime, string $tz = 'America/New_York'): string
    {
        $dt = new \DateTime($utcTime, new \DateTimeZone('UTC'));
        $dt->setTimezone(new \DateTimeZone($tz));
        // Example: "Thursday, August 8, 4:05 PM"
        return $dt->format('l, F j, g:i A');
    }

    //convert meters to feet
    public static function metersToFeet(float $meters, int $precision = 2): float
    {
        return round($meters * 3.28084, $precision);
    }
}
