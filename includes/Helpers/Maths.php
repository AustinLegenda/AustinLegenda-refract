<?php
namespace Legenda\NormalSurf\Helpers;

final class Maths
{
  //convert meters to feet
    public static function metersToFeet(float $meters, int $precision = 2): float
    {
        return round($meters * 3.28084, $precision);
    }

    public static function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earth_radius = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat/2) ** 2
           + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earth_radius * $c;
    }

    public static function circularAverage(array $angles, array $weights): float
    {
        $sumSin = 0.0; $sumCos = 0.0;
        foreach ($angles as $i => $angle) {
            $r = deg2rad((float)$angle);
            $w = (float)($weights[$i] ?? 1.0);
            $sumSin += sin($r) * $w;
            $sumCos += cos($r) * $w;
        }
        $avg = atan2($sumSin, $sumCos);
        return fmod(rad2deg($avg) + 360.0, 360.0);
    }
    /** Normalize an angle to [0,360). */
    public static function normAngle(float $deg): float
    {
        $x = fmod($deg, 360.0);
        if ($x < 0) $x += 360.0;
        return $x;
    }

    /** Smallest angular distance (0..180). */
    public static function angDist(float $a, float $b): float
    {
        $d = abs(self::normAngle($a) - self::normAngle($b));
        return ($d > 180.0) ? 360.0 - $d : $d;
    }

    /** Shortest span from min to max going the short way around (0..180]. */
    public static function angSpan(float $min, float $max): float
    {
        $min = self::normAngle($min);
        $max = self::normAngle($max);
        $span = $max - $min;
        if ($span < 0) $span += 360.0;
        return min($span, 360.0 - $span);
    }

    /** Direction wrap‑safe membership (supports wrapped ranges like 350..020). */
    public static function dirInRange(float $deg, float $min, float $max): bool
    {
        $deg = self::normAngle($deg);
        $min = self::normAngle($min);
        $max = self::normAngle($max);
        if ($min <= $max) return ($deg >= $min && $deg <= $max);
        return ($deg >= $min || $deg <= $max); // wrapped range
    }

    /**
     * Match score for (period, direction) against row ranges.
     * Lower is better. Direction weighted lighter than period by default.
     */
    public static function matchScore(
        float $per, float $dir,
        ?float $pmin, ?float $pmax,
        ?float $dmin, ?float $dmax,
        float $dirWeight = 0.1
    ): float {
        // Period center
        $midPer = ($pmin !== null && $pmax !== null) ? ($pmin + $pmax) / 2.0 : $per;
        $perDiff = abs($per - $midPer);

        // Direction center across the short arc
        $dirDiff = 0.0;
        if ($dmin !== null && $dmax !== null) {
            $span   = self::angSpan($dmin, $dmax);
            $midDir = self::normAngle($dmin + $span / 2.0);
            $dirDiff = self::angDist($dir, $midDir);
        }

        return $perDiff + ($dirDiff * $dirWeight);
    }
}
