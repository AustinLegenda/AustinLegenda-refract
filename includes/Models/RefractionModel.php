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

    public static function isValidShoalDepth(float $waveHeight, float $depth): bool
    {
        return $depth > ($waveHeight * 1.3);
    }

    /**
     * Calculate depth ratio (c2 / c1) used in Snell's Law.
     * @param float $period         Deep water wave period (s)
     * @param float $nearshoreDepth Approximate depth at break (m)
     */
    public static function depthRatio(float $period, float $nearshoreDepth = 1.5): float
    {
        $c1 = self::deepWaterSpeed($period);
        $c2 = self::shallowWaterSpeed($nearshoreDepth);

        return $c2 / $c1;
    }

    /**
     * Calculate refracted angle of incidence using Snell’s Law.
     * @param float $aoiDegrees  Angle of incidence in degrees
     * @param float $period      Wave period in seconds
     * @param float $depth       Nearshore depth in meters
     * @return float|null        Refracted angle in degrees, or null if total reflection
     */
    public static function refractedAOI(float $aoiDegrees, float $period, float $depth = 1.5): ?float
    {
        $c1 = self::deepWaterSpeed($period);
        $c2 = self::shallowWaterSpeed($depth);

        $theta1 = deg2rad($aoiDegrees);
        $sinTheta2 = (sin($theta1) * $c2) / $c1;

        if (abs($sinTheta2) > 1) {
            return null; // total internal reflection (rare in water)
        }

        return rad2deg(asin($sinTheta2));
    }
}
