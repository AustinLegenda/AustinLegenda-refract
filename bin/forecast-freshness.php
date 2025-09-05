#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../includes/Infra/Db.php';
use Legenda\NormalSurf\Infra\Db;

$pdo = Db::get();

/** table => timestamp column */
$TS = [
    // tides
    'tides_8720030' => 't_utc',
    'tides_8720218' => 't_utc',
    'tides_8720587' => 't_utc',
    // waves (forecast)
    'waves_41112'   => 't_utc',
    'waves_41117'   => 't_utc',
    // winds (forecast)
    'winds_fcst_41112' => 'ts',
    'winds_fcst_median'=> 'ts',
    'winds_fcst_41117' => 'ts',
];

function horizon(PDO $pdo, string $table, string $tsCol): ?array {
    $sql = "SELECT MIN($tsCol) min_ts, MAX($tsCol) max_ts, COUNT(*) rows FROM `$table`";
    $r = $pdo->query($sql);
    return $r ? $r->fetch(PDO::FETCH_ASSOC) : null;
}

function sampleLatest(PDO $pdo, string $table, string $tsCol, int $n = 3): array {
    $sql = "SELECT * FROM `$table` ORDER BY $tsCol DESC LIMIT $n";
    $r = $pdo->query($sql);
    return $r ? $r->fetchAll(PDO::FETCH_ASSOC) : [];
}

$groups = [
    'Tides' => ['tides_8720030','tides_8720218','tides_8720587'],
    'Waves (forecast)' => ['waves_41112','waves_41117'],
    'Winds (forecast)' => ['winds_fcst_41112','winds_fcst_median','winds_fcst_41117'],
];

foreach ($groups as $label => $tables) {
    echo "== $label ==\n";
    foreach ($tables as $t) {
        if (!isset($TS[$t])) { echo str_pad($t, 20)."  (no ts map)\n"; continue; }
        $col = $TS[$t];
        $h   = horizon($pdo, $t, $col);
        if (!$h) { echo str_pad($t, 20)."  (no rows)\n"; continue; }
        printf("%-20s  col=%-6s  min=%s  max=%s  rows=%s\n",
            $t, $col, $h['min_ts'] ?? 'null', $h['max_ts'] ?? 'null', $h['rows'] ?? '0');

        // show the most recent row’s timestamp + 2 more for sanity
        $latest = sampleLatest($pdo, $t, $col, 2);
        foreach ($latest as $i => $row) {
            $ts = $row[$col] ?? null;
            echo "   • latest[$i] $col=" . ($ts ?? 'null') . "\n";
        }
    }
    echo "\n";
}

// Targets to compare against
$tz = new DateTimeZone('America/New_York');
$nowL = new DateTime('now', $tz);
$tomorrowEndL = (clone $nowL)->modify('tomorrow')->setTime(23,59,59);
$tomorrowEndUtc = (clone $tomorrowEndL)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$plus72Utc = (new DateTime('now', new DateTimeZone('UTC')))->modify('+72 hours')->format('Y-m-d H:i:s');

echo "Needs (UTC): tomorrow_end=$tomorrowEndUtc ; now+72h=$plus72Utc\n";
