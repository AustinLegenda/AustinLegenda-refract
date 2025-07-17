<?php

namespace Legenda\NormalSurf\Models;

class RefractionModel
{
    const GRAVITY = 9.81;

    /**
     * Estimate wave speed in deep water based on wave period (in seconds).
     */
    public static function deepWaterSpeed(float $period): float
    {
        return (self::GRAVITY * $period) / (2 * M_PI);
    }

    /**
     * Estimate wave speed in shallow water based on depth (in meters).
     */
    public static function shallowWaterSpeed(float $depth): float
    {
        return sqrt(self::GRAVITY * $depth);
    }

    /**
     * Check if depth is valid (i.e., deeper than 1.3 × wave height).
     */
    public static function isValidShoalDepth(float $waveHeight, float $depth): bool
    {
        return $depth > ($waveHeight * 1.3);
    }

    /**
     * Calculate refracted AOI, using Snell’s Law, or fallback to original AOI.
     * @param float $aoiDegrees      Angle of incidence (degrees)
     * @param float $period          Wave period (seconds)
     * @param float $waveHeight      Wave height (meters)
     * @param float|null $depth      Nearshore depth (meters), optional
     * @return float Refracted AOI or original AOI if invalid
     */
    public static function safeRefractionAOI(
        float $aoiDegrees,
        float $period,
        float $waveHeight,
        ?float $depth = null
    ): float {
        $depth = $depth ?? max($waveHeight * 1.5, 4.0); // fallback safe depth

        if (!self::isValidShoalDepth($waveHeight, $depth)) {
            return $aoiDegrees;
        }

        $c1 = self::deepWaterSpeed($period);
        $c2 = self::shallowWaterSpeed($depth);

        $theta1 = deg2rad($aoiDegrees);
        $sinTheta2 = (sin($theta1) * $c2) / $c1;

        if (abs($sinTheta2) > 1) {
            return $aoiDegrees; // fallback to original if total reflection
        }

        return rad2deg(asin($sinTheta2));
    }
}
