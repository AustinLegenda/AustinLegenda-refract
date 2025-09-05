<?php
declare(strict_types=1);

$ROOT = dirname(__DIR__, 2); // /Applications/MAMP/htdocs/normal-surf

// Load config and dependencies IN THIS ORDER
require_once $ROOT . '/config.php';
require_once dirname(__DIR__) . '/API/NoaaRequest.php';        // <- add this
require_once __DIR__ . '/SpectralDataParser.php';
require_once dirname(__DIR__) . '/Repositories/WaveBuoyRepo.php';
require_once dirname(__DIR__) . '/Hooks/LoadData.php';

use Legenda\NormalSurf\Hooks\LoadData;

$stations = ['41112', '41117'];
$exitCode = 0;

foreach ($stations as $stn) {
    echo "[normal-surf] station {$stn}: begin\n";
    try {
        $summary  = LoadData::conn_report($stn);
        $inserted = $summary['inserted'] ?? ($summary[$stn]['inserted'] ?? null);
        echo "[normal-surf] station {$stn}: inserted=" . (is_null($inserted) ? 'unknown' : $inserted) . "\n";
    } catch (\Throwable $e) {
        $exitCode = 1;
        echo "[normal-surf] EXCEPTION station {$stn}: {$e->getMessage()} at {$e->getFile()}:{$e->getLine()}\n";
    }
    echo "[normal-surf] station {$stn}: end\n";
}

exit($exitCode);
