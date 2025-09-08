<?php
declare(strict_types=1);

$appRoot = dirname(__DIR__, 2); // /home/master/applications/<APP_ID>
chdir($appRoot);

// Ensure logs dir
@mkdir($appRoot.'/logs', 0775, true);
$logFile = $appRoot.'/logs/cron_refresh_fc.log';

// Command: same as local, but Cloudways paths
$cmd = sprintf(
    'cd %s && DB_SOCKET=/var/run/mysqld/mysqld.sock /usr/bin/php private_html/bin/refresh_fc.php >> %s 2>&1',
    escapeshellarg($appRoot),
    escapeshellarg($logFile)
);

$disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
if (!in_array('shell_exec', $disabled, true)) {
    shell_exec($cmd);
    echo "refresh_fc launched\n";
} else {
    require $appRoot.'/private_html/bin/refresh_fc.php';
    echo "refresh_fc run inline\n";
}
