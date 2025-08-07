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
        $matchingSpots = [];

        // fetch spots with their period and direction ranges
        $stmtSpots = $pdo->query("
            SELECT
                s.id,
                s.spot_name,
                r.region_lat,
                r.region_lon,
                s.period_min,
                s.period_max,
                s.dir_min,
                s.dir_max
            FROM surf_spots AS s
            INNER JOIN regions AS r ON s.regional_id = r.id
        ");
        $spots = $stmtSpots->fetchAll(\PDO::FETCH_ASSOC);

        // fixed buoy coordinates
        $stationCoords = [
            'station_41112' => ['lat' => 30.709, 'lon' => -81.292],
            'station_41117' => ['lat' => 29.999, 'lon' => -81.079],
        ];

        foreach ($spots as $spot) {
            $lat = $spot['region_lat'];
            $lon = $spot['region_lon'];

            // distance to each station
            $dist1 = $this->haversine($lat, $lon, $stationCoords['station_41112']['lat'], $stationCoords['station_41112']['lon']);
            $dist2 = $this->haversine($lat, $lon, $stationCoords['station_41117']['lat'], $stationCoords['station_41117']['lon']);

            // inverse‐distance weights
            $inv1 = 1 / ($dist1 + 0.01);
            $inv2 = 1 / ($dist2 + 0.01);
            $sumInv = $inv1 + $inv2;
            $w1 = $inv1 / $sumInv;
            $w2 = $inv2 / $sumInv;

            // require MWD at both stations
            $mwd1 = $data1['MWD'] ?? null;
            $mwd2 = $data2['MWD'] ?? null;
            if ($mwd1 === null || $mwd2 === null) {
                continue;
            }

            // interpolated MWD
            $interpMWD = $this->circularAverage([$mwd1, $mwd2], [$w1, $w2]);


            // full midpoint row for all variables
            $mid = $this->interpolate_midpoint_row(
                $data1,
                $data2,
                ['dist_41112' => $dist1, 'dist_41117' => $dist2]
            );

            // choose dominant period by comparing wave heights
          if ($mid['SwH'] > $mid['WWH']) {
                $dominantPeriod = $mid['SwP'];
            } elseif ($mid['SwH'] < $mid['WWH']) {
                $dominantPeriod = $mid['WWP'];
            } else {
                $dominantPeriod = ($mid['SwP'] + $mid['WWP']) / 2;
            }

            $dominantPeriod = round($dominantPeriod, 2);

            // spot's allowed ranges
            $pMin = (float) $spot['period_min'];
            $pMax = (float) $spot['period_max'];
            $dMin = (float) $spot['dir_min'];
            $dMax = (float) $spot['dir_max'];

            // handle circular wrap for direction
            $dirOk = $dMin <= $dMax
                ? ($interpMWD >= $dMin && $interpMWD <= $dMax)
                : ($interpMWD >= $dMin || $interpMWD <= $dMax);

            // filter out non‐matching spots
            if (
                $dominantPeriod < $pMin ||
                $dominantPeriod > $pMax ||
                ! $dirOk
            ) {
                continue;
            }

            // record match
            $matchingSpots[] = [
                'spot_id'          => $spot['id'],
                'spot_name'        => $spot['spot_name'],
                'interpolated_mwd' => round($interpMWD, 1),
                'dominant_period'  => $dominantPeriod,
                'dist_41112'       => round($dist1, 2),
                'dist_41117'       => round($dist2, 2),
            ];
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
        $sumInv = $inv1 + $inv2;
        $w1 = $inv1 / $sumInv;
        $w2 = $inv2 / $sumInv;

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
