<?php

namespace Legenda\NormalSurf\Hooks;

use DateTime;
use DateTimeZone;
use PDO;
use Legenda\NormalSurf\Hooks\Convert;
use Legenda\NormalSurf\Hooks\WaveData;
use Legenda\NormalSurf\Repositories\NoaaTideRepository as Tides;

class Report
{
    // ———————————————————————
    // Math helpers (keep local)
    // ———————————————————————
    private function haversine($lat1, $lon1, $lat2, $lon2): float
    {
        $earth_radius = 6371; // km
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLon / 2) * sin($dLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earth_radius * $c;
    }

    private function circularAverage(array $angles, array $weights): float
    {
        $sumSin = 0.0;
        $sumCos = 0.0;

        foreach ($angles as $i => $angle) {
            $radians = deg2rad($angle);
            $w = (float)($weights[$i] ?? 1.0);
            $sumSin += sin($radians) * $w;
            $sumCos += cos($radians) * $w;
        }

        $avgRadians = atan2($sumSin, $sumCos);
        $avgDegrees = rad2deg($avgRadians);
        return fmod($avgDegrees + 360.0, 360.0);
    }

    // ———————————————————————
    // Tide logic (single-source + preference gate)
    // ———————————————————————

public function tideWindowForStation(PDO $pdo, string $stationId, string $nowUtc, int $windowMin = 60): array
{
    // ---- PREV: walk back to nearest H/L (skip 'I') ----
    $prev = Tides::getPrevHL($pdo, $stationId, $nowUtc);
    $guard = 0;
    while ($prev && ($prev['hl_type'] !== 'H' && $prev['hl_type'] !== 'L') && $guard < 8) {
        // step back again from the time of the row we just got
        $prev = Tides::getPrevHL($pdo, $stationId, $prev['t_utc']);
        $guard++;
    }
    if ($prev && ($prev['hl_type'] !== 'H' && $prev['hl_type'] !== 'L')) {
        $prev = null; // still not H/L after walking back
    }

    // ---- NEXT: look ahead, pick first H/L (skip 'I') ----
    $nexts = Tides::getNextHL($pdo, $stationId, $nowUtc, 6); // look far enough ahead
    $nextHL = null;
    foreach ($nexts as $r) {
        if ($r['hl_type'] === 'H' || $r['hl_type'] === 'L') {
            $nextHL = $r;
            break;
        }
    }

    // If we found neither prev H/L nor next H/L, bail
    if (!$prev && !$nextHL) {
        return ['error' => 'no_hl_events'];
    }

    // ---- Choose endpoints (a, b) for mid-tide (H↔L pair) ----
    // Prefer using prev (a) and nextHL (b). If they are same type, try to find a later opposite in $nexts.
    $a = $prev;
    $b = $nextHL;

    if ($a && $b && $a['hl_type'] === $b['hl_type']) {
        // find the next opposite H/L after the first nextHL
        $found = false;
        $passedFirst = false;
        foreach ($nexts as $r) {
            if ($r['hl_type'] !== 'H' && $r['hl_type'] !== 'L') continue;
            if (!$passedFirst) {
                // skip until we pass the first H/L we chose as $b
                if ($b && $r['t_utc'] === $b['t_utc']) { $passedFirst = true; }
                continue;
            }
            if ($r['hl_type'] !== $a['hl_type']) {
                $b = $r;
                $found = true;
                break;
            }
        }
        // If still same type and no opposite found, we'll handle mid gracefully below.
    }

    // If no prev H/L but we have at least two future H/Ls, use them as a→b
    if (!$a && $b) {
        $hls = [];
        foreach ($nexts as $r) {
            if ($r['hl_type'] === 'H' || $r['hl_type'] === 'L') $hls[] = $r;
            if (count($hls) >= 2) break;
        }
        if (count($hls) >= 2) {
            $a = $hls[0];
            $b = $hls[1];
        }
    }

    // ---- Build times ----
    $tzLocal = new DateTimeZone('America/New_York');
    $now     = new DateTime($nowUtc, new DateTimeZone('UTC'));

    $toUtcDT = static function (?array $row): ?DateTime {
        if (!$row || empty($row['t_utc'])) return null;
        return new DateTime($row['t_utc'], new DateTimeZone('UTC'));
    };

    $prevUtc = $toUtcDT($a);
    $nextUtc = $toUtcDT($nextHL);

    // ---- Mid-tide (only if we have two endpoints) ----
    $midUtc = null;
    $midLocal = null;
    $midFt = null;
    $midM  = null;
    $between = null;

    if ($a && $b && isset($a['hl_type'], $b['hl_type'])) {
        $taUtc = new DateTime($a['t_utc'], new DateTimeZone('UTC'));
        $tbUtc = new DateTime($b['t_utc'], new DateTimeZone('UTC'));
        $midUnix = (int) floor(($taUtc->getTimestamp() + $tbUtc->getTimestamp()) / 2);
        $midUtc  = (new DateTime('@'.$midUnix))->setTimezone(new DateTimeZone('UTC'));
        $midLocal= (clone $midUtc)->setTimezone($tzLocal);

        $midFt = round(((float)$a['height_ft'] + (float)$b['height_ft']) / 2.0, 3);
        $midM  = round(((float)$a['height_m']  + (float)$b['height_m'])  / 2.0, 3);
        $between = ($a['hl_type'] === 'L' && $b['hl_type'] === 'H') ? 'L→H'
                 : (($a['hl_type'] === 'H' && $b['hl_type'] === 'L') ? 'H→L' : null);
    }

    // ---- Within-window flags ----
    $minsDiff = static function (DateTime $x, DateTime $y): int {
        return (int) round(abs($x->getTimestamp() - $y->getTimestamp()) / 60);
    };

    $within = [
        'H'  => false,
        'L'  => false,
        'M+' => false,
        'M-' => false,
    ];

    if ($nextHL) {
        $nextHLutc = new DateTime($nextHL['t_utc'], new DateTimeZone('UTC'));
        if ($nextHL['hl_type'] === 'H') {
            $within['H'] = ($minsDiff($now, $nextHLutc) <= $windowMin);
        } elseif ($nextHL['hl_type'] === 'L') {
            $within['L'] = ($minsDiff($now, $nextHLutc) <= $windowMin);
        }
    }

    if ($between && $midUtc) {
        $mdiff = $minsDiff($now, $midUtc);
        if ($between === 'L→H') $within['M+'] = ($mdiff <= $windowMin);
        if ($between === 'H→L') $within['M-'] = ($mdiff <= $windowMin);
    }

    // ---- Build return ----
    $fmt = 'Y-m-d H:i:00';
    $toLocalStr = static function (?DateTime $utcDT, DateTimeZone $tzLocal, string $fmt) {
        return $utcDT ? (clone $utcDT)->setTimezone($tzLocal)->format($fmt) : null;
    };

    // prev should be the *HL* we used as 'a' (not arbitrary previous)
    $prevOut = null;
    if ($a) {
        $prevOut = [
            'hl_type'   => $a['hl_type'],
            't_utc'     => $a['t_utc'],
            't_local'   => $toLocalStr(new DateTime($a['t_utc'], new DateTimeZone('UTC')), $tzLocal, $fmt),
            'height_ft' => (float)$a['height_ft'],
            'height_m'  => (float)$a['height_m'],
        ];
    }

    // next should be the true next HL event we tested for H/L windows
    $nextOut = null;
    if ($nextHL) {
        $nextOut = [
            'hl_type'   => $nextHL['hl_type'],
            't_utc'     => $nextHL['t_utc'],
            't_local'   => $toLocalStr(new DateTime($nextHL['t_utc'], new DateTimeZone('UTC')), $tzLocal, $fmt),
            'height_ft' => (float)$nextHL['height_ft'],
            'height_m'  => (float)$nextHL['height_m'],
        ];
    }

    $midOut = null;
    if ($midUtc && $between) {
        $midOut = [
            'between'   => $between,
            't_utc'     => $midUtc->format($fmt),
            't_local'   => $toLocalStr($midUtc, $tzLocal, $fmt),
            'height_ft' => $midFt,
            'height_m'  => $midM,
        ];
    }

    return [
        'prev' => $prevOut,
        'next' => $nextOut,
        'mid'  => $midOut,
        'within_window' => $within,
    ];
}

// Pick nearest NOAA tide station for a spot, then check its tide prefs.
// Returns: [
//   ok => bool,
//   tide => array|null,
//   tide_reason => 'H'|'L'|'M+'|'M-'|null,
//   tide_reason_time => string|null (LOCAL time used for the check),
//   debug => string
// ]
private function tidePrefMatch(PDO $pdo, array $spot, string $nowUtc, int $windowMin = 60): array
{
    static $cache = []; // per-station/hour memoization

    $prefH  = !empty($spot['H_tide']);
    $prefMp = !empty($spot['M_plus_tide']);
    $prefMm = !empty($spot['M_minus_tide']);
    $prefL  = !empty($spot['L_tide']);

    $truthy = static function($v): bool {
    if ($v === null) return false;
    if (is_bool($v)) return $v;
    if (is_numeric($v)) return ((int)$v) === 1;
    $s = strtolower(trim((string)$v));
    return in_array($s, ['1','y','yes','t','true'], true);
};

    if (!$prefH && !$prefMp && !$prefMm && !$prefL) {
        $res = ['ok' => true, 'tide' => null, 'tide_reason' => null, 'tide_reason_time' => null, 'debug' => 'no prefs'];
        echo '<!-- tide-debug: ' . htmlspecialchars($spot['spot_name'] . ' — ' . $res['debug'], ENT_QUOTES, 'UTF-8') . ' -->';
        return $res;
    }

    if (!isset($spot['region_lat'], $spot['region_lon'])) {
        $res = ['ok' => true, 'tide' => null, 'tide_reason' => null, 'tide_reason_time' => null, 'debug' => 'no coords'];
        echo '<!-- tide-debug: ' . htmlspecialchars($spot['spot_name'] . ' — ' . $res['debug'], ENT_QUOTES, 'UTF-8') . ' -->';
        return $res;
    }
    $lat = (float)$spot['region_lat'];
    $lon = (float)$spot['region_lon'];

    // Minimal tide station list (extend as needed)
   $tideStations = [
            '8720030' => [30.642602, -81.43025], // Fernandina Beach
            '8720218' => [30.415555, -81.38233], // Mayport Bar Pilots
            '8720291' => [30.260920, -81.38334], // Jax Beach Pier
        ];

    // Find nearest tide station
    $nearestId = null;
    $nearestKm = PHP_FLOAT_MAX;
    foreach ($tideStations as $sid => [$tLat, $tLon]) {
        $d = $this->haversine($lat, $lon, $tLat, $tLon);
        if ($d < $nearestKm) { $nearestKm = $d; $nearestId = $sid; }
    }
    if (!$nearestId) {
        $res = ['ok' => true, 'tide' => null, 'tide_reason' => null, 'tide_reason_time' => null, 'debug' => 'no tide station'];
        echo '<!-- tide-debug: ' . htmlspecialchars($spot['spot_name'] . ' — ' . $res['debug'], ENT_QUOTES, 'UTF-8') . ' -->';
        return $res;
    }

    // Load tide window (cached by station + hour)
    $cacheKey = $nearestId . '|' . substr($nowUtc, 0, 13);
    if (!isset($cache[$cacheKey])) {
        $cache[$cacheKey] = $this->tideWindowForStation($pdo, $nearestId, $nowUtc, $windowMin);
    }
    $tide = $cache[$cacheKey];

    if (isset($tide['error'])) {
        $res = ['ok' => true, 'tide' => null, 'tide_reason' => null, 'tide_reason_time' => null, 'debug' => 'tide error: '.$tide['error']];
        echo '<!-- tide-debug: ' . htmlspecialchars($spot['spot_name'] . ' — ' . $res['debug'], ENT_QUOTES, 'UTF-8') . ' -->';
        return $res;
    }

    // Decide which preferred phase matched (and which time was used)
    $reason = null;
    $reasonTime = null;

    if ($prefH  && !empty($tide['within_window']['H'])) { $reason = 'H';  $reasonTime = $tide['next']['t_local'] ?? null; }
    elseif ($prefL  && !empty($tide['within_window']['L'])) { $reason = 'L';  $reasonTime = $tide['next']['t_local'] ?? null; }
    elseif ($prefMp && !empty($tide['within_window']['M+'])) { $reason = 'M+'; $reasonTime = $tide['mid']['t_local']  ?? null; }
    elseif ($prefMm && !empty($tide['within_window']['M-'])) { $reason = 'M-'; $reasonTime = $tide['mid']['t_local']  ?? null; }

    $ok = (bool)$reason;
    $res = [
        'ok'               => $ok,
        'tide'             => $tide,
        'tide_reason'      => $reason,
        'tide_reason_time' => $reasonTime,
        'debug'            => $ok ? ('matched '.$reason) : 'no phase match',
    ];

    // ALWAYS emit one line so you can see the decision for each spot
    echo '<!-- tide-debug: '
       . htmlspecialchars($spot['spot_name'] . ' — ' . $res['debug']
         . (isset($tide['next']['hl_type']) ? (' | next=' . $tide['next']['hl_type'] . '@' . ($tide['next']['t_local'] ?? '')) : '')
         . (isset($tide['mid']['between']) ? (' | mid=' . $tide['mid']['between'] . '@' . ($tide['mid']['t_local'] ?? '')) : ''),
         ENT_QUOTES, 'UTF-8')
       . ' -->';

    return $res;
}

    // ———————————————————————
    // Core selection model
    // ———————————————————————
    public function station_interpolation(PDO $pdo, array $data1, array $data2, WaveData $waveData): array
    {
        $matchingSpots = [];

        // fetch spots + period/direction ranges + tide prefs
        $stmtSpots = $pdo->query("
            SELECT
                s.id,
                s.spot_name,
                r.region_lat,
                r.region_lon,
                s.period_min,
                s.period_max,
                s.dir_min,
                s.dir_max,
                s.H_tide,
                s.M_plus_tide,
                s.M_minus_tide,
                s.L_tide
            FROM surf_spots AS s
            INNER JOIN regions AS r ON s.regional_id = r.id
        ");
        $spots = $stmtSpots->fetchAll(PDO::FETCH_ASSOC);

        // fixed buoy coordinates
        $stationCoords = [
            'station_41112' => ['lat' => 30.709, 'lon' => -81.292],
            'station_41117' => ['lat' => 29.999, 'lon' => -81.079],
        ];

        $nowUtc = Convert::UTC_time();

        foreach ($spots as $spot) {
            $lat = $spot['region_lat'];
            $lon = $spot['region_lon'];

            // distance to each station
            $dist1 = $this->haversine($lat, $lon, $stationCoords['station_41112']['lat'], $stationCoords['station_41112']['lon']);
            $dist2 = $this->haversine($lat, $lon, $stationCoords['station_41117']['lat'], $stationCoords['station_41117']['lon']);

            // inverse-distance weights
            $inv1 = 1 / ($dist1 + 0.01);
            $inv2 = 1 / ($dist2 + 0.01);
            $sumInv = $inv1 + $inv2;
            $w1 = $inv1 / $sumInv;
            $w2 = $inv2 / $sumInv;

            // require MWD at both stations
            $mwd1 = $data1['MWD'] ?? null;
            $mwd2 = $data2['MWD'] ?? null;
            if ($mwd1 === null || $mwd2 === null) {
                continue;
            }

            // interpolated MWD
            $interpMWD = $this->circularAverage([$mwd1, $mwd2], [$w1, $w2]);

            // full midpoint row for all variables
            $mid = $this->interpolate_midpoint_row(
                $data1,
                $data2,
                ['dist_41112' => $dist1, 'dist_41117' => $dist2]
            );

            // choose dominant period by comparing wave heights
            if ($mid['SwH'] > $mid['WWH']) {
                $dominantPeriod = $mid['SwP'];
            } elseif ($mid['SwH'] < $mid['WWH']) {
                $dominantPeriod = $mid['WWP'];
            } else {
                $dominantPeriod = ($mid['SwP'] + $mid['WWP']) / 2;
            }
            $dominantPeriod = round($dominantPeriod, 2);

            // spot ranges
            $pMin = (float) $spot['period_min'];
            $pMax = (float) $spot['period_max'];
            $dMin = (float) $spot['dir_min'];
            $dMax = (float) $spot['dir_max'];

            // direction wrap handling
            $dirOk = $dMin <= $dMax
                ? ($interpMWD >= $dMin && $interpMWD <= $dMax)
                : ($interpMWD >= $dMin || $interpMWD <= $dMax);

            // base filters
            if ($dominantPeriod < $pMin || $dominantPeriod > $pMax || !$dirOk) {
                continue;
            }

            // tide preference gate (single call)
            $tp = $this->tidePrefMatch($pdo, $spot, $nowUtc, 60);
            if (!$tp['ok']) {

                // TEMP DEBUG ECHO: explain why this spot was dropped by tide gate
                echo '<!-- tide-debug: REJECT '
                    . htmlspecialchars($spot['spot_name'] . ' — ' . $tp['debug'], ENT_QUOTES, 'UTF-8')
                    . ' -->';
                continue;
            }

            // record match
            $matchingSpots[] = [
                'spot_id'          => $spot['id'],
                'spot_name'        => $spot['spot_name'],
                'interpolated_mwd' => round($interpMWD, 1),
                'dominant_period'  => $dominantPeriod,
                'dist_41112'       => round($dist1, 2),
                'dist_41117'       => round($dist2, 2),
                'tide_reason'      => $tp['tide_reason'] ?? null,
                'tide_reason_time' => $tp['tide_reason_time'] ?? null,
            ];
        }

        return $matchingSpots;
    }

    // ———————————————————————
    // Midpoint interpolation (unchanged signature)
    // ———————————————————————
    public function interpolate_midpoint_row(array $data1, array $data2, array $distances): array
    {
        $columns = ['ts', 'WVHT', 'SwH', 'SwP', 'WWH', 'WWP', 'SwD', 'WWD', 'APD', 'MWD', 'STEEPNESS'];

        $dist1 = $distances['dist_41112'] ?? 1;
        $dist2 = $distances['dist_41117'] ?? 1;

        $inv1 = 1 / ($dist1 + 0.01);
        $inv2 = 1 / ($dist2 + 0.01);
        $sumInv = $inv1 + $inv2;
        $w1 = $inv1 / $sumInv;
        $w2 = $inv2 / $sumInv;

        $mid = [];

        foreach ($columns as $col) {
            if ($col === 'ts') {
                $mid[$col] = '—';
            } elseif ($col === 'MWD') {
                $mid[$col] = $this->circularAverage(
                    [$data1['MWD'] ?? 0, $data2['MWD'] ?? 0],
                    [$w1, $w2]
                );
            } else {
                $v1 = is_numeric($data1[$col] ?? null) ? $data1[$col] : null;
                $v2 = is_numeric($data2[$col] ?? null) ? $data2[$col] : null;
                $mid[$col] = ($v1 !== null && $v2 !== null)
                    ? $v1 * $w1 + $v2 * $w2
                    : '—';
            }
        }

        return $mid;
    }

    // ———————————————————————
    // Period chooser (unchanged signature)
    // ———————————————————————
    public function computeDominantPeriod(array $d): ?float
    {
        if (!isset($d['SwH'], $d['WWH'], $d['SwP'], $d['WWP'])) {
            return null;
        }

        $swH = (float) $d['SwH'];
        $wwH = (float) $d['WWH'];
        $swP = (float) $d['SwP'];
        $wwP = (float) $d['WWP'];

        $swH1 = round($swH, 1);
        $wwH1 = round($wwH, 1);

        if ($swH1 === $wwH1) {
            $dp = ($swP + $wwP) / 2;
        } elseif ($swH1 > $wwH1) {
            $dp = $swP;
        } else {
            $dp = $wwP;
        }

        return round($dp, 1);
    }
}
