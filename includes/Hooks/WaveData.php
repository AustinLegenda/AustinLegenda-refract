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
}