<?php

namespace Legenda\NormalSurf\Hooks;

use Legenda\NormalSurf\Models\RefractionModel;

class Report
{
    /**
     * Interpolates data between stations 41112 and 41117 for each surf spot.
     *
     * @param \PDO $pdo
     * @param array $data1 - Wave data from station_41112 (must include WVHT, SwP, MWD)
     * @param array $data2 - Wave data from station_41117 (same structure)
     * @param WaveData $waveData - Instance of WaveData for AOI calculation
     * @return array $matchingSpots - Each spot enriched with interpolated and AOI values
     */
    public function station_interpolation(\PDO $pdo, array $data1, array $data2, WaveData $waveData): array
    {
        $matchingSpots = [];

        $stmtSpots = $pdo->query("SELECT id, spot_name, spot_angle, spot_lat, spot_lon FROM surf_spots");

        while ($spot = $stmtSpots->fetch(\PDO::FETCH_ASSOC)) {
            $lat = (float)$spot['spot_lat'];
            $lon = (float)$spot['spot_lon'];

            $d1 = WaveData::distanceBetween($lat, $lon, 30.709, -81.292); // 41112
            $d2 = WaveData::distanceBetween($lat, $lon, 30.681, -81.212); // 41117

            $total = $d1 + $d2;
            $w1 = 1 - ($d1 / $total);
            $w2 = 1 - $w1;

            $wvht   = ($data1['WVHT'] * $w1) + ($data2['WVHT'] * $w2);
            $period = ($data1['SwP']  * $w1) + ($data2['SwP']  * $w2);
            $mwd    = ($data1['MWD']  * $w1) + ($data2['MWD']  * $w2);

            $aoiRaw = $waveData->AOI((float)$spot['spot_angle'], $mwd);
            $aoiAdjusted = RefractionModel::safeRefractionAOI($aoiRaw, $period, $wvht);

            $spot['aoi'] = $aoiRaw;
            $spot['aoi_adjusted'] = $aoiAdjusted;
            $spot['aoi_category'] = $waveData->AOI_category($aoiAdjusted);

            $matchingSpots[] = $spot;
        }

        usort($matchingSpots, fn($a, $b) => $a['aoi_adjusted'] <=> $b['aoi_adjusted']);

        return $matchingSpots;
    }
}
