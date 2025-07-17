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
    public static function toLocalTime(string $utcTime, string $tz = 'America/New_York'): string
    {
        $dt = new \DateTime($utcTime, new \DateTimeZone('UTC'));
        $dt->setTimezone(new \DateTimeZone($tz));
        return $dt->format('Y-m-d H:i:s');
    }
    //convert meters to feet
       public static function metersToFeet(float $meters, int $precision = 2): float
    {
        return round($meters * 3.28084, $precision);
    }
}
