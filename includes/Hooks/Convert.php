<?php
namespace Legenda\NormalSurf\Hooks;

class Convert
{
    public static function UTC_time(string $tz = 'America/New_York'): string
    {
        $userTz = new \DateTimeZone($tz);
        $now = new \DateTime('now', $userTz);
        $now->setTimezone(new \DateTimeZone('UTC'));
        return $now->format('Y-m-d H:i:00');
    }
}
