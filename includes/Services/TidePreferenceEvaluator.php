<?php

namespace Legenda\NormalSurf\Services;

use PDO;

use Legenda\NormalSurf\Services\Geo;
use Legenda\NormalSurf\Services\TidePhaseService;

use Legenda\NormalSurf\Hooks\Convert;
use Legenda\NormalSurf\Repositories\NoaaTideRepository as Tides;

final class TidePreferenceEvaluator
{
    public function __construct(private TidePhaseService $phase) {}

    public function tidePrefMatch(PDO $pdo, array $spot, string $nowUtc, int $windowMin = 60): array
    {
        static $cache = []; // per-station/hour memoization

        // ---- truthy helper
        $truthy = static function ($v): bool {
            if ($v === null) return false;
            if (is_bool($v)) return $v;
            if (is_numeric($v)) return ((int)$v) === 1;
            $s = strtolower(trim((string)$v));
            return in_array($s, ['1', 'y', 'yes', 't', 'true'], true);
        };

        // ---- preferences
        $prefH  = $truthy($spot['H_tide']       ?? null);
        $prefMp = $truthy($spot['M_plus_tide']  ?? null);
        $prefMm = $truthy($spot['M_minus_tide'] ?? null);
        $prefL  = $truthy($spot['L_tide']       ?? null);
        $hasPrefs = ($prefH || $prefMp || $prefMm || $prefL);

        // ---- coordinates guard
        if (!isset($spot['region_lat'], $spot['region_lon'])) {
            return [
                'ok' => true,
                'has_prefs' => $hasPrefs,
                'tide' => null,
                'tide_reason' => null,
                'tide_reason_time' => null,
                'closest_pref' => null,
                'closest_pref_time' => null,
                'closest_pref_delta_min' => null,
                'next_pref' => null,
                'next_pref_time' => null,
                'next_marker' => null,
                'next_marker_time' => null,
                'debug' => 'no coords'
            ];
        }

        // ---- nearest tide station (static map)
        $tideStations = [
            '8720030' => [30.671500, -81.465300], // Fernandina Beach
            '8720218' => [30.398200, -81.383100], // Mayport Bar Pilots
            '8720291' => [30.288500, -81.390900], // Jax Beach Pier
        ];
        $lat = (float)$spot['region_lat'];
        $lon = (float)$spot['region_lon'];

        $nearestId = null;
        $nearestKm = PHP_FLOAT_MAX;
        foreach ($tideStations as $sid => [$tLat, $tLon]) {
            $d = Geo::haversine($lat, $lon, $tLat, $tLon);
            if ($d < $nearestKm) {
                $nearestKm = $d;
                $nearestId = $sid;
            }
        }
        if (!$nearestId) {
            return [
                'ok' => true,
                'has_prefs' => $hasPrefs,
                'tide' => null,
                'tide_reason' => null,
                'tide_reason_time' => null,
                'closest_pref' => null,
                'closest_pref_time' => null,
                'closest_pref_delta_min' => null,
                'next_pref' => null,
                'next_pref_time' => null,
                'next_marker' => null,
                'next_marker_time' => null,
                'debug' => 'no tide station'
            ];
        }

        // ---- tide window (memoized per station|hour)
        $cacheKey = $nearestId . '|' . substr($nowUtc, 0, 13);
        if (!isset($cache[$cacheKey])) {
            $cache[$cacheKey] = $this->phase->tideWindowForStation($pdo, $nearestId, $nowUtc, $windowMin);
        }
        $tide = $cache[$cacheKey];
        if (isset($tide['error'])) {
            return [
                'ok' => true,
                'has_prefs' => $hasPrefs,
                'tide' => null,
                'tide_reason' => null,
                'tide_reason_time' => null,
                'closest_pref' => null,
                'closest_pref_time' => null,
                'closest_pref_delta_min' => null,
                'next_pref' => null,
                'next_pref_time' => null,
                'next_marker' => null,
                'next_marker_time' => null,
                'debug' => 'tide error: ' . $tide['error']
            ];
        }

        // ---- time helpers
        $nowUtcDT = new \DateTime($nowUtc, new \DateTimeZone('UTC'));
        $mins = static function (\DateTime $a, \DateTime $b): int {
            return (int) round(($b->getTimestamp() - $a->getTimestamp()) / 60);
        };
        $toUtcDT = static function (?string $utc) {
            return $utc ? new \DateTime($utc, new \DateTimeZone('UTC')) : null;
        };
        $locFromUtcStr = static function (?string $utc) {
            return $utc ? Convert::toLocalTime((new \DateTime($utc, new \DateTimeZone('UTC')))->format('Y-m-d H:i:00')) : null;
        };

        // ---- unpack window
        $nextType = $tide['next']['hl_type'] ?? null;             // 'H' or 'L'
        $nextUtc  = $toUtcDT($tide['next']['t_utc'] ?? null);
        $nextLoc  = $tide['next']['t_local'] ?? ($locFromUtcStr($tide['next']['t_utc'] ?? null));

        $midInfo  = $tide['mid'] ?? null;                         // ['between','t_utc','t_local',...]
        $midUtc   = $toUtcDT($midInfo['t_utc'] ?? null);
        $midLoc   = $midInfo['t_local'] ?? ($locFromUtcStr($midInfo['t_utc'] ?? null));
        $between  = $midInfo['between'] ?? null;                  // 'L→H' => M+, 'H→L' => M-

        // ---- 1) current-window match (within ±windowMin of now) -> sets ok/reason
        $ok = false;
        $reason = null;
        $reasonTime = null;
        if (!empty($tide['within_window']['H']) && $prefH) {
            $ok = true;
            $reason = 'H';
            $reasonTime = $tide['next']['t_local'] ?? $nextLoc;
        }
        if (!empty($tide['within_window']['L']) && $prefL) {
            $ok = true;
            $reason = 'L';
            $reasonTime = $tide['next']['t_local'] ?? $nextLoc;
        }
        if (!empty($tide['within_window']['M+']) && $prefMp) {
            $ok = true;
            $reason = 'M+';
            $reasonTime = $midLoc;
        }
        if (!empty($tide['within_window']['M-']) && $prefMm) {
            $ok = true;
            $reason = 'M-';
            $reasonTime = $midLoc;
        }

        // ---- 2) NEXT preferred (earliest future candidate among preferred phases)
        $nextPref = null;
        $nextPrefTime = null;
        $bestDelta = PHP_INT_MAX;

        if ($hasPrefs) {
            // a) Upcoming H/L from repository (first future of each)
            $futureHL = Tides::getNextHL($pdo, $nearestId, $nowUtc, 6);
            $nextHutc = null;
            $nextHloc = null;
            $nextLutc = null;
            $nextLloc = null;
            foreach ($futureHL as $r) {
                if ($r['hl_type'] === 'H' && !$nextHutc) {
                    $nextHutc = $toUtcDT($r['t_utc']);
                    $nextHloc = $r['t_local'] ?? $locFromUtcStr($r['t_utc']);
                }
                if ($r['hl_type'] === 'L' && !$nextLutc) {
                    $nextLutc = $toUtcDT($r['t_utc']);
                    $nextLloc = $r['t_local'] ?? $locFromUtcStr($r['t_utc']);
                }
                if ($nextHutc && $nextLutc) break;
            }

            // b) Derive mids from FUTURE HL sequence (first L→H and first H→L after now)
            $nextMidPlusUtc = null;
            $nextMidPlusLoc = null;   // M+ (L→H)
            $nextMidMinusUtc = null;
            $nextMidMinusLoc = null;  // M− (H→L)
            for ($i = 0; $i + 1 < count($futureHL) && (!$nextMidPlusUtc || !$nextMidMinusUtc); $i++) {
                $a = $futureHL[$i];
                $b = $futureHL[$i + 1];
                if (($a['hl_type'] !== 'H' && $a['hl_type'] !== 'L') ||
                    ($b['hl_type'] !== 'H' && $b['hl_type'] !== 'L')
                ) continue;

                $ta = $toUtcDT($a['t_utc']);
                $tb = $toUtcDT($b['t_utc']);
                if (!$ta || !$tb) continue;

                $midUnix  = (int) floor(($ta->getTimestamp() + $tb->getTimestamp()) / 2);
                $midUtcDT = (new \DateTime('@' . $midUnix))->setTimezone(new \DateTimeZone('UTC'));
                $midLocDT = Convert::toLocalTime($midUtcDT->format('Y-m-d H:i:00'));

                if (!$nextMidPlusUtc && $a['hl_type'] === 'L' && $b['hl_type'] === 'H') {
                    $nextMidPlusUtc = $midUtcDT;
                    $nextMidPlusLoc  = $midLocDT;
                }
                if (!$nextMidMinusUtc && $a['hl_type'] === 'H' && $b['hl_type'] === 'L') {
                    $nextMidMinusUtc = $midUtcDT;
                    $nextMidMinusLoc = $midLocDT;
                }
            }

            // c) Candidate set: include current-half mid if it's still upcoming
            $consider = function (string $phase, ?\DateTime $utc, ?string $loc) use (&$nextPref, &$nextPrefTime, &$bestDelta, $mins, $nowUtcDT) {
                if (!$utc) return;
                $d = $mins($nowUtcDT, $utc);
                if ($d >= 0 && $d < $bestDelta) {
                    $bestDelta = $d;
                    $nextPref = $phase;
                    $nextPrefTime = $loc;
                }
            };

            // H / L
            if ($prefH) $consider('H', $nextHutc, $nextHloc);
            if ($prefL) $consider('L', $nextLutc, $nextLloc);

            // M+ (current-half mid beats future L→H mid if earlier)
            if ($prefMp) {
                if ($between === 'L→H' && $midUtc) $consider('M+', $midUtc, $midLoc);
                $consider('M+', $nextMidPlusUtc, $nextMidPlusLoc);
            }

            // M− (current-half mid beats future H→L mid if earlier)
            if ($prefMm) {
                if ($between === 'H→L' && $midUtc) $consider('M-', $midUtc, $midLoc);
                $consider('M-', $nextMidMinusUtc, $nextMidMinusLoc);
            }
        }

        // ---- 3) Generic next marker for spots with NO prefs
        $nextMarker = null;
        $nextMarkerTime = null;
        if (!$hasPrefs) {
            if ($midUtc && $mins($nowUtcDT, $midUtc) >= 0 && $between) {
                $nextMarker = ($between === 'L→H') ? 'M+' : (($between === 'H→L') ? 'M-' : null);
                $nextMarkerTime = $midLoc;
            } elseif ($nextType && $nextUtc) {
                $nextMarker = $nextType;
                $nextMarkerTime = $nextLoc;
            }
        }

        return [
            'ok'                     => $ok,
            'has_prefs'              => $hasPrefs,
            'tide'                   => $tide,
            'tide_reason'            => $reason,
            'tide_reason_time'       => $reasonTime,

            // Back-compat + explicit names
            'closest_pref'           => $nextPref,
            'closest_pref_time'      => $nextPrefTime,
            'closest_pref_delta_min' => ($nextPrefTime && $bestDelta !== PHP_INT_MAX) ? $bestDelta : null,
            'next_pref'              => $nextPref,
            'next_pref_time'         => $nextPrefTime,

            'next_marker'            => $nextMarker,
            'next_marker_time'       => $nextMarkerTime,

            'debug'                  => $ok ? ('matched ' . $reason) : 'no phase match',
        ];
    }
}
