#!/usr/bin/env php
<?php
declare(strict_types=1);

use Legenda\NormalSurf\BatchProcessing\ImportFC;

// ----- lock working dir to project root -----
$root = dirname(__DIR__);           
chdir($root);

// Composer autoload from project root
require $root . '/vendor/autoload.php';

// -------- process-wide lock to avoid overlapping runs --------
$lockPath = sys_get_temp_dir() . '/ns_refresh_fc.lock';
$lock = @fopen($lockPath, 'c');
if (!$lock) {
    fwrite(STDERR, "ERR: cannot open lock file: $lockPath\n");
    exit(1);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    // another run is active; exit quietly
    fwrite(STDERR, "INFO: refresh_fc already running; exiting.\n");
    exit(0);
}

// -------- optional environment overrides --------
// TIDES_XML: path to your annual tides XML
// WAVES_DIR: directory of wave forecast JSON files
// WINDS_FCST_DEFS: JSON array like
//   [{"key":"41112","office":"JAX","x":71,"y":80},{"key":"median","office":"JAX","x":74,"y":68}]
$opts = [];
if (($p = getenv('TIDES_XML')) && is_string($p))  { $opts['tides_xml'] = $p; }
if (($p = getenv('WAVES_DIR')) && is_string($p))  { $opts['waves_dir'] = $p; }
if (($p = getenv('WINDS_FCST_DEFS')) && is_string($p)) {
    $defs = json_decode($p, true);
    if (is_array($defs)) { $opts['winds_fcst_defs'] = $defs; }
}

// ensure logs dir exists (so heartbeat can write)
if (!is_dir($root . '/logs')) {
    @mkdir($root . '/logs', 0775, true);
}

// -------- run the job --------
try {
    $out = ImportFC::refresh_all($opts);
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

    // heartbeat so you can verify cron freshness
    @file_put_contents($root . '/logs/refresh_fc.heartbeat', gmdate('c') . "\n", FILE_APPEND);
} finally {
    // release lock even if we threw
    flock($lock, LOCK_UN);
    fclose($lock);
}
