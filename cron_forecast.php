<?php
// public_html/cron_forecast.php
$cmd = '/usr/bin/python3 /home/1452178.cloudwaysapps.com/mdpngsfhzc/public_html/Forecast/src/main.py';
$log = '/home/1452178.cloudwaysapps.com/mdpngsfhzc/public_html/logs/cron.log';
if (!is_dir(dirname($log))) { @mkdir(dirname($log), 0775, true); }
putenv('PYTHONUNBUFFERED=1');
exec("$cmd >> $log 2>&1");
echo "OK\n";
