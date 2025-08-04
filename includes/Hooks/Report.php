<?php

namespace Legenda\NormalSurf\Hooks;

use Legenda\NormalSurf\Models\RefractionModel;

class Report
{
    private function haversine($lat1, $lon1, $lat2, $lon2): float
    {
        $earth_radius = 6371; // km
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLon / 2) * sin($dLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earth_radius * $c;
    }

    private function circularAverage(array $angles, array $weights): float
    {
        $sumSin = 0.0;
        $sumCos = 0.0;

        foreach ($angles as $i => $angle) {
            $radians = deg2rad($angle);
            $sumSin += sin($radians) * $weights[$i];
            $sumCos += cos($radians) * $weights[$i];
        }

        $avgRadians = atan2($sumSin, $sumCos);
        $avgDegrees = rad2deg($avgRadians);
        return fmod($avgDegrees + 360.0, 360.0);
    }


    public function station_interpolation(\PDO $pdo, array $data1, array $data2, WaveData $waveData): array
    {
        // inside Report::station_interpolation

        $matchingSpots = [];
        $stmtSpots = $pdo->query("SELECT id, spot_name, /*spot_angle,*/ region_lat, region_lon FROM surf_spots AS INNER JOIN regions AS ON regional_id = id");
        $spots = $stmtSpots->fetchAll(\PDO::FETCH_ASSOC);

        // Buoy coordinates — adjust if needed
        $stationCoords = [
            'station_41112' => ['lat' => 34.638, 'lon' => -76.818],
            'station_41117' => ['lat' => 34.197, 'lon' => -77.792],
        ];

        foreach ($spots as $spot) {
            $regLat = $spot['region_lat'];
            $regLon = $spot['region_lon'];
           // $spotAngle = $spot['spot_angle'];

            // Calculate distances
            $dist1 = $this->haversine($regLat, $regLon, $stationCoords['station_41112']['lat'], $stationCoords['station_41112']['lon']);
            $dist2 = $this->haversine($regLat, $regLon, $stationCoords['station_41117']['lat'], $stationCoords['station_41117']['lon']);

            // Compute weights
            $inv1 = 1 / ($dist1 + 0.01);
            $inv2 = 1 / ($dist2 + 0.01);
            $total = $inv1 + $inv2;
            $weight1 = $inv1 / $total;
            $weight2 = $inv2 / $total;

            // Interpolate MWD
            $mwd1 = $data1['MWD'] ?? null;
            $mwd2 = $data2['MWD'] ?? null;

            if ($mwd1 === null || $mwd2 === null) {
                continue; // Skip if either station is missing direction data
            }

            // Basic weighted average — assumes MWD values are close (no circular averaging)
            $interpolatedMWD = $this->circularAverage([$mwd1, $mwd2], [$weight1, $weight2]);

            // Calculate AOI
            /*$adjustedAOI = RefractionModel::safeRefractionAOI($interpolatedMWD, $spotAngle, $data1['WVHT'] ?? null, $data1['APD'] ?? null);
            $aoi_category = $waveData->AOI_category($adjustedAOI);
            $longshore = $waveData->longshoreRisk($adjustedAOI);*/

            $matchingSpots[] = [
                'spot_id' => $spot['id'],
                'spot_name' => $spot['spot_name'],
                'interpolated_mwd' => round($interpolatedMWD, 1),
                //'adjusted_aoi' => round($adjustedAOI, 2),
                'dist_41112' => round($dist1, 2),
                'dist_41117' => round($dist2, 2),
                //'aoi_category' => $aoi_category,
                //'longshore' => $longshore,
            ];
        }
        if (empty($matchingSpots)) {

            exit;
        }

        return $matchingSpots;
    }

   public function interpolate_midpoint_row(array $data1, array $data2, array $distances): array
{
    $columns = ['ts', 'WVHT', 'SwH', 'SwP', 'WWH', 'WWP', 'SwD', 'WWD', 'APD', 'MWD', 'STEEPNESS'];

    $dist1 = $distances['dist_41112'] ?? 1;
    $dist2 = $distances['dist_41117'] ?? 1;

    $inv1 = 1 / ($dist1 + 0.01);
    $inv2 = 1 / ($dist2 + 0.01);
    $total = $inv1 + $inv2;
    $w1 = $inv1 / $total;
    $w2 = $inv2 / $total;

    $mid = [];

    foreach ($columns as $col) {
        if ($col === 'ts') {
            $mid[$col] = '—';
        } elseif ($col === 'MWD') {
            $mid[$col] = $this->circularAverage(
                [$data1['MWD'] ?? 0, $data2['MWD'] ?? 0],
                [$w1, $w2]
            );
        } else {
            $v1 = is_numeric($data1[$col] ?? null) ? $data1[$col] : null;
            $v2 = is_numeric($data2[$col] ?? null) ? $data2[$col] : null;
            $mid[$col] = ($v1 !== null && $v2 !== null)
                ? $v1 * $w1 + $v2 * $w2
                : '—';
        }
    }

    return $mid;
}

}
