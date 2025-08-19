<?php

declare(strict_types=1);

namespace Legenda\NormalSurf\Utilities;

use PDO;
use Legenda\NormalSurf\Helpers\Maths;
use Legenda\NormalSurf\Utilities\WavePeriod;
use Legenda\NormalSurf\Utilities\Interpolator;

final class WavePreference
{
    /**
     * Realtime evaluation using two station rows.
     * Returns presentation-free data + gating reason.
     */
    public static function realtimeForSpot(
        array $spot,
        array $data1,
        array $data2,
        array $c12,         // ['lat'=>float, 'lon'=>float]
        array $c17          // ['lat'=>float, 'lon'=>float]
    ): array {
        // region lat/lon for this spot
        $lat = isset($spot['region_lat']) ? (float)$spot['region_lat'] : null;
        $lon = isset($spot['region_lon']) ? (float)$spot['region_lon'] : null;
        if ($lat === null || $lon === null) {
            return ['ok' => false, 'gate_reason' => 'missing_region_coords'];
        }

        // distances and weights
        $dist1 = Maths::haversine($lat, $lon, (float)$c12['lat'], (float)$c12['lon']);
        $dist2 = Maths::haversine($lat, $lon, (float)$c17['lat'], (float)$c17['lon']);
        $inv1  = 1 / ($dist1 + 0.01);
        $inv2  = 1 / ($dist2 + 0.01);
        $sum   = $inv1 + $inv2;
        $w1    = $inv1 / $sum;
        $w2    = $inv2 / $sum;

        // need MWDs
        $mwd1 = $data1['MWD'] ?? null;
        $mwd2 = $data2['MWD'] ?? null;
        if ($mwd1 === null || $mwd2 === null) {
            return ['ok' => false, 'gate_reason' => 'missing_mwd'];
        }

        $interpMWD = Maths::circularAverage([(float)$mwd1, (float)$mwd2], [$w1, $w2]);

        // “midpoint row” for other metrics
        $mid = Interpolator::interpolateMidpointRow(
            $data1,
            $data2,
            ['dist_41112' => $dist1, 'dist_41117' => $dist2]
        );

        $hs_m = is_numeric($mid['WVHT'] ?? null) ? (float)$mid['WVHT'] : null;

        // dominant period
        $dominantPeriod = WavePeriod::computeDominantPeriod($mid);
        if ($dominantPeriod === null) {
            return ['ok' => false, 'gate_reason' => 'missing_period'];
        }

        // range checks
        $pMin = isset($spot['period_min']) ? (float)$spot['period_min'] : null;
        $pMax = isset($spot['period_max']) ? (float)$spot['period_max'] : null;
        $dMin = isset($spot['dir_min'])    ? (float)$spot['dir_min']    : null;
        $dMax = isset($spot['dir_max'])    ? (float)$spot['dir_max']    : null;

        $dirOk = ($dMin !== null && $dMax !== null)
            ? Maths::dirInRange($interpMWD, $dMin, $dMax)
            : true;

        if (($pMin !== null && $dominantPeriod < $pMin) ||
            ($pMax !== null && $dominantPeriod > $pMax)) {
            return ['ok' => false, 'gate_reason' => 'period_out_of_range'];
        }
        if (!$dirOk) {
            return ['ok' => false, 'gate_reason' => 'direction_out_of_range'];
        }

        return [
            'ok'                 => true,
            'gate_reason'        => null,
            // core wave fields
            'hs_m'               => $hs_m,
            'per_s'              => (float)$dominantPeriod,
            'dir_deg'            => (float)$interpMWD,
            // extras for debug
            'interpolated_mwd'   => round($interpMWD, 1),
            'dominant_period'    => (float)$dominantPeriod,
            'dist_41112'         => round($dist1, 2),
            'dist_41117'         => round($dist2, 2),
        ];
    }

    /**
     * Forecast evaluation wrapper. Uses Interpolator and performs
     * range checks. Returns presentation-free data + gating reason.
     */
    public static function forecastForSpot(
        PDO $pdo,
        array $spot,
        string $targetUtc,
        array $coords,          // from StationRepo->coordsMany(['41112','41117'])
        array $stationIds       // e.g., ['41112','41117']
    ): array {
        $F = Interpolator::interpolateForecastForSpot($pdo, $spot, $targetUtc, $coords, $stationIds);

        if ($F['per_s'] === null) {
            return ['ok' => false, 'gate_reason' => 'forecast_missing_period'];
        }
        if ($F['dir_deg'] === null) {
            return ['ok' => false, 'gate_reason' => 'forecast_missing_direction'];
        }

        $pmin = isset($spot['period_min']) ? (float)$spot['period_min'] : null;
        $pmax = isset($spot['period_max']) ? (float)$spot['period_max'] : null;
        $dmin = isset($spot['dir_min'])    ? (float)$spot['dir_min']    : null;
        $dmax = isset($spot['dir_max'])    ? (float)$spot['dir_max']    : null;

        $okPer = ($pmin !== null && $pmax !== null) ? ($F['per_s'] >= $pmin && $F['per_s'] <= $pmax) : true;
        $okDir = ($dmin !== null && $dmax !== null) ? Maths::dirInRange((float)$F['dir_deg'], $dmin, $dmax) : true;
        if (!$okPer) {
            return ['ok' => false, 'gate_reason' => 'forecast_period_out_of_range'];
        }
        if (!$okDir) {
            return ['ok' => false, 'gate_reason' => 'forecast_direction_out_of_range'];
        }

        return [
            'ok'       => true,
            'gate_reason' => null,
            'hs_m'     => $F['hs_m'],
            'per_s'    => $F['per_s'],
            'dir_deg'  => $F['dir_deg'],
            // retain raw timing for the caller
            'target_utc' => $targetUtc,
        ];
    }
}
