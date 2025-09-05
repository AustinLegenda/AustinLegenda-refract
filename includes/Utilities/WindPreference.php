<?php
declare(strict_types=1);

namespace Legenda\NormalSurf\Utilities;

use PDO;
use Legenda\NormalSurf\Hooks\WindCell;
use Legenda\NormalSurf\BatchProcessing\ImportCC;
use Legenda\NormalSurf\BatchProcessing\ImportFC;

final class WindPreference
{
    /** Map spot → wind key used elsewhere ('41112','median','41117') */
    public static function keyForSpot(array $spot, array $c12, array $c17): string
    {
        $lat = (float)$spot['region_lat'];
        $lon = (float)$spot['region_lon'];

        $midLat = ($c12['lat'] + $c17['lat']) / 2.0;
        $midLon = ($c12['lon'] + $c17['lon']) / 2.0;

        // near middle corridor → use 'median'
        $nearMid = (abs($lat - $midLat) < 0.15 && abs($lon - $midLon) < 0.20);
        if ($nearMid) return 'median';

        $d12 = self::haversineKm($lat, $lon, $c12['lat'], $c12['lon']);
        $d17 = self::haversineKm($lat, $lon, $c17['lat'], $c17['lon']);
        return ($d12 <= $d17) ? '41112' : '41117';
    }

    /** Observed wind (latest) for a key; returns ok/dir/kt/label */
    public static function realtimeForKey(PDO $pdo, string $key, ?array $spot = null): array
    {
        $map = [
            '41112'  => '8720030', // CO-OPS Fernandina
            'median' => '8720218', // CO-OPS Mayport
            '41117'  => 'SAUF1',   // NDBC St. Augustine
        ];
        $code = $map[$key] ?? null;
        if (!$code) return ['ok' => false, 'label' => '—'];

        $w = ImportCC::winds_latest($pdo, $code);
        if (!$w) return ['ok' => false, 'label' => '—'];

        $dir = isset($w['WDIR']) ? (int)$w['WDIR'] : null;
        $kt  = isset($w['WSPD_kt']) ? (float)$w['WSPD_kt'] : null;

        $ok  = self::allowForSpot($spot, $dir, $kt);
        $label = WindCell::format($dir, $kt);

        return ['ok' => $ok, 'dir' => $dir, 'kt' => $kt, 'label' => $label];
    }

    /** Forecast wind at time for a key; reads winds_fcst_{key} */
    public static function forecastForKeyAt(PDO $pdo, string $key, string $targetUtc, ?array $spot = null): array
    {
        $tbl = 'winds_fcst_' . strtolower($key);

        // nearest at/after target, else nearest before
        $stmt = $pdo->prepare("
            SELECT ts, WDIR, WSPD_kt
            FROM `{$tbl}`
            WHERE ts >= :t
            ORDER BY ts ASC
            LIMIT 1
        ");
        $stmt->execute([':t' => $targetUtc]);
        $w = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$w) {
            $stmt = $pdo->prepare("
                SELECT ts, WDIR, WSPD_kt
                FROM `{$tbl}`
                WHERE ts <= :t
                ORDER BY ts DESC
                LIMIT 1
            ");
            $stmt->execute([':t' => $targetUtc]);
            $w = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$w) return ['ok' => false, 'label' => '—'];
        }

        $dir = isset($w['WDIR']) ? (int)$w['WDIR'] : null;
        $kt  = isset($w['WSPD_kt']) ? (float)$w['WSPD_kt'] : null;

        $ok    = self::allowForSpot($spot, $dir, $kt);
        $label = WindCell::format($dir, $kt);

        return ['ok' => $ok, 'dir' => $dir, 'kt' => $kt, 'label' => $label, 'ts' => $w['ts']];
    }

    /** Blend wind into an existing wave score (optional) */
    public static function blendIntoScore(float $waveScore, array $wind): float
    {
        if (!$wind['ok']) return $waveScore + 5.0;       // hard penalty if wind fails policy
        if ($wind['kt'] !== null && $wind['kt'] <= 8)  return max(0.0, $waveScore - 0.25);
        if ($wind['kt'] !== null && $wind['kt'] <= 12) return max(0.0, $waveScore - 0.10);
        return $waveScore;
    }

    // -----------------
    // Core policy logic
    // -----------------

    /**
     * Policy:
     * - Preferred if wind direction is within ±90° of spot_angle.
     * - If NOT preferred, still OK if speed ≤ 15 kt.
     * - Else fail.
     */
    public static function allowForSpot(?array $spot, ?int $windDirDeg, ?float $speedKt): bool
    {
        if ($windDirDeg === null || $speedKt === null) {
            return false; // need both to decide
        }

        $spotAngle = isset($spot['spot_angle']) ? (float)$spot['spot_angle'] : null;
        if ($spotAngle === null) {
            // If no spot_angle in DB, fall back to speed-only tolerance
            return $speedKt <= 15.0;
        }

        $diff = self::angularDiffDeg($windDirDeg, $spotAngle); // 0..180

        $preferred = ($diff <= 90.0); // within ±90° of spot_angle
        if ($preferred) return true;

        return ($speedKt <= 15.0);
    }

    // -----------------
    // helpers
    // -----------------

    /** Smallest absolute angular difference between two bearings in degrees (0..180) */
    private static function angularDiffDeg(float $a, float $b): float
    {
        $d = fmod(($a - $b + 540.0), 360.0) - 180.0; // wrap to (-180,180]
        return abs($d);
    }

    private static function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $R = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat/2)**2 + cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin($dLon/2)**2;
        return 2 * $R * asin(min(1.0, sqrt($a)));
    }
}
