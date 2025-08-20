<?php
declare(strict_types=1);

namespace Legenda\NormalSurf\Utilities;

final class WindPreference
{
    /**
     * Returns true if wind is >= ±90° away from spot_angle.
     * spot_angle: your canonical spot facing (deg TRUE).
     * wind_dir: meteorological "from" direction (deg TRUE).
     */
    public static function isPreferred(?int $wind_dir, ?int $spot_angle): bool
    {
        if ($wind_dir === null || $spot_angle === null) return false;
        $d = self::deltaDeg($wind_dir, $spot_angle);
        return abs($d) >= 90.0;
    }

    /**
     * Smallest signed angular difference wind - spot in [-180, 180].
     */
    public static function deltaDeg(int $a, int $b): float
    {
        $d = fmod(($a - $b + 540.0), 360.0) - 180.0;
        return (float)$d;
    }
}
