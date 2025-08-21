<?php
// install_numpy.php — one-time installer for NumPy without SSH
$log = __DIR__ . '/logs/install_numpy.log';
if (!is_dir(dirname($log))) { @mkdir(dirname($log), 0775, true); }

$cmds = [
  '/usr/bin/python3 -m pip install --user --upgrade pip',
  '/usr/bin/python3 -m pip install --user numpy'
];

file_put_contents($log, "---- RUN ".date('c')." ----\n", FILE_APPEND);
foreach ($cmds as $cmd) {
  file_put_contents($log, "$cmd\n", FILE_APPEND);
  $out = [];
  $code = 0;
  exec($cmd . ' 2>&1', $out, $code);
  file_put_contents($log, implode("\n", $out)."\nRC: $code\n\n", FILE_APPEND);
}
echo "Done. Check logs/install_numpy.log\n";
