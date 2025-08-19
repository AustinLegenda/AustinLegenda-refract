<?php

declare(strict_types=1);

namespace Legenda\NormalSurf\Utilities;

use PDO;
use DateTime;
use DateTimeZone;

use Legenda\NormalSurf\Helpers\Maths;
use Legenda\NormalSurf\Helpers\Format;
use Legenda\NormalSurf\Repositories\TideRepo as Tides;

final class TidePreference
{
    public function __construct(private TidePhase $phase) {}

    /**
     * Evaluate tide preferences for a spot at a given UTC time window.
     * Returns both local-string *and* UTC fields so Hooks can avoid formatting.
     *
     * Keys added (new):
     * - tide_reason_utc
     * - closest_pref_utc
     * - next_pref_utc
     * - next_marker_utc
     */
    public function tidePrefMatch(PDO $pdo, array $spot, string $nowUtc, int $windowMin = 60): array
    {
        static $cache = []; // per-station|hour memo

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
                'tide_reason_utc'  => null,
                'closest_pref' => null,
                'closest_pref_time' => null,
                'closest_pref_utc'  => null,
                'closest_pref_delta_min' => null,
                'next_pref' => null,
                'next_pref_time' => null,
                'next_pref_utc'  => null,
                'next_marker' => null,
                'next_marker_time' => null,
                'next_marker_utc'  => null,
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
            $d = Maths::haversine($lat, $lon, $tLat, $tLon);
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
                'tide_reason_utc'  => null,
                'closest_pref' => null,
                'closest_pref_time' => null,
                'closest_pref_utc'  => null,
                'closest_pref_delta_min' => null,
                'next_pref' => null,
                'next_pref_time' => null,
                'next_pref_utc'  => null,
                'next_marker' => null,
                'next_marker_time' => null,
                'next_marker_utc'  => null,
                'debug' => 'no tide station'
            ];
        }

        // ---- tide window (memoized per station|hour)
        $cacheKey = $nearestId . '|' . substr($nowUtc, 0, 13);
        if (!isset($cache[$cacheKey])) {
            $cache[$cacheKey] = $this->phase->tideWindowForStation($pdo, (string)$nearestId, $nowUtc, $windowMin);
        }
        $tide = $cache[$cacheKey];
        if (isset($tide['error'])) {
            return [
                'ok' => true,
                'has_prefs' => $hasPrefs,
                'tide' => null,
                'tide_reason' => null,
                'tide_reason_time' => null,
                'tide_reason_utc'  => null,
                'closest_pref' => null,
                'closest_pref_time' => null,
                'closest_pref_utc'  => null,
                'closest_pref_delta_min' => null,
                'next_pref' => null,
                'next_pref_time' => null,
                'next_pref_utc'  => null,
                'next_marker' => null,
                'next_marker_time' => null,
                'next_marker_utc'  => null,
                'debug' => 'tide error: ' . $tide['error']
            ];
        }

        // ---- time helpers
        $nowUtcDT = new DateTime($nowUtc, new DateTimeZone('UTC'));
        $mins = static function (DateTime $a, DateTime $b): int {
            return (int) round(($b->getTimestamp() - $a->getTimestamp()) / 60);
        };
        $toUtcDT = static function (?string $utc) {
            return $utc ? new DateTime($utc, new DateTimeZone('UTC')) : null;
        };
        $locFromUtcStr = static function (?string $utc) {
            return $utc ? Format::toLocalTime((new DateTime($utc, new DateTimeZone('UTC')))->format('Y-m-d H:i:00')) : null;
        };

        // ---- unpack window
        $nextType = $tide['next']['hl_type'] ?? null;             // 'H' or 'L'
        $nextUtcDT = $toUtcDT($tide['next']['t_utc'] ?? null);
        $nextUtc   = $nextUtcDT ? $nextUtcDT->format('Y-m-d H:i:00') : null;
        $nextLoc   = $tide['next']['t_local'] ?? ($locFromUtcStr($tide['next']['t_utc'] ?? null));

        $midInfo  = $tide['mid'] ?? null;                         // ['between','t_utc','t_local',...]
        $midUtcDT = $toUtcDT($midInfo['t_utc'] ?? null);
        $midUtc   = $midUtcDT ? $midUtcDT->format('Y-m-d H:i:00') : null;
        $midLoc   = $midInfo['t_local'] ?? ($locFromUtcStr($midInfo['t_utc'] ?? null));
        $between  = $midInfo['between'] ?? null;                  // 'L→H' => M+, 'H→L' => M-

        // ---- 1) current-window match (within ±windowMin of now)
        $ok = false;
        $reason = null;
        $reasonTime = null;
        $reasonUtc  = null;
        if (!empty($tide['within_window']['H']) && $prefH) {
            $ok = true;
            $reason = 'H';
            $reasonTime = $nextLoc;
            $reasonUtc  = $nextUtc;
        }
        if (!empty($tide['within_window']['L']) && $prefL) {
            $ok = true;
            $reason = 'L';
            $reasonTime = $nextLoc;
            $reasonUtc  = $nextUtc;
        }
        if (!empty($tide['within_window']['M+']) && $prefMp) {
            $ok = true;
            $reason = 'M+';
            $reasonTime = $midLoc;
            $reasonUtc  = $midUtc;
        }
        if (!empty($tide['within_window']['M-']) && $prefMm) {
            $ok = true;
            $reason = 'M-';
            $reasonTime = $midLoc;
            $reasonUtc  = $midUtc;
        }

        // ---- 2) NEXT preferred (earliest future candidate among preferred phases)
        $nextPref = null;
        $nextPrefTime = null;
        $nextPrefUtc  = null;
        $bestDelta = PHP_INT_MAX;

        if ($hasPrefs) {
            // a) Upcoming H/L from repository (first future of each)
            $futureHL = Tides::getNextHL($pdo, (string)$nearestId, $nowUtc, 6);
            $nextHutcDT = null;
            $nextHutc = null;
            $nextHloc = null;
            $nextLutcDT = null;
            $nextLutc = null;
            $nextLloc = null;

            foreach ($futureHL as $r) {
                if ($r['hl_type'] === 'H' && !$nextHutcDT) {
                    $nextHutcDT = $toUtcDT($r['t_utc']);
                    $nextHutc   = $nextHutcDT?->format('Y-m-d H:i:00');
                    $nextHloc   = $r['t_local'] ?? $locFromUtcStr($r['t_utc']);
                }
                if ($r['hl_type'] === 'L' && !$nextLutcDT) {
                    $nextLutcDT = $toUtcDT($r['t_utc']);
                    $nextLutc   = $nextLutcDT?->format('Y-m-d H:i:00');
                    $nextLloc   = $r['t_local'] ?? $locFromUtcStr($r['t_utc']);
                }
                if ($nextHutcDT && $nextLutcDT) break;
            }

            // b) Derive mids from FUTURE HL sequence
            $nextMidPlusUtcDT = null;
            $nextMidPlusUtc = null;
            $nextMidPlusLoc = null;   // M+ (L→H)
            $nextMidMinusUtcDT = null;
            $nextMidMinusUtc = null;
            $nextMidMinusLoc = null; // M− (H→L)
            for ($i = 0; $i + 1 < count($futureHL) && (!$nextMidPlusUtcDT || !$nextMidMinusUtcDT); $i++) {
                $a = $futureHL[$i];
                $b = $futureHL[$i + 1];
                if (($a['hl_type'] !== 'H' && $a['hl_type'] !== 'L') ||
                    ($b['hl_type'] !== 'H' && $b['hl_type'] !== 'L')
                ) continue;

                $ta = $toUtcDT($a['t_utc']);
                $tb = $toUtcDT($b['t_utc']);
                if (!$ta || !$tb) continue;

                $midUnix  = (int) floor(($ta->getTimestamp() + $tb->getTimestamp()) / 2);
                $midUtcDTx = (new DateTime('@' . $midUnix))->setTimezone(new DateTimeZone('UTC'));
                $midUtcStr = $midUtcDTx->format('Y-m-d H:i:00');
                $midLocStr = Format::toLocalTime($midUtcStr);

                if (!$nextMidPlusUtcDT && $a['hl_type'] === 'L' && $b['hl_type'] === 'H') {
                    $nextMidPlusUtcDT = $midUtcDTx;
                    $nextMidPlusUtc = $midUtcStr;
                    $nextMidPlusLoc = $midLocStr;
                }
                if (!$nextMidMinusUtcDT && $a['hl_type'] === 'H' && $b['hl_type'] === 'L') {
                    $nextMidMinusUtcDT = $midUtcDTx;
                    $nextMidMinusUtc = $midUtcStr;
                    $nextMidMinusLoc = $midLocStr;
                }
            }

            // c) Candidate selector
            $consider = function (string $phase, ?DateTime $utcDT, ?string $utcStr, ?string $locStr) use (&$nextPref, &$nextPrefTime, &$nextPrefUtc, &$bestDelta, $mins, $nowUtcDT) {
                if (!$utcDT) return;
                $d = $mins($nowUtcDT, $utcDT);
                if ($d >= 0 && $d < $bestDelta) {
                    $bestDelta   = $d;
                    $nextPref    = $phase;
                    $nextPrefTime = $locStr;
                    $nextPrefUtc = $utcStr;
                }
            };

            // H / L
            if ($prefH) $consider('H', $nextHutcDT, $nextHutc, $nextHloc);
            if ($prefL) $consider('L', $nextLutcDT, $nextLutc, $nextLloc);

            // M+
            if ($prefMp) {
                if (($tide['mid']['between'] ?? null) === 'L→H' && $midUtcDT) {
                    $consider('M+', $midUtcDT, $midUtc, $midLoc);
                }
                $consider('M+', $nextMidPlusUtcDT, $nextMidPlusUtc, $nextMidPlusLoc);
            }

            // M-
            if ($prefMm) {
                if (($tide['mid']['between'] ?? null) === 'H→L' && $midUtcDT) {
                    $consider('M-', $midUtcDT, $midUtc, $midLoc);
                }
                $consider('M-', $nextMidMinusUtcDT, $nextMidMinusUtc, $nextMidMinusLoc);
            }
        }

        // ---- 3) Generic next marker for spots with NO prefs
        $nextMarker = null;
        $nextMarkerTime = null;
        $nextMarkerUtc  = null;
        if (!$hasPrefs) {
            if ($midUtcDT && $mins($nowUtcDT, $midUtcDT) >= 0 && $between) {
                $nextMarker     = ($between === 'L→H') ? 'M+' : (($between === 'H→L') ? 'M-' : null);
                $nextMarkerTime = $midLoc;
                $nextMarkerUtc  = $midUtc;
            } elseif ($nextType && $nextUtc) {
                $nextMarker     = $nextType;
                $nextMarkerTime = $nextLoc;
                $nextMarkerUtc  = $nextUtc;
            }
        }

        return [
            'ok'                        => $ok,
            'has_prefs'                 => $hasPrefs,
            'tide'                      => $tide,

            'tide_reason'               => $reason,
            'tide_reason_time'          => $reasonTime,
            'tide_reason_utc'           => $reasonUtc,

            'closest_pref'              => $nextPref,
            'closest_pref_time'         => $nextPrefTime,
            'closest_pref_utc'          => $nextPrefUtc,
            'closest_pref_delta_min'    => ($nextPrefUtc && $bestDelta !== PHP_INT_MAX) ? $bestDelta : null,

            'next_pref'                 => $nextPref,
            'next_pref_time'            => $nextPrefTime,
            'next_pref_utc'             => $nextPrefUtc,

            'next_marker'               => $nextMarker,
            'next_marker_time'          => $nextMarkerTime,
            'next_marker_utc'           => $nextMarkerUtc,

            'debug'                     => $ok ? ('matched ' . $reason) : 'no phase match',
        ];
    }

    public static function allowPhase(array $spot, ?string $tideCode): bool
    {
        if ($tideCode === null) return false;
        return match ($tideCode) {
            'H'  => (int)($spot['H_tide'] ?? 0) === 1,
            'L'  => (int)($spot['L_tide'] ?? 0) === 1,
            'M+' => (int)($spot['M_plus_tide'] ?? 0) === 1,
            'M-' => (int)($spot['M_minus_tide'] ?? 0) === 1,
            default => false,
        };
    }
}
