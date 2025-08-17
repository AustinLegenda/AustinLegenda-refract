<?php

namespace Legenda\NormalSurf\Services;

use PDO;
use Legenda\NormalSurf\Hooks\Convert;
use Legenda\NormalSurf\Hooks\WaveData;


final class SpotSelector
{
    public function __construct(private TidePreferenceEvaluator $tidePrefs) {}

    public function select(PDO $pdo, array $data1, array $data2, WaveData $waveData): array
    {
        $rows = [];

        $stmtSpots = $pdo->query("
            SELECT
                s.id, s.spot_name,
                r.region_lat, r.region_lon,
                s.period_min, s.period_max,
                s.dir_min, s.dir_max,
                s.H_tide, s.M_plus_tide, s.M_minus_tide, s.L_tide
            FROM surf_spots AS s
            INNER JOIN regions AS r ON s.regional_id = r.id
        ");
        $spots = $stmtSpots->fetchAll(\PDO::FETCH_ASSOC);

        $stationCoords = [
            'station_41112' => ['lat' => 30.709, 'lon' => -81.292],
            'station_41117' => ['lat' => 29.999, 'lon' => -81.079],
        ];

        $nowUtc = Convert::UTC_time();

        foreach ($spots as $spot) {
            $lat = (float)$spot['region_lat'];
            $lon = (float)$spot['region_lon'];

            $dist1 = Geo::haversine($lat, $lon, $stationCoords['station_41112']['lat'], $stationCoords['station_41112']['lon']);
            $dist2 = Geo::haversine($lat, $lon, $stationCoords['station_41117']['lat'], $stationCoords['station_41117']['lon']);

            $inv1 = 1 / ($dist1 + 0.01);
            $inv2 = 1 / ($dist2 + 0.01);
            $sum = $inv1 + $inv2;
            $w1 = $inv1 / $sum;
            $w2 = $inv2 / $sum;

            $mwd1 = $data1['MWD'] ?? null;
            $mwd2 = $data2['MWD'] ?? null;
            if ($mwd1 === null || $mwd2 === null) continue;

            $interpMWD = Geo::circularAverage([$mwd1, $mwd2], [$w1, $w2]);

            $mid = Interpolator::interpolateMidpointRow(
                $data1,
                $data2,
                ['dist_41112' => $dist1, 'dist_41117' => $dist2]
            );

            $wvhtMeters = is_numeric($mid['WVHT'] ?? null) ? (float)$mid['WVHT'] : null;

            $dominantPeriod = \Legenda\NormalSurf\Services\PeriodService::computeDominantPeriod($mid);
            if ($dominantPeriod === null) continue;

            $pMin = (float)$spot['period_min'];
            $pMax = (float)$spot['period_max'];
            $dMin = (float)$spot['dir_min'];
            $dMax = (float)$spot['dir_max'];

            $dirOk = $dMin <= $dMax
                ? ($interpMWD >= $dMin && $interpMWD <= $dMax)
                : ($interpMWD >= $dMin || $interpMWD <= $dMax);

            if ($dominantPeriod < $pMin || $dominantPeriod > $pMax || !$dirOk) continue;

            $tp = $this->tidePrefs->tidePrefMatch($pdo, $spot, $nowUtc, 60);

            // ---- Decide List 1 vs List 2 + compose notes ----
            $listBucket = '2';
            $tideNote   = null;

            // has tide prefs?
            $hasPrefs = !empty($spot['H_tide']) || !empty($spot['M_plus_tide']) || !empty($spot['M_minus_tide']) || !empty($spot['L_tide']);

            if ($hasPrefs) {
                if (!empty($tp['ok'])) {
                    // Ideal: all true, show the exact matching reason/time
                    $listBucket = '1';
                    $phase = $tp['tide_reason'] ?? null;         // 'H' | 'L' | 'M+' | 'M-'
                    $time  = $tp['tide_reason_time'] ?? null;    // local str
                    if ($phase && $time) {
                        $tideNote = "{$phase} @ {$time}";
                    }
                    $periodNote = "Period: {$dominantPeriod} s";
                    $dirNote    = "Dir: " . round($interpMWD, 0) . "°";
                    if ($tideNote) {
                        $parenthetical = "({$periodNote}, {$dirNote}, Tide: {$tideNote})";
                    }
                } else {
                    // Optional: preferences exist but not within 60 min; show next preferred
                    $phase = $tp['next_pref'] ?? null;
                    $time  = $tp['next_pref_time'] ?? null;
                    if ($phase && $time) {
                        $tideNote = "next preferred {$phase} @ {$time}";
                    }
                }
            } else {
                // Optional: no prefs; surface the neutral next tide marker
                $phase = $tp['next_marker'] ?? null;
                $time  = $tp['next_marker_time'] ?? null;
                if ($phase && $time) {
                    $tideNote = "next tide {$phase} @ {$time}";
                }
            }

            // ---- Build row (existing fields) ----
            $rows[] = [
                'spot_id'          => $spot['id'],
                'spot_name'        => $spot['spot_name'],
                'interpolated_mwd' => round($interpMWD, 1),
                'dominant_period'  => $dominantPeriod,
                'dist_41112'       => round($dist1, 2),
                'dist_41117'       => round($dist2, 2),

                'WVHT'             => $wvhtMeters,

                'tide_ok'                => (bool)($tp['ok'] ?? false),
                'tide_reason'            => $tp['tide_reason'] ?? null,
                'tide_reason_time'       => $tp['tide_reason_time'] ?? null,
                'tide'                   => $tp['tide'] ?? null,

                'has_tide_prefs'         => $hasPrefs,
                'next_pref'              => $tp['next_pref'] ?? null,
                'next_pref_time'         => $tp['next_pref_time'] ?? null,
                'next_marker'            => $tp['next_marker'] ?? null,
                'next_marker_time'       => $tp['next_marker_time'] ?? null,

                // NEW:
                'list_bucket'            => $listBucket,            // '1' or '2'
                'tide_note'              => $tideNote,              // string ready for display
            ];
        }
        return $rows;
    }
}
