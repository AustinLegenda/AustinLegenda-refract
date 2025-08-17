<?php
namespace Legenda\NormalSurf\Services;

final class Geo
{
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
}
