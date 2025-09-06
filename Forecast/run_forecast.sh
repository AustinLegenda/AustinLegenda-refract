<?php
declare(strict_types=1);

// Paths
$appRoot = dirname(__DIR__, 2);          // app root
$forecastDir = $appRoot . '/private_html/Forecast';
$logDir = $appRoot . '/logs';
@mkdir($logDir, 0775, true);
$logFile = $logDir . '/forecast_' . gmdate('Ymd') . '.log';

// Build command to kick your runner (background so HTTP returns fast)
$cmd = sprintf('(cd %s && bash -lc %s) >> %s 2>&1 & echo $!',
    escapeshellarg($forecastDir),
    escapeshellarg('./run_forecast.sh'),
    escapeshellarg($logFile)
);

$disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
if (!in_array('shell_exec', $disabled, true)) {
    shell_exec($cmd);
    echo "Spawned\n";
} else {
    file_put_contents($logFile, "[".gmdate('c')."] shell_exec disabled; cannot run run_forecast.sh\n", FILE_APPEND);
    echo "shell_exec disabled\n";
}
