<?php

declare(strict_types=1);

namespace Legenda\NormalSurf\Hooks;

use PDO;
use DateTime;
use DateTimeZone;

use Legenda\NormalSurf\Services\SpotSelector;
use Legenda\NormalSurf\Services\TidePreferenceEvaluator;
use Legenda\NormalSurf\Services\TidePhaseService;
use Legenda\NormalSurf\Services\PeriodService;
use Legenda\NormalSurf\Repositories\NoaaTideRepository;

final class Report
{
    // Map current-conditions rows to tide stations (can move to config later)
    private const TIDE_STATION_BY_KEY = [
        '41112'  => '8720030', // Fernandina Beach
        'median' => '8720218', // Mayport (St. Johns Entrance)
        '41117'  => '8720587', // St. Augustine
    ];

    public static function tideStationIdForKey($key): ?string
    {
        $k = (string)$key; // normalize int 41112 -> "41112"
        return self::TIDE_STATION_BY_KEY[$k] ?? null;
    }
    /** Wrapper to keep older call sites happy if they used Report::computeDominantPeriod */
    public function computeDominantPeriod(array $row): ?float
    {
        return PeriodService::computeDominantPeriod($row);
    }

    /** Latest canonical NOAA *station* row (realtime) with WVHT/SwP/WWP/MWD, etc. */
    private function latestStationRow(PDO $pdo, string $stationId, string $nowUtc): ?array
    {
        $table = 'station_' . preg_replace('/\D+/', '', $stationId);
        $cols  = 'ts, WVHT, SwH, SwP, WWH, WWP, SwD, WWD, STEEPNESS, APD, MWD';
        $stmt  = $pdo->prepare("SELECT {$cols} FROM `{$table}` WHERE ts <= ? ORDER BY ts DESC LIMIT 1");
        $stmt->execute([$nowUtc]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Absolute midpoint builder for current conditions (simple mean per column). */
    public function buildCurrentConditionRows(array $data1, array $data2): array
    {
        $cols = ['ts', 'WVHT', 'SwH', 'SwP', 'WWH', 'WWP', 'SwD', 'WWD', 'STEEPNESS', 'APD', 'MWD'];

        $mid = [];
        foreach ($cols as $c) {
            $v1 = $data1[$c] ?? null;
            $v2 = $data2[$c] ?? null;
            $mid[$c] = (is_numeric($v1) && is_numeric($v2)) ? (($v1 + $v2) / 2) : null;
        }

        $rows = [
            '41112'  => ['key' => '41112',  'label' => 'St. Marys Entrance',                'data' => $data1],
            'median' => ['key' => 'median', 'label' => 'St. Johns Approach (interpolated)', 'data' => $mid],
            '41117'  => ['key' => '41117',  'label' => 'St. Augustine',                      'data' => $data2],
        ];

        foreach ($rows as $k => $r) {
            $rows[$k]['dominant_period'] = $this->computeDominantPeriod($r['data']);
            $rows[$k]['tide_station_id'] = self::tideStationIdForKey($k);
        }

        return $rows;
    }

    /** Current “H/L/Incoming/Outgoing” label with ±30m slack for H/L. */
    public function tideCurrentLabel(PDO $pdo, string $tideStationId, string $nowUtc): string
    {
        $svc = new TidePhaseService();
        $st  = $svc->stateForStation($pdo, $tideStationId, $nowUtc, 30);

        if (!$st || empty($st['state'])) return '—';
        if ($st['state'] === 'High' || $st['state'] === 'Low') return $st['state'];
        if ($st['state'] === 'Incoming' || $st['state'] === 'Outgoing') return $st['state'];
        return '—';
    }

    /** Next peak tide info: minutes till + “High @ 7:00 PM” label. */
    private function nextTideInfo(PDO $pdo, string $tideStationId, string $nowUtc, string $tz): ?array
    {
        $arr = NoaaTideRepository::getNextHL($pdo, $tideStationId, $nowUtc, 1);
        if (empty($arr)) return null;

        $row = $arr[0];
        if (empty($row['t_utc']) || empty($row['hl_type'])) return null;

        $now  = new DateTime($nowUtc, new DateTimeZone('UTC'));
        $when = new DateTime($row['t_utc'], new DateTimeZone('UTC'));
        $mins = max(0, (int) floor(($when->getTimestamp() - $now->getTimestamp()) / 60));
        $type = $row['hl_type'] === 'H' ? 'High' : 'Low';

        return [
            'minutes'   => $mins,
            'row_label' => $type . ' @ ' . Format::localHm($row['t_utc'], $tz),
        ];
    }

    /**
     * View-model for “Current Conditions”
     * - Title shows exact local time
     * - “Current Tide At” shows exact local HH:MM
     * - “Next Tide In …” (header) shows countdown from the median row
     * - Rows: Wave (WVHT @ DP & MWD), Current Tide (H/L/Incoming/Outgoing), Next Tide At (“High @ 7:00 PM”), Wind (placeholder)
     */
    public function currentConditionsView(PDO $pdo, string $tz = 'America/New_York'): array
    {
        $nowUtc   = Convert::UTC_time();
        $nowLocal = Convert::toLocalTime($nowUtc, $tz);

        $r12 = $this->latestStationRow($pdo, '41112', $nowUtc);
        $r17 = $this->latestStationRow($pdo, '41117', $nowUtc);

        if (!$r12 && !$r17) {
            return [
                'now_local'          => $nowLocal,
                'current_hm_label'   => Format::localHm($nowUtc, $tz),
                'next_tide_in_label' => '—',
                'rows'               => [],
            ];
        }
        if (!$r12) $r12 = $r17;
        if (!$r17) $r17 = $r12;

        $rows = $this->buildCurrentConditionRows($r12, $r17);

        foreach ($rows as $k => $r) {
            $tid = $r['tide_station_id'] ?? null;

            $rows[$k]['wave_cell']          = Format::waveCellDominant($r['data']);
            $rows[$k]['tide_label_current'] = $tid ? $this->tideCurrentLabel($pdo, $tid, $nowUtc) : '—';

            $info = $tid ? $this->nextTideInfo($pdo, $tid, $nowUtc, $tz) : null;
            $rows[$k]['tide_next_at']       = $info['row_label'] ?? '—';
            $rows[$k]['_next_minutes']      = $info['minutes'] ?? null;

            $rows[$k]['wind_label']         = Format::windPlaceholder();
        }

        // keep a stable order for render
        $ordered = [];
        foreach (['41112', 'median', '41117'] as $key) {
            if (isset($rows[$key])) $ordered[$key] = $rows[$key];
        }

        // header countdown derives from median if present; else min of sides
        $mins = $ordered['median']['_next_minutes'] ?? null;
        if ($mins === null) {
            $candidates = [];
            if (isset($ordered['41112']['_next_minutes'])) $candidates[] = $ordered['41112']['_next_minutes'];
            if (isset($ordered['41117']['_next_minutes'])) $candidates[] = $ordered['41117']['_next_minutes'];
            $mins = !empty($candidates) ? min($candidates) : null;
        }

        return [
            'now_local'          => $nowLocal,
            'current_hm_label'   => Format::localHm($nowUtc, $tz),
            'next_tide_in_label' => $mins !== null ? Format::minutesToHm((int)$mins) : '—',
            'rows'               => $ordered,
        ];
    }

    /**
     * View-model for “Where To Surf Now”
     * - Uses only realtime station rows + your Services (SpotSelector + TidePreferenceEvaluator + TidePhaseService)
     * - Returns two buckets: best (List 1) and others (List 2)
     */
    public function whereToSurfNowView(PDO $pdo, string $tz = 'America/New_York'): array
    {
        $nowUtc   = Convert::UTC_time();
        $headerHm = Format::localHm($nowUtc, $tz);

        $r12 = $this->latestStationRow($pdo, '41112', $nowUtc);
        $r17 = $this->latestStationRow($pdo, '41117', $nowUtc);

        if (!$r12 && !$r17) {
            return ['header_hm' => $headerHm, 'best' => [], 'others' => [], 'message' => 'Conditions are less than optimal at this time.'];
        }
        if (!$r12) $r12 = $r17;
        if (!$r17) $r17 = $r12;

        // Use your existing selector/evaluator/services; WaveData carries any needed context
        $selector = new SpotSelector(new TidePreferenceEvaluator(new TidePhaseService()));
        $waveData = new WaveData();
        $results  = $selector->select($pdo, $r12, $r17, $waveData);
        if (empty($results)) {
            return ['header_hm' => $headerHm, 'best' => [], 'others' => [], 'message' => 'Conditions are less than optimal at this time.'];
        }

        $best = [];
        $others = [];

        foreach ($results as $r) {
            $name = $r['spot_name'] ?? $r['name'] ?? 'Unnamed Spot';
            $wvht = isset($r['WVHT']) ? (float)$r['WVHT'] : null;                 // meters
            $dp   = isset($r['dominant_period']) ? (float)$r['dominant_period'] : null; // seconds
            $mwd  = isset($r['interpolated_mwd']) ? (float)$r['interpolated_mwd'] : null; // degrees

            $row = [
                'name'      => $name,
                'wave_cell' => Format::waveCellFromParts($wvht, $dp, $mwd),
                'tide'      => $r['tide_note'] ?? '—',
                'wind'      => Format::windPlaceholder(), // wire real wind later
            ];

            (($r['list_bucket'] ?? '2') === '1') ? $best[] = $row : $others[] = $row;
        }

        // simple ordering (keep deterministic UI)
        usort($best, fn($a, $b) => strcmp($a['name'], $b['name']));
        usort($others, fn($a, $b) => strcmp($a['name'], $b['name']));

        return ['header_hm' => $headerHm, 'best' => $best, 'others' => $others];
    }
}
