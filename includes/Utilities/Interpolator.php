<?php
namespace Legenda\NormalSurf\Utilities;

use PDO;
use Legenda\NormalSurf\Hooks\Convert;
use Legenda\NormalSurf\Helpers\Maths;
use Legenda\NormalSurf\Repositories\WaveForecastRepo;

final class Interpolator
{
    // Same signature & columns as your current interpolate_midpoint_row()
    public static function interpolateMidpointRow(array $data1, array $data2, array $distances): array
    {
        $columns = ['ts','WVHT','SwH','SwP','WWH','WWP','SwD','WWD','APD','MWD','STEEPNESS'];

        $dist1 = (float)($distances['dist_41112'] ?? 1);
        $dist2 = (float)($distances['dist_41117'] ?? 1);

        $inv1 = 1 / ($dist1 + 0.01);
        $inv2 = 1 / ($dist2 + 0.01);
        $sum  = $inv1 + $inv2;
        $w1   = $inv1 / $sum;
        $w2   = $inv2 / $sum;

        $mid = [];
        foreach ($columns as $col) {
            if ($col === 'ts') { $mid[$col] = '—'; continue; }
            if ($col === 'MWD') {
                $mid[$col] = Maths::circularAverage(
                    [$data1['MWD'] ?? 0, $data2['MWD'] ?? 0],
                    [$w1, $w2]
                );
                continue;
            }
            $v1 = is_numeric($data1[$col] ?? null) ? (float)$data1[$col] : null;
            $v2 = is_numeric($data2[$col] ?? null) ? (float)$data2[$col] : null;
            $mid[$col] = ($v1 !== null && $v2 !== null) ? $v1 * $w1 + $v2 * $w2 : '—';
        }
        return $mid;
    }

public static function interpolateForecastForSpot(PDO $pdo, array $spot, string $targetUtc, array $stationCoords, array $stationIds = ['41112','41117']): array
    {
        // Fetch the most recent forecast sample at-or-before $targetUtc per station
        // (repo's getPrev() is strictly < now, so we'll bump by a minute to allow exact hits)
        $plusOne = (new \DateTime($targetUtc, new \DateTimeZone('UTC')))->modify('+1 minute')->format('Y-m-d H:i:00');

        $samples = [];
        foreach ($stationIds as $sid) {
            $row = WaveForecastRepo::getPrev($pdo, $sid, $plusOne);
            if ($row) $samples[$sid] = $row; // expects hs_m, per_s, dir_deg
        }
        if (!$samples) {
            return ['hs_m'=>null,'per_s'=>null,'dir_deg'=>null,'weights'=>[],'ts_utc'=>$targetUtc];
        }

        // Distance weights using Utilities\Maths
        $lat = $spot['spot_lat']  ?? $spot['region_lat'] ?? null;
        $lon = $spot['spot_lon']  ?? $spot['region_lon'] ?? null;

        $weights = [];
        if ($lat === null || $lon === null) {
            $w = 1.0 / max(count($samples), 1);
            foreach ($samples as $sid => $_) $weights[$sid] = $w;
        } else {
            $inv = [];
            foreach ($samples as $sid => $_) {
                if (!isset($stationCoords[$sid])) continue;
                $d = Maths::haversine((float)$lat, (float)$lon, (float)$stationCoords[$sid]['lat'], (float)$stationCoords[$sid]['lon']);
                $inv[$sid] = 1.0 / max($d, 1e-6);
            }
            $sum = array_sum($inv) ?: 1.0;
            foreach ($inv as $sid => $v) $weights[$sid] = $v / $sum;
        }

        // Linear for hs & per, circular mean for dir
        $hs=0.0; $per=0.0; $angs=[]; $wts=[];
        foreach ($samples as $sid => $row) {
            $w = $weights[$sid] ?? 0.0;
            if (is_numeric($row['hs_m'] ?? null))  $hs  += $w * (float)$row['hs_m'];
            if (is_numeric($row['per_s'] ?? null)) $per += $w * (float)$row['per_s'];
            if (is_numeric($row['dir_deg'] ?? null)) {
                $angs[] = (float)$row['dir_deg'];
                $wts[]  = $w;
            }
        }
        $dir = $angs ? Maths::circularAverage($angs, $wts) : null;

        return [
            'hs_m'   => $hs ?: null,
            'per_s'  => $per ?: null,
            'dir_deg'=> $dir,
            'weights'=> $weights,
            'ts_utc' => $targetUtc,
        ];
    }
     public static function combineForecast(array $samples, array $weights): array
    {
        $hs = 0.0; $per = 0.0; $angles = []; $wts = [];

        foreach ($samples as $sid => $s) {
            $w = (float)($weights[$sid] ?? 0.0);
            if (is_numeric($s['hs_m']  ?? null)) $hs  += $w * (float)$s['hs_m'];
            if (is_numeric($s['per_s'] ?? null)) $per += $w * (float)$s['per_s'];
            if (is_numeric($s['dir_deg'] ?? null)) { $angles[] = (float)$s['dir_deg']; $wts[] = $w; }
        }

        $dir = $angles ? Maths::circularAverage($angles, $wts) : null;

        return [
            'hs_m'    => $hs ?: null,
            'per_s'   => $per ?: null,
            'dir_deg' => $dir,
        ];
    }
}
