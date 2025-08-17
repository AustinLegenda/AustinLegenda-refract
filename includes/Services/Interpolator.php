<?php
namespace Legenda\NormalSurf\Services;

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
                $mid[$col] = Geo::circularAverage(
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
}
