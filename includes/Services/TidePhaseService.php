<?php
namespace Legenda\NormalSurf\Services;

use PDO;
use DateTime;
use DateTimeZone;
use Legenda\NormalSurf\Repositories\NoaaTideRepository as Tides;

final class TidePhaseService
{
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
                    if ($b && $r['t_utc'] === $b['t_utc']) {
                        $passedFirst = true;
                    }
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
            $midUtc  = (new DateTime('@' . $midUnix))->setTimezone(new DateTimeZone('UTC'));
            $midLocal = (clone $midUtc)->setTimezone($tzLocal);

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
        // ---- Build return ----
        // Keep a machine UTC format internally (if you ever need it)
        $fmtUtc = 'Y-m-d H:i:00';

        // Human local format (12-hour). If the event is not today, include day+date.
        $fmtSameDay  = 'g:i A';
        $fmtOtherDay = 'D, M j, g:i A';

        $toLocalStr = static function (?DateTime $utcDT, DateTimeZone $tzLocal) use ($fmtSameDay, $fmtOtherDay) {
            if (!$utcDT) return null;
            $local = (clone $utcDT)->setTimezone($tzLocal);

            $nowLocal = new DateTime('now', $tzLocal);
            $sameDay  = $local->format('Y-m-d') === $nowLocal->format('Y-m-d');

            return $local->format($sameDay ? $fmtSameDay : $fmtOtherDay);
        };

        // prev should be the *HL* we used as 'a' (not arbitrary previous)
        $prevOut = null;
        if ($a) {
            $prevOut = [
                'hl_type'   => $a['hl_type'],
                't_utc'     => $a['t_utc'],
                't_local'   => $toLocalStr(new DateTime($a['t_utc'], new DateTimeZone('UTC')), $tzLocal),
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
                't_local'   => $toLocalStr(new DateTime($nextHL['t_utc'], new DateTimeZone('UTC')), $tzLocal),
                'height_ft' => (float)$nextHL['height_ft'],
                'height_m'  => (float)$nextHL['height_m'],
            ];
        }

        $midOut = null;
        if ($midUtc && $between) {
            $midOut = [
                'between'   => $between,
                't_utc'     => $midUtc->format($fmtUtc),
                't_local'   => $toLocalStr($midUtc, $tzLocal),
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

    public function stateForStation(PDO $pdo, string $stationId, string $nowUtc, int $slackMin = 30): array
{
    // Get nearest previous and next H/L (skip 'I' rows)
    $prev = \Legenda\NormalSurf\Repositories\NoaaTideRepository::getPrevHL($pdo, $stationId, $nowUtc);
    $nextList = \Legenda\NormalSurf\Repositories\NoaaTideRepository::getNextHL($pdo, $stationId, $nowUtc, 3);
    $next = null;
    foreach ($nextList as $n) {
        if ($n && ($n['hl_type'] === 'H' || $n['hl_type'] === 'L')) { $next = $n; break; }
    }

    if (!$prev && !$next) {
        return ['state' => null];
    }

    $now = new \DateTime($nowUtc, new \DateTimeZone('UTC'));

    $checkSlack = function (?array $row, string $label) use ($now, $slackMin) {
        if (!$row) return null;
        $t = new \DateTime($row['t_utc'], new \DateTimeZone('UTC'));
        $diffMin = abs(($now->getTimestamp() - $t->getTimestamp()) / 60);
        if ($diffMin <= $slackMin) {
            $start = (clone $t)->modify("-{$slackMin} minutes")->format('Y-m-d H:i:00');
            $end   = (clone $t)->modify("+{$slackMin} minutes")->format('Y-m-d H:i:00');
            return ['state' => $label, 'state_code' => ($label === 'High' ? 'H' : 'L'), 'slack_start_utc' => $start, 'slack_end_utc' => $end, 'peak_utc' => $t->format('Y-m-d H:i:00')];
        }
        return null;
    };

    // High/Low slack windows first (± 30m around the peak)
    if ($prev && $prev['hl_type'] === 'H') { if ($hit = $checkSlack($prev, 'High')) return $hit; }
    if ($prev && $prev['hl_type'] === 'L') { if ($hit = $checkSlack($prev, 'Low'))  return $hit; }
    if ($next && $next['hl_type'] === 'H') { if ($hit = $checkSlack($next, 'High')) return $hit; }
    if ($next && $next['hl_type'] === 'L') { if ($hit = $checkSlack($next, 'Low'))  return $hit; }

    // If not in slack, classify by direction
    if ($prev && $next) {
        $prevT = new \DateTime($prev['t_utc'], new \DateTimeZone('UTC'));
        $nextT = new \DateTime($next['t_utc'], new \DateTimeZone('UTC'));
        $midT  = (new \DateTime('@' . (int)(($prevT->getTimestamp() + $nextT->getTimestamp())/2)))->setTimezone(new \DateTimeZone('UTC'));

        // Incoming if Low → High, Outgoing if High → Low
        if ($prev['hl_type'] === 'L' && $next['hl_type'] === 'H') {
            $state = 'Incoming';
            $code  = 'IN';
            $mLabel = 'M+'; // Mid → High
        } elseif ($prev['hl_type'] === 'H' && $next['hl_type'] === 'L') {
            $state = 'Outgoing';
            $code  = 'OUT';
            $mLabel = 'M-'; // Mid → Low
        } else {
            $state = null; $code = null; $mLabel = null;
        }

        return [
            'state'        => $state,
            'state_code'   => $code,
            'prev'         => $prev,
            'next'         => $next,
            'mid_utc'      => $midT->format('Y-m-d H:i:00'),
            'mid_label'    => $mLabel,
            'slack_minutes'=> $slackMin,
        ];
    }

    // Fallback (one side missing): unknown
    return ['state' => null];
}


}
