<?php
namespace Legenda\NormalSurf\BatchProcessing;

final class Helpers
{
    /** Haversine distance in km */
    public static function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $R = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat/2)**2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2)**2;
        return 2 * $R * atan2(sqrt($a), sqrt(1 - $a));
    }

    /** Circular average of angles in degrees with weights */
    public static function circularAverage(array $angles, array $weights): float
    {
        $sumSin = 0.0;
        $sumCos = 0.0;
        foreach ($angles as $i => $angle) {
            $r = deg2rad((float)$angle);
            $w = (float)($weights[$i] ?? 1.0);
            $sumSin += sin($r) * $w;
            $sumCos += cos($r) * $w;
        }
        $avg = atan2($sumSin, $sumCos);
        $deg = rad2deg($avg);
        return fmod($deg + 360.0, 360.0);
    }

    /** Weighted mean for scalars */
    public static function weightedMean(array $vals, array $weights): float
    {
        $num = 0.0; $den = 0.0;
        foreach ($vals as $i => $v) {
            if (!is_numeric($v)) continue;
            $w = (float)($weights[$i] ?? 1.0);
            $num += (float)$v * $w; $den += $w;
        }
        return $den ? $num / $den : 0.0;
    }

    /**
     * Weighted mean of times (returns epoch seconds).
     * Accepts ISO UTC strings or epoch ints in $times.
     */
    public static function weightedMeanEpoch(array $times, array $weights): int
    {
        $epochs = [];
        foreach ($times as $t) {
            if (is_int($t)) { $epochs[] = $t; continue; }
            $dt = \DateTime::createFromFormat('Y-m-d H:i:s', (string)$t, new \DateTimeZone('UTC'))
               ?: new \DateTime((string)$t, new \DateTimeZone('UTC'));
            $epochs[] = $dt->getTimestamp();
        }
        $e = self::weightedMean($epochs, $weights);
        return (int)round($e);
    }
}
