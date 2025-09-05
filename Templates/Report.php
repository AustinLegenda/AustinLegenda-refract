<?php

declare(strict_types=1);

namespace Legenda\NormalSurf\Templates;

use PDO;
use Legenda\NormalSurf\Repositories\StationRepo;
use Legenda\NormalSurf\BatchProcessing\ImportCC;
use Legenda\NormalSurf\Hooks\WaveCell;
use Legenda\NormalSurf\Hooks\TideCell;
use Legenda\NormalSurf\Hooks\WindCell;
use Legenda\NormalSurf\Hooks\SpotSelector;
use Legenda\NormalSurf\Helpers\Format;
use Legenda\NormalSurf\Utilities\ForecastPreference;
use Legenda\NormalSurf\Infra\Db;

final class Report
{
    /** Single, central PDO for all DB work in this request/CLI run */
    private readonly PDO $pdo;

    private StationRepo $stations;
    private WaveCell $waveCell;
    private TideCell $tideCell;
    private SpotSelector $selector;

    private const TIDE_STATION_BY_KEY = [
        '41112'  => '8720030',
        'median' => '8720218',
        '41117'  => '8720587',
    ];

    private const WIND_STATION_BY_KEY = [
        '41112'  => '8720030', // Fernandina
        'median' => '8720218', // Mayport / St. Johns Entrance
        '41117'  => 'SAUF1',   // St. Augustine
    ];

    public function __construct(?PDO $pdo = null)
    {
        // One PDO to rule them all
        $this->pdo = $pdo ?? Db::get();

        // Upstream side effects (explicitly use the same PDO)
        ImportCC::conn_report(['41112', '41117'], $this->pdo);
        ImportCC::conn_winds(['8720030', '8720218', 'SAUF1'], 300, $this->pdo);

        // Repos/cells wired to the same PDO as needed
        $this->stations = new StationRepo($this->pdo);
        $this->waveCell = new WaveCell();
        $this->tideCell = new TideCell($this->pdo);
        $this->selector = new SpotSelector($this->pdo);
    }

    private static function tideStationIdForKey(int|string $key): ?string
    {
        $k = (string)$key;
        return self::TIDE_STATION_BY_KEY[$k] ?? null;
    }

    private static function windStationCodeForKey(int|string $key): ?string
    {
        $k = (string)$key;
        return self::WIND_STATION_BY_KEY[$k] ?? null;
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

            // Wave: format from buoy row
            $rows[$k]['wave_cell'] = $this->waveCell->cellFromBuoyRow($r['data']);

            // Tide: current + next peak
            $rows[$k]['tide_label_current'] = $tid ? $this->tideCell->currentLabel($tid, $nowUtc) : '—';
            $info = $tid ? $this->tideCell->nextPeakInfo($tid, $nowUtc, $tz) : null;
            $rows[$k]['tide_next_at']  = $info['row_label'] ?? '—';
            $rows[$k]['_next_minutes'] = $info['minutes']   ?? null;

            // Wind (latest obs)
            $wcode = self::windStationCodeForKey($k);
            if ($wcode) {
                $w = ImportCC::winds_latest($this->pdo, $wcode);
                if ($w) {
                    $rows[$k]['wind_label'] = WindCell::format(
                        isset($w['WDIR']) ? (int)$w['WDIR'] : null,
                        isset($w['WSPD_kt']) ? (float)$w['WSPD_kt'] : null
                    );
                } else {
                    $rows[$k]['wind_label'] = '—';
                }
            } else {
                $rows[$k]['wind_label'] = '—';
            }
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

        $results = $this->selector->select($r12, $r17);
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
                'wind'      => $r['wind'] ?? '—',
            ];

            (($r['list_bucket'] ?? '2') === '1') ? $best[] = $row : $others[] = $row;
        }

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
        $rows = $this->selector->selectForecastLaterToday();
        $rows = $this->selector->selectForecastTomorrow();
        $nowUtc = Format::UTC_time();

        foreach ($rows as &$r) {
            $r['wave_cell'] = $this->waveCell->cellFromForecastRow($r);
            $r['tide']      = $this->tideCell->prefCellFromSelectorRow($r, $nowUtc, $tz, 'later');
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
        $nowUtc = Format::UTC_time();

        foreach ($rows as &$r) {
            $r['wave_cell'] = $this->waveCell->cellFromForecastRow($r);
            $r['tide']      = $this->tideCell->prefCellFromSelectorRow($r, $nowUtc, $tz, 'later');
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
    // Forecast (72h)
    // ——————————————————————————————————————————————————————————
    public function forecast72hView(string $tz = 'America/New_York'): array
    {
        $nowUtc = Format::UTC_time();

        $c12 = $this->stations->coords('41112');
        $c17 = $this->stations->coords('41117');
        if (!$c12 || !$c17) return ['stations' => []];
        $coordsMany = $this->stations->coordsMany(['41112', '41117']);

        $zones = [
            '41112'  => [
                'label'        => 'St. Marys Entrance',
                'coord'        => $c12,
                'tide_station' => self::TIDE_STATION_BY_KEY['41112'] ?? null,
                'wind_key'     => '41112',
            ],
            'median' => [
                'label'        => 'St. Johns Entrance*',
                'coord'        => [
                    'lat' => ($c12['lat'] + $c17['lat']) / 2,
                    'lon' => ($c12['lon'] + $c17['lon']) / 2
                ],
                'tide_station' => self::TIDE_STATION_BY_KEY['median'] ?? null,
                'wind_key'     => 'median',
            ],
            '41117'  => [
                'label'        => 'St. Augustine',
                'coord'        => $c17,
                'tide_station' => self::TIDE_STATION_BY_KEY['41117'] ?? null,
                'wind_key'     => '41117',
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
                12,
                $z['wind_key'] ?? null
            );

            $rows = [];
            foreach ($samples as $s) {
                $windLabel = WindCell::format(
                    isset($s['wind_dir']) ? (int)$s['wind_dir'] : null,
                    isset($s['wind_kt'])  ? (float)$s['wind_kt']  : null
                );

                $rows[] = [
                    'when'      => Format::toLocalTime($s['t_utc'], $tz),
                    'wave_cell' => $this->waveCell->cellFromDTO([
                        'hs_m'    => $s['hs_m'],
                        'per_s'   => $s['per_s'],
                        'dir_deg' => $s['dir_deg'],
                    ]),
                    'tide'      => ($s['hl_type'] === 'H') ? 'High' : 'Low',
                    'wind'      => $windLabel,
                ];
            }

            $out[] = ['label' => $z['label'], 'rows' => $rows];
        }

        return ['stations' => $out];
    }
}
