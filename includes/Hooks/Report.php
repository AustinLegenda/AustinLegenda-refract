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
}

//Load all data (50)
//$colsList = implode(',', $dataCols);
//$stmtLatest = $pdo->query("SELECT ts, {$colsList} FROM wave_data ORDER BY ts DESC LIMIT 50");
//$latest = $stmtLatest->fetchAll(PDO::FETCH_ASSOC);