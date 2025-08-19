<?php
declare(strict_types=1);

namespace Legenda\NormalSurf\Hooks;

use PDO;
use DateTime;
use DateTimeZone;
use Legenda\NormalSurf\Utilities\TidePhase;
use Legenda\NormalSurf\Utilities\TidePreference;
use Legenda\NormalSurf\Repositories\TideRepo;
use Legenda\NormalSurf\Helpers\Format;

final class TideCell
{
    public function __construct(
        private PDO $pdo,
        private TidePhase $phase = new TidePhase(),
        private TidePreference $pref = new TidePreference(new TidePhase())
    ) {}

    /** Current tide state as a short, normalized label. */
    public function currentLabel(string $tideStationId, string $nowUtc): string
    {
        $st = $this->phase->stateForStation($this->pdo, $tideStationId, $nowUtc, 30);
        if (!$st || empty($st['state'])) return '—';

        return match (self::normalizeCode((string)$st['state'])) {
            'H'  => 'High',
            'L'  => 'Low',
            'IN' => 'Incoming',
            'OUT'=> 'Outgoing',
            default => '—',
        };
    }

    /**
     * Next H/L info for header use.
     * Returns ['minutes'=>int, 'row_label'=>string] or null.
     * row_label example: "High @ 3:14 PM"
     */
    public function nextPeakInfo(string $tideStationId, string $nowUtc, string $tz): ?array
    {
        $arr = TideRepo::getNextHL($this->pdo, $tideStationId, $nowUtc, 1);
        if (empty($arr)) return null;

        $row = $arr[0];
        if (empty($row['t_utc']) || empty($row['hl_type'])) return null;

        $now  = new DateTime($nowUtc, new DateTimeZone('UTC'));
        $when = new DateTime($row['t_utc'], new DateTimeZone('UTC'));
        $mins = max(0, (int) floor(($when->getTimestamp() - $now->getTimestamp()) / 60));

        $code  = self::normalizeCode((string)$row['hl_type']); // 'H' | 'L'
        $label = ($code === 'H') ? 'High' : 'Low';

        return [
            'minutes'   => $mins,
            'row_label' => $label . ' @ ' . Format::localHm($row['t_utc'], $tz),
        ];
    }

    /**
     * Formatter for rows coming out of SpotSelector (Now/Later/Tomorrow).
     * Accepts selector row fields and renders a consistent human string.
     *
     * Inputs it uses if present on $row:
     *   - 'phase_code'         e.g. 'H','L','M+','M-','Incoming','Outgoing'
     *   - 'phase_time_utc'     UTC timestamp for that phase/preference
     *   - 'closest_pref_delta_min'  minutes until that phase (optional, overrides calc)
     *   - 'next_pref'/'next_pref_utc' or 'next_marker'/'next_marker_utc' (fallbacks)
     *
     * Output examples:
     *   - "High now"
     *   - "High in 0:37 (3:14 PM)"
     *   - "Incoming"
     *   - "Mid+ in 1:05 (4:20 PM)"
     */
public function prefCellFromSelectorRow(
        array $row,
        string $nowUtc,
        string $tz,
        string $mode = 'now'
    ): string {
        // detect the best available code + time
        $code = $row['phase_code']
            ?? $row['tide']
            ?? $row['next_pref']
            ?? $row['next_marker']
            ?? null;

        $whenUtc = $row['phase_time_utc']
            ?? $row['next_pref_utc']
            ?? $row['next_marker_utc']
            ?? null;

        if ($code === null && $whenUtc === null) return '—';

        $norm  = self::normalizeCode((string)$code);
        $label = self::humanize($norm);

        // LATER/TOMORROW: always render "<Label> @ <local time>" (no countdown)
        if ($mode !== 'now') {
            return $whenUtc ? ($label . ' @ ' . Format::localHm((string)$whenUtc, $tz)) : $label;
        }

        // NOW mode (unchanged behavior)
        if ($whenUtc === null && !isset($row['closest_pref_delta_min'])) {
            return $label;
        }

        // compute minutes until whenUtc (or honor provided delta)
        $mins = null;
        if (isset($row['closest_pref_delta_min']) && is_numeric($row['closest_pref_delta_min'])) {
            $mins = max(0, (int)$row['closest_pref_delta_min']);
        } elseif ($whenUtc !== null) {
            $now  = new DateTime($nowUtc, new DateTimeZone('UTC'));
            $when = new DateTime((string)$whenUtc, new DateTimeZone('UTC'));
            $mins = max(0, (int) floor(($when->getTimestamp() - $now->getTimestamp()) / 60));
        }

        if ($mins === null) {
            return $whenUtc ? ($label . ' @ ' . Format::localHm((string)$whenUtc, $tz)) : $label;
        }
        if ($mins === 0) {
            return $label . ' now';
        }
        return sprintf('%s in %s (%s)', $label, Format::minutesToHm($mins), Format::localHm((string)$whenUtc, $tz));
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    /** Map a variety of codes/words to canonical codes: 'H','L','M+','M-','IN','OUT' */
    private static function normalizeCode(string $code): string
    {
        $c = strtoupper(trim($code));
        return match (true) {
            $c === 'H' || $c === 'HIGH'            => 'H',
            $c === 'L' || $c === 'LOW'             => 'L',
            $c === 'M+' || $c === 'MID+'           => 'M+',
            $c === 'M-' || $c === 'MID-'           => 'M-',
            $c === 'IN' || $c === 'INCOMING' || $c === 'FLOOD' => 'IN',
            $c === 'OUT' || $c === 'OUTGOING' || $c === 'EBB'  => 'OUT',
            default => $c,
        };
    }

    /** Human-friendly label for a canonical code. */
    private static function humanize(string $norm): string
    {
        return match ($norm) {
            'H'   => 'High',
            'L'   => 'Low',
            'M+'  => 'Mid Incoming',
            'M-'  => 'Mid Outgoing',
            'IN'  => 'Incoming',
            'OUT' => 'Outgoing',
            default => $norm, // fallback to whatever it was
        };
    }

    public function hlAtLabel(string $hlType, string $tideUtc, string $tz = 'America/New_York'): string
{
    $type = ($hlType === 'H') ? 'High' : 'Low';
    $dt = new \DateTime($tideUtc, new \DateTimeZone('UTC'));
    $dt->setTimezone(new \DateTimeZone($tz));
    // 12-hour, lowercase am/pm per your “...at 0:00AM/PM” requirement
    return $type . ' at ' . $dt->format('g:ia');
}

}
