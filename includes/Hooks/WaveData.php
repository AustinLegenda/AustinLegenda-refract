<?php

namespace Legenda\NormalSurf\Hooks;

class WaveData
{
    /**
     * Calculate Angle of Incidence between swell direction and spot normal
     */
    public function AOI(float $spotAngle, float $swellDirection): float
    {
        $diff = abs($spotAngle - $swellDirection);
        return ($diff > 180) ? 360 - $diff : $diff;
    }

    /**
     * Categorize AOI for surf behavior
     */
    public function AOI_category(float $aoi): string
    {
        if ($aoi < 4) return 'Too Straight (Closeout)';
        if ($aoi < 15) return 'Good Lines';
        if ($aoi < 30) return 'Feathered';
        return 'Too Angled / Wrapped';
    }
public function longshoreRisk(float $aoi): string
{
    if ($aoi > 60) return 'Severe';
    if ($aoi > 45) return 'Strong';
    if ($aoi > 30) return 'Moderate';
    return 'Low';
}

/**
 * Calculate the great-circle distance between two lat/lon points (in kilometers).
 *
 * @param float $lat1
 * @param float $lon1
 * @param float $lat2
 * @param float $lon2
 * @return float
 */
public static function distanceBetween(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $earthRadius = 6371; // kilometers

    $lat1Rad = deg2rad($lat1);
    $lon1Rad = deg2rad($lon1);
    $lat2Rad = deg2rad($lat2);
    $lon2Rad = deg2rad($lon2);

    $deltaLat = $lat2Rad - $lat1Rad;
    $deltaLon = $lon2Rad - $lon1Rad;

    $a = sin($deltaLat / 2) ** 2
       + cos($lat1Rad) * cos($lat2Rad) * sin($deltaLon / 2) ** 2;

    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earthRadius * $c;
}


}