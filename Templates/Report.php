<?php

declare(strict_types=1);

namespace Legenda\NormalSurf\Templates;

use PDO;
use DateTime;
use DateTimeZone;

use Legenda\NormalSurf\Repositories\StationRepo;
use Legenda\NormalSurf\Hooks\LoadData;
use Legenda\NormalSurf\Hooks\WaveCell;
use Legenda\NormalSurf\Hooks\TideCell;
use Legenda\NormalSurf\Hooks\SpotSelector;
use Legenda\NormalSurf\Helpers\Format;
use Legenda\NormalSurf\Utilities\WavePreference;
use Legenda\NormalSurf\Utilities\ForecastPreference;


/**
 * View-model builder for index.php
 * - Preferences (Wave/Tide/Wind) compute raw values upstream.
 * - SpotSelector gates & returns presentation-free rows.
 * - Cells (WaveCell/TideCell/…) format here in Report for consistent UI.
 */
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
        $this->waveCell = new WaveCell();                 // formatter only
        $this->tideCell = new TideCell($this->pdo);       // formatter only
        $this->selector = new SpotSelector();             // gates & selects
    }

    private static function tideStationIdForKey(int|string $key): ?string
    {
        $k = (string)$key;
        return self::TIDE_STATION_BY_KEY[$k] ?? null;
    }

    /** Mean of shared numeric columns for a “median” buoy row. */
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

    // ——————————————————————————————————————————————————————————
    // Current Conditions
    // ——————————————————————————————————————————————————————————
    public function currentConditionsView(string $tz = 'America/New_York'): array
    {
        $nowUtc   = Format::UTC_time();
        $nowLocal = Format::toLocalTime($nowUtc, $tz);

        $r12 = $this->stations->latestStationRow('41112', $nowUtc);
        $r17 = $this->stations->latestStationRow('41117', $nowUtc);

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

        $mid = $this->midpoint($r12, $r17);

        $rows = [
            '41112'  => ['key' => '41112',  'label' => 'St. Marys Entrance',                'data' => $r12],
            'median' => ['key' => 'median', 'label' => 'St. Johns Approach (interpolated)', 'data' => $mid],
            '41117'  => ['key' => '41117',  'label' => 'St. Augustine',                     'data' => $r17],
        ];

        foreach ($rows as $k => $r) {
            $tid = self::tideStationIdForKey($k);

            // Wave: format from buoy row (includes dominant-period logic via WavePeriod)
            $rows[$k]['wave_cell'] = $this->waveCell->cellFromBuoyRow($r['data']);

            // Tide: current label + next peak info
            $rows[$k]['tide_label_current'] = $tid ? $this->tideCell->currentLabel($tid, $nowUtc) : '—';

            $info = $tid ? $this->tideCell->nextPeakInfo($tid, $nowUtc, $tz) : null;
            $rows[$k]['tide_next_at']  = $info['row_label'] ?? '—';
            $rows[$k]['_next_minutes'] = $info['minutes']   ?? null;

            // Wind: wire later
            $rows[$k]['wind_label'] = '—';
        }

        // Countdown header: prefer median else min flank minutes
        $mins = $rows['median']['_next_minutes'] ?? null;
        if ($mins === null) {
            $cand = [];
            if (isset($rows['41112']['_next_minutes'])) $cand[] = $rows['41112']['_next_minutes'];
            if (isset($rows['41117']['_next_minutes'])) $cand[] = $rows['41117']['_next_minutes'];
            $mins = $cand ? min($cand) : null;
        }

        return [
            'now_local'          => $nowLocal,
            'current_hm_label'   => Format::localHm($nowUtc, $tz),
            'next_tide_in_label' => ($mins !== null) ? Format::minutesToHm((int)$mins) : '—',
            'rows'               => $rows,
        ];
    }

    // ——————————————————————————————————————————————————————————
    // Where To Surf Now
    // ——————————————————————————————————————————————————————————
    public function whereToSurfNowView(string $tz = 'America/New_York'): array
    {
        $nowUtc   = Format::UTC_time();
        $headerHm = Format::localHm($nowUtc, $tz);

        $r12 = $this->stations->latestStationRow('41112', $nowUtc);
        $r17 = $this->stations->latestStationRow('41117', $nowUtc);

        if (!$r12 && !$r17) {
            return [
                'header_hm' => $headerHm,
                'best'      => [],
                'others'    => [],
                'message'   => 'Conditions are less than optimal at this time.',
            ];
        }
        if (!$r12) $r12 = $r17;
        if (!$r17) $r17 = $r12;

        $results = $this->selector->select($this->pdo, $r12, $r17);
        if (empty($results)) {
            return [
                'header_hm' => $headerHm,
                'best'      => [],
                'others'    => [],
                'message'   => 'Conditions are less than optimal at this time.',
            ];
        }

        $best = [];
        $others = [];

        foreach ($results as $r) {
            $name = $r['spot_name'] ?? $r['name'] ?? 'Unnamed Spot';

            // Build wave cell from normalized DTO (realtime selection output)
            $waveCell = $this->waveCell->cellFromDTO([
                'hs_m'    => $r['hs_m']    ?? null,
                'per_s'   => $r['per_s']   ?? ($r['dominant_period']   ?? null),
                'dir_deg' => $r['dir_deg'] ?? ($r['interpolated_mwd'] ?? null),
            ]);
            $tide = $this->tideCell->prefCellFromSelectorRow($r, $nowUtc, $tz);
            $row = [
                'name'      => $name,
                'wave_cell' => $waveCell ?? '&mdash;',
                'tide'      => $tide,
                'wind'      => $r['wind'] ?? '—',   // wire later
            ];

            (($r['list_bucket'] ?? '2') === '1') ? $best[] = $row : $others[] = $row;
        }

        // stable order
        usort($best, static fn($a, $b) => strcmp($a['name'], $b['name']));
        usort($others, static fn($a, $b) => strcmp($a['name'], $b['name']));

        return [
            'header_hm' => $headerHm,
            'best'      => $best,
            'others'    => $others,
        ];
    }

    // ——————————————————————————————————————————————————————————
    // Where To Surf Later Today
    // ——————————————————————————————————————————————————————————
    public function whereToSurfLaterTodayView(string $tz = 'America/New_York'): array
    {
        $rows   = $this->selector->selectForecastLaterToday($this->pdo);
        $nowUtc = Format::UTC_time(); // once per view

        foreach ($rows as &$r) {

            $r['wave_cell'] = $this->waveCell->cellFromForecastRow($r);
            $r['tide'] = $this->tideCell->prefCellFromSelectorRow($r, $nowUtc, $tz, 'later');
            $r['wind']      = $r['wind'] ?? '—';
        }
        unset($r);

        $date = (new \DateTime('now', new \DateTimeZone($tz)))->format('m/d/Y');

        return [
            'header_date' => $date,
            'rows'        => $rows,
        ];
    }

    // ——————————————————————————————————————————————————————————
    // Where To Surf Tomorrow
    // ——————————————————————————————————————————————————————————
    public function whereToSurfTomorrowView(string $tz = 'America/New_York'): array
    {
        $rows   = $this->selector->selectForecastTomorrow($this->pdo);
        $nowUtc = Format::UTC_time(); // once per view

        foreach ($rows as &$r) {
            $r['wave_cell'] = $this->waveCell->cellFromForecastRow($r);
            $r['tide'] = $this->tideCell->prefCellFromSelectorRow($r, $nowUtc, $tz, 'later');
            $r['wind']      = $r['wind'] ?? '—';
        }
        unset($r);

        $date = (new \DateTime('tomorrow', new \DateTimeZone($tz)))->format('m/d/Y');

        return [
            'header_date' => $date,
            'rows'        => $rows,
        ];
    }
    // ——————————————————————————————————————————————————————————
    // Forecast
    // ——————————————————————————————————————————————————————————
    private static function localLongWhen(string $utc, string $tz): string
    {
        $dt = new \DateTime($utc, new \DateTimeZone('UTC'));
        $dt->setTimezone(new \DateTimeZone($tz));
        return $dt->format('l, F j, g:i A'); // Tuesday, August 19, 5:21 PM
    }

    public function forecast72hView(string $tz = 'America/New_York'): array
    {
        $nowUtc = \Legenda\NormalSurf\Helpers\Format::UTC_time();

        // need base station coords for the zones + interpolation
        $c12 = $this->stations->coords('41112');
        $c17 = $this->stations->coords('41117');
        if (!$c12 || !$c17) return ['stations' => []];
        $coordsMany = $this->stations->coordsMany(['41112', '41117']); // used by sampler

        $zones = [
            '41112'  => [
                'label'        => 'St. Marys Entrance',
                'coord'        => $c12,
                'tide_station' => self::TIDE_STATION_BY_KEY['41112'] ?? null,
            ],
            'median' => [
                'label'        => 'St. Johns Entrance*',
                'coord'        => ['lat' => ($c12['lat'] + $c17['lat']) / 2, 'lon' => ($c12['lon'] + $c17['lon']) / 2],
                'tide_station' => self::TIDE_STATION_BY_KEY['median'] ?? null,
            ],
            '41117'  => [
                'label'        => 'St. Augustine',
                'coord'        => $c17,
                'tide_station' => self::TIDE_STATION_BY_KEY['41117'] ?? null,
            ],
        ];

        $out = [];
        foreach ($zones as $z) {
            if (!$z['tide_station']) continue;

            $samples = ForecastPreference::hlAnchoredForecastForStation(
                $this->pdo,
                $z['tide_station'],
                $z['coord'],
                $nowUtc,
                $coordsMany,
                ['41112', '41117'],
                72,
                12
            );

            $rows = [];
            foreach ($samples as $s) {
                $rows[] = [
                    'when'      => self::localLongWhen($s['t_utc'], $tz),
                    'wave_cell' => $this->waveCell->cellFromDTO([
                        'hs_m'    => $s['hs_m'],
                        'per_s'   => $s['per_s'],
                        'dir_deg' => $s['dir_deg'],
                    ]),
                    'tide'      => ($s['hl_type'] === 'H') ? 'High' : 'Low',
                ];
            }

            $out[] = ['label' => $z['label'], 'rows' => $rows];
        }

        return ['stations' => $out];
    }
}
