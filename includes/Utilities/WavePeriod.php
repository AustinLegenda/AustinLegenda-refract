<?php
namespace Legenda\NormalSurf\Utilities;

final class WavePeriod
{
    // Same logic as your computeDominantPeriod()
    public static function computeDominantPeriod(array $d, float $bias = 0.80): ?float
    {
        if (!isset($d['SwH'], $d['WWH'], $d['SwP'], $d['WWP'])) {
            return null;
        }

        $swH = (float)$d['SwH'];
        $wwH = (float)$d['WWH'];
        $swP = (float)$d['SwP'];
        $wwP = (float)$d['WWP'];

        if ($swH === $wwH) {
            $dp = ($swP + $wwP) / 2.0;
        } elseif ($wwH > $swH) {  
            $dp = $wwP + $bias * ($swP - $wwP);
        } else {
            $dp = $swP;
        }

        return round($dp, 1);
    }
}
