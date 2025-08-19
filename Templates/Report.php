<?php

declare(strict_types=1);

namespace Legenda\NormalSurf\Templates;

use PDO;
use DateTime;
use DateTimeZone;

use Legenda\NormalSurf\Repositories\StationRepo; //move later
use Legenda\NormalSurf\Hooks\LoadData;
use Legenda\NormalSurf\Hooks\WaveCell;
use Legenda\NormalSurf\Hooks\TideCell;
use Legenda\NormalSurf\Hooks\SpotSelector;
use Legenda\NormalSurf\Helpers\Format;      // fix later

final class Report
{
    private PDO $pdo;
    private StationRepo $stations;
    private WaveCell $waveCell;
    private TideCell $tideCell;
    private SpotSelector $selector;

    private const TIDE_STATION_BY_KEY = [
        '41112'  => '8720030',
        'median' => '8720218',
        '41117'  => '8720587',
    ];

    public function __construct(?PDO $pdo = null)
    {
        if ($pdo instanceof PDO) {
            $this->pdo = $pdo;
        } else {
            [$conn] = LoadData::conn_report('41112');
            $this->pdo = $conn;
        }
        $this->stations = new StationRepo($this->pdo);
        $this->waveCell = new WaveCell();
        $this->tideCell = new TideCell($this->pdo);
        $this->selector = new SpotSelector(); // if it needs PDO, inject it similarly
    }

    private static function tideStationIdForKey(int|string $key): ?string
    {
        $k = (string)$key; // normalize 41112 → "41112"
        return self::TIDE_STATION_BY_KEY[$k] ?? null;
    }

    /** Midpoint for two rows (simple mean per numeric column). */
    private function midpoint(array $a, array $b): array
    {
        $cols = ['ts', 'WVHT', 'SwH', 'SwP', 'WWH', 'WWP', 'SwD', 'WWD', 'STEEPNESS', 'APD', 'MWD'];
        $mid = [];
        foreach ($cols as $c) {
            $v1 = $a[$c] ?? null;
            $v2 = $b[$c] ?? null;
            $mid[$c] = (is_numeric($v1) && is_numeric($v2)) ? (($v1 + $v2) / 2) : null;
        }
        return $mid;
    }

    public function currentConditionsView(string $tz = 'America/New_York'): array
    {
        $nowUtc   = Format::UTC_time();
        $nowLocal = Format::toLocalTime($nowUtc, $tz);

        $r12 = $this->stations->latestStationRow('41112', $nowUtc);
        $r17 = $this->stations->latestStationRow('41117', $nowUtc);
        if (!$r12 && !$r17) {
            return [
                'now_local' => $nowLocal,
                'current_hm_label' => Format::localHm($nowUtc, $tz),
                'next_tide_in_label' => '—',
                'rows' => [],
            ];
        }
        if (!$r12) $r12 = $r17;
        if (!$r17) $r17 = $r12;

        $mid = $this->midpoint($r12, $r17);

        // Build ordered rows
        $rows = [
            '41112'  => ['key' => '41112',  'label' => 'St. Marys Entrance',                'data' => $r12],
            'median' => ['key' => 'median', 'label' => 'St. Johns Approach (interpolated)', 'data' => $mid],
            '41117'  => ['key' => '41117',  'label' => 'St. Augustine',                     'data' => $r17],
        ];

        foreach ($rows as $k => $r) {
            $tid = self::tideStationIdForKey($k);
            $rows[$k]['wave_cell']          = $this->waveCell->dominantCell($r['data']);
            $rows[$k]['tide_label_current'] = $tid ? $this->tideCell->currentLabel($tid, $nowUtc) : '—';

            $info = $tid ? $this->tideCell->nextPeakInfo($tid, $nowUtc, $tz) : null;
            $rows[$k]['tide_next_at']       = $info['row_label'] ?? '—';
            $rows[$k]['_next_minutes']      = $info['minutes'] ?? null;

            $rows[$k]['wind_label']         = '—'; // wire Wind hook later
        }

        // Header countdown uses median minutes, else min of flank minutes
        $mins = $rows['median']['_next_minutes'] ?? null;
        if ($mins === null) {
            $c = [];
            if (isset($rows['41112']['_next_minutes'])) $c[] = $rows['41112']['_next_minutes'];
            if (isset($rows['41117']['_next_minutes'])) $c[] = $rows['41117']['_next_minutes'];
            $mins = !empty($c) ? min($c) : null;
        }

        return [
            'now_local'          => $nowLocal,
            'current_hm_label'   => Format::localHm($nowUtc, $tz),
            'next_tide_in_label' => ($mins !== null) ? Format::minutesToHm((int)$mins) : '—',
            'rows'               => $rows,
        ];
    }

    public function whereToSurfNowView(string $tz = 'America/New_York'): array
    {
        $nowUtc   = Format::UTC_time();
        $headerHm = Format::localHm($nowUtc, $tz);

        $r12 = $this->stations->latestStationRow('41112', $nowUtc);
        $r17 = $this->stations->latestStationRow('41117', $nowUtc);
        if (!$r12 && !$r17) {
            return ['header_hm' => $headerHm, 'best' => [], 'others' => [], 'message' => 'Conditions are less than optimal at this time.'];
        }
        if (!$r12) $r12 = $r17;
        if (!$r17) $r17 = $r12;

        $results = $this->selector->select($this->pdo, $r12, $r17);
        if (empty($results)) {
            return ['header_hm' => $headerHm, 'best' => [], 'others' => [], 'message' => 'Conditions are less than optimal at this time.'];
        }

        $best = [];
        $others = [];
        foreach ($results as $r) {
            $name = $r['spot_name'] ?? $r['name'] ?? 'Unnamed Spot';
            $wvht = isset($r['WVHT']) ? (float)$r['WVHT'] : null;
            $dp   = isset($r['dominant_period']) ? (float)$r['dominant_period'] : null;
            $mwd  = isset($r['interpolated_mwd']) ? (float)$r['interpolated_mwd'] : null;

            // Use WaveCell for consistent display (keeps Format downstream)
            $waveCell = (new WaveCell())->dominantCell([
                'WVHT' => $wvht,
                'MWD' => $mwd,
                'SwP' => $dp,
                'WWP' => $dp,
                'APD' => $dp
            ]);

            $row = [
                'name'      => $name,
                'wave_cell' => $waveCell,
                'tide'      => $r['tide_note'] ?? '—',
                'wind'      => '—',
            ];
            (($r['list_bucket'] ?? '2') === '1') ? $best[] = $row : $others[] = $row;
        }

        usort($best, fn($a, $b) => strcmp($a['name'], $b['name']));
        usort($others, fn($a, $b) => strcmp($a['name'], $b['name']));

        return ['header_hm' => $headerHm, 'best' => $best, 'others' => $others];
    }

    public function whereToSurfLaterTodayView(): array
    {
        $rows = $this->selector->selectForecastLaterToday($this->pdo);
        $tz = 'America/New_York';
        $date = (new DateTime('now', new DateTimeZone($tz)))->format('m/d/Y');
        return ['header_date' => $date, 'rows' => $rows];
    }

    public function whereToSurfTomorrowView(): array
    {
        $rows = $this->selector->selectForecastTomorrow($this->pdo);
        $tz = 'America/New_York';
        $date = (new DateTime('tomorrow', new DateTimeZone($tz)))->format('m/d/Y');
        return ['header_date' => $date, 'rows' => $rows];
    }
}
