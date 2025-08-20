<?php
declare(strict_types=1);

namespace Legenda\NormalSurf\BatchProcessing;

/**
 * NDBC .txt wind parser (realtime2/<STATION>.txt)
 * - Skips comment/# lines
 * - Auto-detects header & units line (if present)
 * - Extracts WDIR (deg true) and WSPD (m/s or kt) → returns both ms & kt
 * - Normalizes timestamp to 'YYYY-MM-DD HH:MM:00' UTC
 *
 * Returns:
 * [
 *   'columns' => [...],
 *   'units'   => ['WDIR' => 'degT', 'WSPD' => 'm/s'|'kt'|null],
 *   'data'    => [
 *      ['ts' => '2025-08-20 15:10:00', 'WDIR' => 100, 'WSPD_ms' => 5.7, 'WSPD_kt' => 11.1],
 *      ...
 *   ]
 * ]
 */
final class NDBCWindParser
{
    /** Convert m/s → knots */
    private static function msToKt(?float $v): ?float
    {
        return is_null($v) ? null : round($v * 1.943844, 2);
    }

    /** Convert knots → m/s */
    private static function ktToMs(?float $v): ?float
    {
        return is_null($v) ? null : round($v / 1.943844, 2);
    }

    /** True if NDBC "missing" token */
    private static function isMissing(?string $raw): bool
    {
        if ($raw === null) return true;
        $s = strtoupper(trim($raw));
        return ($s === 'MM' || $s === 'M' || $s === 'N/A' || $s === '');
    }

    /**
     * Parse NDBC TXT lines.
     * Accepts either:
     *   #YY MM DD hh mm WDIR WSPD ...
     *   #YYYY MM DD hh mm WDIR WSPD ...
     * and a units row like:
     *   #yr  mo  dy hr mn degT m/s ...
     */
    public static function parse(array $lines): array
    {
        $cols = [];
        $units = [];
        $headerRow = null;
        $dataStart = null;

        // 1) Find header row
        foreach ($lines as $i => $line) {
            $t = trim($line);
            if ($t === '' || $t[0] === '#') {
                // If this looks like a header, capture columns
                if (preg_match('/^\s*#\s*(YY|YYYY)\s+MM\s+DD\s+hh\s+mm\b/i', $t)) {
                    // Strip leading '#'
                    $cols = preg_split('/\s+/', trim(preg_replace('/^#\s*/', '', $t)));
                    $headerRow = $i;
                    // Try to read units on the following line if it is a '#' line
                    $maybeUnits = $lines[$i+1] ?? '';
                    if (is_string($maybeUnits) && strlen($maybeUnits) && $maybeUnits[0] === '#') {
                        $unitTokens = preg_split('/\s+/', trim(preg_replace('/^#\s*/', '', $maybeUnits)));
                        // Map units by position; not all columns will have known units
                        foreach ($cols as $idx => $c) {
                            $units[$c] = $unitTokens[$idx] ?? null;
                        }
                        $dataStart = $i + 2;
                    } else {
                        $dataStart = $i + 1;
                    }
                }
                continue;
            }
        }

        if (!$cols) {
            throw new \RuntimeException('NDBCWindParser: header not found in TXT.');
        }
        if ($dataStart === null) {
            throw new \RuntimeException('NDBCWindParser: could not locate first data row.');
        }

        // 2) Ensure we can locate fields we need
        $idxMap = [];
        $want = ['YY','YYYY','MM','DD','hh','mm','WDIR','WSPD'];
        foreach ($cols as $i => $c) {
            if (in_array($c, $want, true)) {
                $idxMap[$c] = $i;
            }
        }

        // Accept either YY or YYYY in the header
        $hasYYYY = array_key_exists('YYYY', $idxMap);
        $hasYY   = array_key_exists('YY', $idxMap);
        foreach (['MM','DD','hh','mm','WSPD'] as $must) {
            if (!array_key_exists($must, $idxMap)) {
                throw new \RuntimeException("NDBCWindParser: required column {$must} not found.");
            }
        }
        // WDIR is commonly present; if missing, we return nulls for direction
        $hasWDIR = array_key_exists('WDIR', $idxMap);

        // Unit detection for WSPD (default to m/s if ambiguous)
        $wspdUnit = null;
        if (isset($units['WSPD'])) {
            $u = strtolower((string)$units['WSPD']);
            if (str_contains($u, 'm/s')) $wspdUnit = 'm/s';
            elseif ($u === 'kt' || str_contains($u, 'knot')) $wspdUnit = 'kt';
        }
        $wspdUnit = $wspdUnit ?: 'm/s';

        $data = [];
        $n = count($lines);
        for ($i = $dataStart; $i < $n; $i++) {
            $line = trim($lines[$i]);
            if ($line === '' || $line[0] === '#') continue;

            $vals = preg_split('/\s+/', $line);
            // sanity
            if (count($vals) < count($cols)) continue;

            $YYorYYYY = $hasYYYY ? $vals[$idxMap['YYYY']] : ($hasYY ? $vals[$idxMap['YY']] : null);
            if (self::isMissing($YYorYYYY)) continue;

            $year = (int)$YYorYYYY;
            if ($hasYY && !$hasYYYY) {
                // YY → YYYY (assume 2000+; NDBC txt is modern)
                $year = ($year < 100) ? (2000 + $year) : $year;
            }

            $month  = (int)$vals[$idxMap['MM']];
            $day    = (int)$vals[$idxMap['DD']];
            $hour   = (int)$vals[$idxMap['hh']];
            $minute = (int)$vals[$idxMap['mm']];

            // Build UTC timestamp (NDBC times are UTC in realtime2)
            $ts = sprintf('%04d-%02d-%02d %02d:%02d:00', $year, $month, $day, $hour, $minute);

            // Direction
            $wdir = null;
            if ($hasWDIR) {
                $rawDir = $vals[$idxMap['WDIR']] ?? null;
                $wdir = self::isMissing($rawDir) ? null : (int)$rawDir;
                // Normalize to [0,360)
                if (!is_null($wdir)) {
                    $wdir = ($wdir % 360 + 360) % 360;
                }
            }

            // Speed
            $rawSpd = $vals[$idxMap['WSPD']] ?? null;
            $spd = self::isMissing($rawSpd) ? null : (float)$rawSpd;

            $spd_ms = null;
            $spd_kt = null;
            if (!is_null($spd)) {
                if ($wspdUnit === 'm/s') {
                    $spd_ms = round($spd, 2);
                    $spd_kt = self::msToKt($spd_ms);
                } else { // 'kt'
                    $spd_kt = round($spd, 2);
                    $spd_ms = self::ktToMs($spd_kt);
                }
            }

            $data[] = [
                'ts'       => $ts,    // UTC
                'WDIR'     => $wdir,  // deg true (int|null)
                'WSPD_ms'  => $spd_ms,
                'WSPD_kt'  => $spd_kt,
            ];
        }

        return [
            'columns' => $cols,
            'units'   => [
                'WDIR' => 'degT',
                'WSPD' => $wspdUnit,
            ],
            'data'    => $data,
        ];
    }

    /**
     * Convenience helper: return only the compact rows array for ingestion.
     * [
     *   ['ts'=>'...', 'WDIR'=>int|null, 'WSPD_ms'=>float|null, 'WSPD_kt'=>float|null],
     *   ...
     * ]
     */
    public static function rows(array $lines): array
    {
        $parsed = self::parse($lines);
        return $parsed['data'];
    }
}
