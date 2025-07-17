<?php

namespace Legenda\NormalSurf\Hooks;

class WaveData
{

    public function dominate_period()
    {
        $surfPeriod = null;
        $swH = $closest['SwH'] ?? 0;
        $swP = $closest['SwP'] ?? 0;
        $wwH = $closest['WWH'] ?? 0;
        $wwP = $closest['WWP'] ?? 0;

        $E_sw = ($swH * $swH) * $swP;
        $E_ww = ($wwH * $wwH) * $wwP;

        $surfPeriod = ($E_sw >= $E_ww) ? $swP : $wwP;
    }

    public function AOI(float $spotAngle, float $swellDirection): float
    {
        $diff = abs($spotAngle - $swellDirection);
        return ($diff > 180) ? 360 - $diff : $diff;
    }
}
