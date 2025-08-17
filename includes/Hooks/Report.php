<?php

namespace Legenda\NormalSurf\Hooks;

use PDO;
use Legenda\NormalSurf\Hooks\WaveData;
use Legenda\NormalSurf\Services\SpotSelector;
use Legenda\NormalSurf\Services\TidePhaseService;
use Legenda\NormalSurf\Services\TidePreferenceEvaluator;

class Report
{
    private SpotSelector $selector;

    public function __construct()
    {
        $phase = new TidePhaseService();
        $prefs = new TidePreferenceEvaluator($phase);
        $this->selector = new SpotSelector($prefs);
    }

    // keep signature & output the same for all callers
    public function station_interpolation(PDO $pdo, array $data1, array $data2, WaveData $waveData): array
    {
        return $this->selector->select($pdo, $data1, $data2, $waveData);
    }
    public function interpolate_midpoint_row(array $a, array $b, array $d): array
    {
        return \Legenda\NormalSurf\Services\Interpolator::interpolateMidpointRow($a, $b, $d);
    }
    public function computeDominantPeriod(array $d): ?float
    {
        return \Legenda\NormalSurf\Services\PeriodService::computeDominantPeriod($d);
    }
}
