<?php
namespace Legenda\NormalSurf\Services;

final class PeriodService
{
    // Same logic as your computeDominantPeriod()
    public static function computeDominantPeriod(array $d): ?float
    {
        if (!isset($d['SwH'],$d['WWH'],$d['SwP'],$d['WWP'])) return null;

        $swH = round((float)$d['SwH'], 1);
        $wwH = round((float)$d['WWH'], 1);
        $swP = (float)$d['SwP'];
        $wwP = (float)$d['WWP'];

        $dp = ($swH === $wwH) ? (($swP + $wwP) / 2) : (($swH > $wwH) ? $swP : $wwP);
        return round($dp, 1);
    }
}
