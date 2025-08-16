<?php

namespace Legenda\NormalSurf\Hooks;

use DateTime;
use DateTimeZone;
use PDO;
use Legenda\NormalSurf\Repositories\NoaaTideRepository as Tides;

class Report
{
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
            $sumSin += sin($radians) * $weights[$i];
            $sumCos += cos($radians) * $weights[$i];
        }

        $avgRadians = atan2($sumSin, $sumCos);
        $avgDegrees = rad2deg($avgRadians);
        return fmod($avgDegrees + 360.0, 360.0);
    }

    public function station_interpolation(\PDO $pdo, array $data1, array $data2, WaveData $waveData): array
    {
        $matchingSpots = [];

        // fetch spots with their period and direction ranges
        $stmtSpots = $pdo->query("
            SELECT
                s.id,
                s.spot_name,
                r.region_lat,
                r.region_lon,
                s.period_min,
                s.period_max,
                s.dir_min,
                s.dir_max
            FROM surf_spots AS s
            INNER JOIN regions AS r ON s.regional_id = r.id
        ");
        $spots = $stmtSpots->fetchAll(\PDO::FETCH_ASSOC);

        // fixed buoy coordinates
        $stationCoords = [
            'station_41112' => ['lat' => 30.709, 'lon' => -81.292],
            'station_41117' => ['lat' => 29.999, 'lon' => -81.079],
        ];

        foreach ($spots as $spot) {
            $lat = $spot['region_lat'];
            $lon = $spot['region_lon'];

            // distance to each station
            $dist1 = $this->haversine($lat, $lon, $stationCoords['station_41112']['lat'], $stationCoords['station_41112']['lon']);
            $dist2 = $this->haversine($lat, $lon, $stationCoords['station_41117']['lat'], $stationCoords['station_41117']['lon']);

            // inverse‐distance weights
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

            // spot's allowed ranges
            $pMin = (float) $spot['period_min'];
            $pMax = (float) $spot['period_max'];
            $dMin = (float) $spot['dir_min'];
            $dMax = (float) $spot['dir_max'];

            // handle circular wrap for direction
            $dirOk = $dMin <= $dMax
                ? ($interpMWD >= $dMin && $interpMWD <= $dMax)
                : ($interpMWD >= $dMin || $interpMWD <= $dMax);

            // filter out non‐matching spots
            if (
                $dominantPeriod < $pMin ||
                $dominantPeriod > $pMax ||
                ! $dirOk
            ) {
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
            ];
        }

        return $matchingSpots;
    }

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

    function computeDominantPeriod(array $d): ?float
    {
        if (! isset($d['SwH'], $d['WWH'], $d['SwP'], $d['WWP'])) {
            return null;
        }

        // cast to floats
        $swH = (float) $d['SwH'];
        $wwH = (float) $d['WWH'];
        $swP = (float) $d['SwP'];
        $wwP = (float) $d['WWP'];

        // collapse heights to one decimal
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

    public function tideWindowForStation(PDO $pdo, string $stationId, string $nowUtc, int $windowMin = 60): array
    {
        // Pull prev and the next two rows (so we can find next + an opposite endpoint if needed)
        $prev = Tides::getPrevHL($pdo, $stationId, $nowUtc);      // or null at very beginning of dataset
        $nexts = Tides::getNextHL($pdo, $stationId, $nowUtc, 2);  // may be [0] only near end of dataset

        // Edge guards
        if (!$prev && empty($nexts)) {
            return ['error' => 'no_tide_rows'];
        }
        $next = $nexts[0] ?? $prev; // if no "next", mirror prev so we don't explode

        // Choose an opposite-type pair around "now" for mid-tide
        $a = $prev ?: $next;              // earlier endpoint
        $b = $nexts[0] ?? $prev;          // later endpoint
        if ($a && $b && $a['hl_type'] === $b['hl_type']) {
            // If both endpoints are H or both L, try to extend the window using the second "next" row
            if (isset($nexts[1]) && $nexts[1]['hl_type'] !== $a['hl_type']) {
                $b = $nexts[1];
            }
        }

        // Build DateTimes
        $tzLocal = new DateTimeZone('America/New_York');
        $now     = new DateTime($nowUtc, new DateTimeZone('UTC'));

        $taUtc = new DateTime($a['t_utc'] ?? $nowUtc, new DateTimeZone('UTC'));
        $tbUtc = new DateTime($b['t_utc'] ?? $nowUtc, new DateTimeZone('UTC'));

        // Mid time in UTC, then also local
        $midUnix = (int) floor(($taUtc->getTimestamp() + $tbUtc->getTimestamp()) / 2);
        $tMidUtc = (new DateTime("@{$midUnix}"))->setTimezone(new DateTimeZone('UTC'));
        $tMidLocal = (clone $tMidUtc)->setTimezone($tzLocal);

        // Mid height = simple average of endpoints (feet & meters)
        $hMidFt = round(((float)$a['height_ft'] + (float)$b['height_ft']) / 2.0, 3);
        $hMidM  = round(((float)$a['height_m']  + (float)$b['height_m'])  / 2.0, 3);
        $between = (($a['hl_type'] ?? 'I') === 'L' && ($b['hl_type'] ?? 'I') === 'H') ? 'L→H'
                 : (($a['hl_type'] ?? 'I') === 'H' && ($b['hl_type'] ?? 'I') === 'L' ? 'H→L' : 'L→H');

        // Window tests
        $minsDiff = static function (DateTime $x, DateTime $y): int {
            return (int) round(abs($x->getTimestamp() - $y->getTimestamp()) / 60);
        };
        $nextUtc = new DateTime($next['t_utc'], new DateTimeZone('UTC'));

        $within = [
            'H'  => ($next['hl_type'] === 'H' && $minsDiff($now, $nextUtc) <= $windowMin),
            'L'  => ($next['hl_type'] === 'L' && $minsDiff($now, $nextUtc) <= $windowMin),
            'M+' => ($between === 'L→H' && $minsDiff($now, $tMidUtc) <= $windowMin),
            'M-' => ($between === 'H→L' && $minsDiff($now, $tMidUtc) <= $windowMin),
        ];

        // Return in your convenient shape
        $toLocal = fn(string $utc) => (new DateTime($utc, new DateTimeZone('UTC')))
                                        ->setTimezone($tzLocal)->format('Y-m-d H:i:00');

        return [
            'prev' => $prev ? [
                'hl_type'   => $prev['hl_type'],
                't_utc'     => $prev['t_utc'],
                't_local'   => $toLocal($prev['t_utc']),
                'height_ft' => (float)$prev['height_ft'],
                'height_m'  => (float)$prev['height_m'],
            ] : null,
            'next' => $next ? [
                'hl_type'   => $next['hl_type'],
                't_utc'     => $next['t_utc'],
                't_local'   => $toLocal($next['t_utc']),
                'height_ft' => (float)$next['height_ft'],
                'height_m'  => (float)$next['height_m'],
            ] : null,
            'mid'  => [
                'between'   => $between, // 'L→H' or 'H→L'
                't_utc'     => $tMidUtc->format('Y-m-d H:i:00'),
                't_local'   => $tMidLocal->format('Y-m-d H:i:00'),
                'height_ft' => $hMidFt,
                'height_m'  => $hMidM,
            ],
            'within_window' => $within,
        ];
    }
}
