<?php

declare(strict_types=1);

namespace Legenda\NormalSurf\Hooks;

use PDO;
use DateTime;
use DateTimeZone;

use Legenda\NormalSurf\Helpers\Maths;
use Legenda\NormalSurf\Utilities\TidePreference;
use Legenda\NormalSurf\Utilities\TidePhase;
use Legenda\NormalSurf\Utilities\WavePreference;   
use Legenda\NormalSurf\Repositories\StationRepo;

final class SpotSelector
{
    private WaveCell $waveCell;

    public function __construct(private ?TidePreference $tidePrefs = null)
    {
        // Downstream default so Report doesn't need Utilities
        $this->tidePrefs ??= new TidePreference(new TidePhase());
        $this->waveCell = new WaveCell();
    }

    /**
     * Selection for "Where To Surf Now" using two realtime station rows.
     * Returns raw, presentation-free data only (plus wave_cell string for convenience).
     */
    public function select(PDO $pdo, array $data1, array $data2): array
    {
        $rows = [];

        // candidate spots
        $stmtSpots = $pdo->query("
            SELECT
                s.id, s.spot_name,
                r.region_lat, r.region_lon,
                s.period_min, s.period_max,
                s.dir_min, s.dir_max,
                s.H_tide, s.M_plus_tide, s.M_minus_tide, s.L_tide
            FROM surf_spots AS s
            INNER JOIN regions AS r ON s.regional_id = r.id
        ");
        $spots = $stmtSpots->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // station coords
        $stations = new StationRepo($pdo);
        $c12 = $stations->coords('41112');
        $c17 = $stations->coords('41117');
        if (!$c12 || !$c17) {
            return [];
        }

        // canonical UTC now
        $nowUtc = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:00');

        foreach ($spots as $spot) {
            // wave gate
            $W = WavePreference::realtimeForSpot($spot, $data1, $data2, $c12, $c17);
            if (!$W['ok']) {
                // (optional) capture gated reasons to help debug UI
                // e.g., echo "<!-- {$spot['spot_name']} gated: {$W['gate_reason']} -->\n";
                continue;
            }

            // tide gate
            $tp = $this->tidePrefs->tidePrefMatch($pdo, $spot, $nowUtc, 60);

            $hasPrefs = !empty($spot['H_tide']) || !empty($spot['M_plus_tide']) ||
                !empty($spot['M_minus_tide']) || !empty($spot['L_tide']);

            $listBucket = (!empty($tp['ok']) && $hasPrefs) ? '1' : '2';

            // build row (wave_cell stays here — view concern)
            $rows[] = [
                'spot_id'   => $spot['id'],
                'spot_name' => $spot['spot_name'],

                // wave
                'hs_m'      => $W['hs_m'],
                'per_s'     => $W['per_s'],
                'dir_deg'   => $W['dir_deg'],

                // debug geometry
                'interpolated_mwd' => $W['interpolated_mwd'],
                'dominant_period'  => $W['dominant_period'],
                'dist_41112'       => $W['dist_41112'],
                'dist_41117'       => $W['dist_41117'],

                'wave_cell' => $this->waveCell->dominantCell([
                    'WVHT' => $W['hs_m'],
                    'MWD'  => $W['dir_deg'],
                    'SwP'  => $W['per_s'],
                    'WWP'  => $W['per_s'],
                    'APD'  => $W['per_s'],
                ]),

                // tide (raw)
                'tide'      => $tp['tide_reason'] ?? $tp['next_pref'] ?? $tp['next_marker'] ?? '—',
                'wind'      => '—',

                'tide_ok'                => (bool)($tp['ok'] ?? false),
                'phase_code'             => $tp['tide_reason'] ?? $tp['next_pref'] ?? $tp['next_marker'] ?? null,
                'phase_time_utc'         => $tp['tide_reason_utc'] ?? $tp['next_pref_utc'] ?? $tp['next_marker_utc'] ?? null,
                'closest_pref_delta_min' => $tp['closest_pref_delta_min'] ?? null,

                // extras passthrough
                'has_tide_prefs'   => $hasPrefs,
                'next_pref'        => $tp['next_pref'] ?? null,
                'next_pref_utc'    => $tp['next_pref_utc'] ?? null,
                'next_marker'      => $tp['next_marker'] ?? null,
                'next_marker_utc'  => $tp['next_marker_utc'] ?? null,

                // decision
                'list_bucket' => $listBucket,
            ];
        }

        return $rows;
    }

    /**
     * Forecast-driven selection for "Where To Surf Later Today".
     * Lean on WavePreference + TidePreference.
     */
    public function selectForecastLaterToday(PDO $pdo): array
    {
        $stmt = $pdo->query("
            SELECT s.*, r.region_lat, r.region_lon
            FROM surf_spots AS s
            INNER JOIN regions AS r ON s.regional_id = r.id
            ORDER BY s.spot_name ASC, s.id ASC
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$rows) return [];

        $stations = new StationRepo($pdo);
        $coords   = $stations->coordsMany(['41112', '41117']);
        if (count($coords) < 2) return [];

        // minutes left in local day
        $tz       = new DateTimeZone('America/New_York');
        $nowL     = new DateTime('now', $tz);
        $endL     = (clone $nowL)->setTime(23, 59, 59);
        $minsLeft = max(0, (int) floor(($endL->getTimestamp() - $nowL->getTimestamp()) / 60));

        $nowUtc = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:00');

        $bestByName = []; // name => ['row'=>raw, 'score'=>float]

        foreach ($rows as $spot) {
            $name  = $spot['spot_name'] ?? 'Unknown spot';
            $tp    = $this->tidePrefs->tidePrefMatch($pdo, $spot, $nowUtc, 60);
            $delta = $tp['closest_pref_delta_min'] ?? null;

            if ($delta === null || $delta < 0 || $delta > $minsLeft) {
                // gated: no preferred tide in remainder of day
                continue;
            }

            $targetUtc = (new DateTime($nowUtc, new DateTimeZone('UTC')))
                ->modify('+' . (int)$delta . ' minutes')
                ->format('Y-m-d H:i:00');

            $WF = WavePreference::forecastForSpot($pdo, $spot, $targetUtc, $coords, ['41112', '41117']);
            if (!$WF['ok']) {
                continue;
            }

            $tideCode = $tp['next_pref'] ?? $tp['closest_pref'] ?? $tp['next_marker'] ?? null;
            if (!TidePreference::allowPhase($spot, $tideCode)) {
                continue;
            }

            $pmin = isset($spot['period_min']) ? (float)$spot['period_min'] : null;
            $pmax = isset($spot['period_max']) ? (float)$spot['period_max'] : null;
            $dmin = isset($spot['dir_min'])    ? (float)$spot['dir_min']    : null;
            $dmax = isset($spot['dir_max'])    ? (float)$spot['dir_max']    : null;

            $score = Maths::matchScore((float)$WF['per_s'], (float)$WF['dir_deg'], $pmin, $pmax, $dmin, $dmax, 0.1);

            $raw = [
                'spot_name' => $name,
                'hs_m'      => $WF['hs_m'],
                'per_s'     => $WF['per_s'],
                'dir_deg'   => $WF['dir_deg'],
                'wave_cell' => $this->waveCell->dominantCell([
                    'WVHT' => $WF['hs_m'],
                    'MWD'  => $WF['dir_deg'],
                    'SwP'  => $WF['per_s'],
                    'WWP'  => $WF['per_s'],
                    'APD'  => $WF['per_s'],
                ]),
                'tide'                  => $tideCode ?? '—',
                'wind'                  => '—',
                'phase_code'            => $tideCode,
                'phase_time_utc'        => $tp['next_pref_utc'] ?? $tp['next_marker_utc'] ?? null,
                'closest_pref_delta_min' => $tp['closest_pref_delta_min'] ?? null,
            ];

            if (!isset($bestByName[$name]) || $score < $bestByName[$name]['score']) {
                $bestByName[$name] = ['row' => $raw, 'score' => $score];
            }
        }

        $out = array_map(static fn($x) => $x['row'], $bestByName);
        usort($out, static fn($a, $b) => strcmp($a['spot_name'], $b['spot_name']));
        return $out;
    }

    /**
     * Forecast-driven selection for "Where To Surf Tomorrow".
     */
    public function selectForecastTomorrow(PDO $pdo): array
    {
        $stmt = $pdo->query("
            SELECT s.*, r.region_lat, r.region_lon
            FROM surf_spots AS s
            INNER JOIN regions AS r ON s.regional_id = r.id
            ORDER BY s.spot_name ASC, s.id ASC
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$rows) return [];

        $stations = new StationRepo($pdo);
        $coords   = $stations->coordsMany(['41112', '41117']);
        if (count($coords) < 2) return [];

        // local start of tomorrow -> UTC pivot
        $pivotUtc = (new DateTime('tomorrow 00:00', new DateTimeZone('America/New_York')))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:00');

        $bestByName = [];

        foreach ($rows as $spot) {
            $name  = $spot['spot_name'] ?? 'Unknown spot';
            $tp    = $this->tidePrefs->tidePrefMatch($pdo, $spot, $pivotUtc, 60);
            $delta = $tp['closest_pref_delta_min'] ?? null;

            if ($delta === null || $delta < 0 || $delta >= 24 * 60) {
                continue;
            }

            $targetUtc = (new DateTime($pivotUtc, new DateTimeZone('UTC')))
                ->modify('+' . (int)$delta . ' minutes')
                ->format('Y-m-d H:i:00');

            $WF = WavePreference::forecastForSpot($pdo, $spot, $targetUtc, $coords, ['41112', '41117']);
            if (!$WF['ok']) {
                continue;
            }

            $tideCode = $tp['next_pref'] ?? $tp['closest_pref'] ?? $tp['next_marker'] ?? null;
            if (!TidePreference::allowPhase($spot, $tideCode)) {
                continue;
            }

            $pmin = isset($spot['period_min']) ? (float)$spot['period_min'] : null;
            $pmax = isset($spot['period_max']) ? (float)$spot['period_max'] : null;
            $dmin = isset($spot['dir_min'])    ? (float)$spot['dir_min']    : null;
            $dmax = isset($spot['dir_max'])    ? (float)$spot['dir_max']    : null;

            $score = Maths::matchScore((float)$WF['per_s'], (float)$WF['dir_deg'], $pmin, $pmax, $dmin, $dmax, 0.1);

            $raw = [
                'spot_name' => $name,
                'hs_m'      => $WF['hs_m'],
                'per_s'     => $WF['per_s'],
                'dir_deg'   => $WF['dir_deg'],
                'wave_cell' => $this->waveCell->dominantCell([
                    'WVHT' => $WF['hs_m'],
                    'MWD'  => $WF['dir_deg'],
                    'SwP'  => $WF['per_s'],
                    'WWP'  => $WF['per_s'],
                    'APD'  => $WF['per_s'],
                ]),
                'tide'                  => $tideCode ?? '—',
                'wind'                  => '—',
                'phase_code'     => $tideCode,
                'phase_time_utc' => $tp['next_pref_utc'] ?? $tp['next_marker_utc'] ?? null,
                'closest_pref_delta_min' => $tp['closest_pref_delta_min'] ?? null,

            ];

            if (!isset($bestByName[$name]) || $score < $bestByName[$name]['score']) {
                $bestByName[$name] = ['row' => $raw, 'score' => $score];
            }
        }

        $out = array_map(static fn($x) => $x['row'], $bestByName);
        usort($out, static fn($a, $b) => strcmp($a['spot_name'], $b['spot_name']));
        return $out;
    }
}
