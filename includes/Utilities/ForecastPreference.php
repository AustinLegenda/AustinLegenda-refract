<?php
declare(strict_types=1);

namespace Legenda\NormalSurf\Utilities;

use PDO;
use DateTime;
use DateTimeZone;
use Legenda\NormalSurf\Repositories\TideRepo;

final class ForecastPreference
{
    public static function hlAnchoredForecastForStation(
        PDO $pdo,
        string $tideStationId,
        array $zoneCoord,
        string $startUtc,
        array $coordsMany,
        array $stationIds,
        int $hours = 72,
        int $maxRows = 12,
        ?string $windKey = null          // <— NEW
    ): array {
        $anchors = self::hlAnchors($pdo, $tideStationId, $startUtc, $hours, $maxRows);
        if (!$anchors) return [];

        // Minimal "place" stub for the forecast sampler (NO selection logic)
        $place = [
            'spot_name'  => 'zone',
            'region_lat' => (float)$zoneCoord['lat'],
            'region_lon' => (float)$zoneCoord['lon'],
        ];

        $out = [];
        foreach ($anchors as $a) {
            $tUtc = (string)$a['t_utc'];

            // waves (existing)
            $WF = WavePreference::forecastForSpot($pdo, $place, $tUtc, $coordsMany, $stationIds);
            if (empty($WF['ok'])) {
                continue; // skip if forecast sampling failed at that instant
            }

            // wind (new, optional)
            $windDir = null; $windKt = null;
            if ($windKey) {
                $w = WindPreference::forecastForKeyAt($pdo, $windKey, $tUtc, $place);
                if (!empty($w)) {
                    $windDir = $w['dir'] ?? null;
                    $windKt  = $w['kt']  ?? null;
                }
            }

            $out[] = [
                't_utc'    => $tUtc,
                'hl_type'  => (string)$a['type'],      // 'H' | 'L'
                'hs_m'     => (float)$WF['hs_m'],
                'per_s'    => (float)$WF['per_s'],
                'dir_deg'  => (float)$WF['dir_deg'],
                // NEW:
                'wind_dir' => $windDir,                // int|null
                'wind_kt'  => $windKt,                 // float|null
            ];
        }
        return $out;
    }

    public static function hlAnchors(
        PDO $pdo,
        string $tideStationId,
        string $startUtc,
        int $hours = 72,
        int $max = 12
    ): array {
        $anchors = [];
        $endTs   = (new DateTime($startUtc, new DateTimeZone('UTC')))->modify("+{$hours} hours")->getTimestamp();
        $cursor  = $startUtc;
        $guard   = 0;

        while ($guard++ < $max) {
            $rows = TideRepo::getNextHL($pdo, $tideStationId, $cursor, 1);
            if (empty($rows)) break;

            $r     = $rows[0];
            $tUtc  = (string)($r['t_utc'] ?? '');
            $type  = (string)($r['hl_type'] ?? '');
            if ($tUtc === '' || ($type !== 'H' && $type !== 'L')) break;

            $tTs = (new DateTime($tUtc, new DateTimeZone('UTC')))->getTimestamp();
            if ($tTs > $endTs) break;

            $anchors[] = ['t_utc' => $tUtc, 'type' => $type];
            $cursor    = (new DateTime($tUtc, new DateTimeZone('UTC')))->modify('+1 minute')->format('Y-m-d H:i:00');
        }
        return $anchors;
    }
}
