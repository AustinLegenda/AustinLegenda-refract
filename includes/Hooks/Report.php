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

    public function station_interpolation(\PDO $pdo, array $data1, array $data2, WaveData $waveData): array
    {
        $matchingSpots = [];

        $stmtSpots = $pdo->query("SELECT id, spot_name, spot_angle, spot_lat, spot_lon FROM surf_spots");
        $spots = $stmtSpots->fetchAll(\PDO::FETCH_ASSOC);

        // Buoy coordinates — adjust if needed
        $stationCoords = [
            'station_41112' => ['lat' => 34.638, 'lon' => -76.818],
            'station_41117' => ['lat' => 34.197, 'lon' => -77.792],
        ];

        foreach ($spots as $spot) {
            $spotLat = $spot['spot_lat'];
            $spotLon = $spot['spot_lon'];
            $spotAngle = $spot['spot_angle'];

            // Calculate distances
            $dist1 = $this->haversine($spotLat, $spotLon, $stationCoords['station_41112']['lat'], $stationCoords['station_41112']['lon']);
            $dist2 = $this->haversine($spotLat, $spotLon, $stationCoords['station_41117']['lat'], $stationCoords['station_41117']['lon']);

            // Compute weights
            $inv1 = 1 / ($dist1 + 0.01);
            $inv2 = 1 / ($dist2 + 0.01);
            $total = $inv1 + $inv2;
            $weight1 = $inv1 / $total;
            $weight2 = $inv2 / $total;

            // Interpolate MWD
            $mwd1 = $data1['mwd'] ?? null;
            $mwd2 = $data2['mwd'] ?? null;

            if ($mwd1 === null || $mwd2 === null) {
                continue; // Skip if either station is missing direction data
            }

            // Basic weighted average — assumes MWD values are close (no circular averaging)
            $interpolatedMWD = $mwd1 * $weight1 + $mwd2 * $weight2;

            // Calculate AOI
            $adjustedAOI = RefractionModel::safeRefractionAOI($interpolatedMWD, $spotAngle, $data1['wvht'] ?? null, $data1['per'] ?? null);

            $matchingSpots[] = [
                'spot_id' => $spot['id'],
                'spot_name' => $spot['spot_name'],
                'interpolated_mwd' => round($interpolatedMWD, 1),
                'adjusted_aoi' => round($adjustedAOI, 2),
                'dist_41112' => round($dist1, 2),
                'dist_41117' => round($dist2, 2),
            ];
        }

        return $matchingSpots;
    }
}
