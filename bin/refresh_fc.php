#!/usr/bin/env php
<?php
declare(strict_types=1);

use Legenda\NormalSurf\BatchProcessing\ImportFC;

// Allow both CLI and web. If web, just run.
$root = dirname(__DIR__, 2); // /public_html/cron -> app root
chdir($root);

require $root . '/vendor/autoload.php';

// Process-wide lock so multiple hits don't overlap
$lockPath = sys_get_temp_dir() . '/ns_refresh_fc.lock';
$lock = @fopen($lockPath, 'c');
if (!$lock) {
    fwrite(STDERR, "ERR: cannot open lock file: $lockPath\n");
    exit(1);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    // already running; exit quietly
    if (php_sapi_name() !== 'cli') echo "Already running\n";
    exit(0);
}

// Optional env overrides your job already supports
$opts = [];
if (($p = getenv('TIDES_XML')) && is_string($p))  { $opts['tides_xml'] = $p; }
if (($p = getenv('WAVES_DIR')) && is_string($p))  { $opts['waves_dir'] = $p; }
if (($p = getenv('WINDS_FCST_DEFS')) && is_string($p)) {
    $defs = json_decode($p, true);
    if (is_array($defs)) { $opts['winds_fcst_defs'] = $defs; }
}

// Ensure logs dir exists
@mkdir($root . '/logs', 0775, true);

try {
    $out = ImportFC::refresh_all($opts);
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    @file_put_contents($root . '/logs/refresh_fc.heartbeat', gmdate('c') . "\n", FILE_APPEND);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
