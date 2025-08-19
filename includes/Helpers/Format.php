<?php

declare(strict_types=1);

namespace Legenda\NormalSurf\Helpers;

use Legenda\NormalSurf\Utilities\WavePeriod;

use Legenda\NormalSurf\Helpers\Maths;


final class Format
{
    /**
     * Human-friendly local time (12‑hour, no seconds).
     * Example: "Thursday, August 8, 4:05 PM"
     */
    public static function localLabel(string $utcTime, string $tz = 'America/New_York'): string
    {
        $dt = new \DateTime($utcTime, new \DateTimeZone('UTC'));
        $dt->setTimezone(new \DateTimeZone($tz));
        return $dt->format('l, F j, g:i A');
    }

    /**
     * Human-friendly clock (e.g., "4:00 PM") from UTC.
     */
    public static function localClock(string $utcTime, string $tz = 'America/New_York'): string
    {
        $dt = new \DateTime($utcTime, new \DateTimeZone('UTC'));
        $dt->setTimezone(new \DateTimeZone($tz));
        return $dt->format('g:i A');
    }

    /**
     * Hours:Minutes in local time.
     * Defaults to 12-hour with AM/PM (e.g., "4:05 PM").
     * Toggle $ampm=false for 24-hour (e.g., "16:05").
     * Set $padHour=true to zero-pad the hour ("04:05 PM" / "16:05").
     */
    public static function localHm(string $utcTime, string $tz = 'America/New_York', bool $ampm = true, bool $padHour = false): string
    {
        $dt = new \DateTime($utcTime, new \DateTimeZone('UTC'));
        $dt->setTimezone(new \DateTimeZone($tz));
        if ($ampm) {
            return $dt->format($padHour ? 'h:i A' : 'g:i A');
        }
        // 24-hour
        return $dt->format($padHour ? 'H:i' : 'G:i');
    }

     /**
     * Convert minutes → "Xh Ym" (pads minutes to 2 digits). 0 → "now".
     */
    public static function minutesToHm(int $minutes): string
    {
        if ($minutes <= 0) return 'now';
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        if ($h > 0) {
            return sprintf('%dh %02dm', $h, $m);
        }
        return sprintf('%dm', $m);
    }

      //convert UTC to users time
    public static function UTC_time(string $tz = 'America/New_York'): string
    {
        $userTz = new \DateTimeZone($tz);
        $now = new \DateTime('now', $userTz);
        $now->setTimezone(new \DateTimeZone('UTC'));
        return $now->format('Y-m-d H:i:00');
    }
    //convert UTC to east coast time
    // in Legenda\NormalSurf\Hooks\Convert
    public static function toLocalTime(string $utcTime, string $tz = 'America/New_York'): string
    {
        $dt = new \DateTime($utcTime, new \DateTimeZone('UTC'));
        $dt->setTimezone(new \DateTimeZone($tz));
        // Example: "Thursday, August 8, 4:05 PM"
        return $dt->format('l, F j, g:i A');
    }

    /**
     * Safe string for optional values (returns &mdash; when null/empty).
     */
    public static function safe(?string $s): string
    {
        $s = trim((string)$s);
        return ($s === '' || $s === '—' || $s === '-') ? '&mdash;' : htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Compute dominant period from a row using existing WavePeriod.
     * Expects SwH/WWH + SwP/WWP fields present in $row.
     */
    public static function dominantPeriod(?array $row): ?float
    {
        if (!$row) return null;
        return WavePeriod::computeDominantPeriod($row);
    }

    public static function waveCell(
    ?float $hs_m,
    ?float $per_s,
    ?float $dir_deg,
    int $dirDecimals = 0,  
    int $perDecimals = 1   
): string {
    if ($hs_m === null || $per_s === null || $dir_deg === null) {
        return '&mdash;';
    }
    $ft = round(Maths::metersToFeet($hs_m, 1), 1);
    $h  = (abs($ft - round($ft)) < 0.05)
        ? (string)round($ft)
        : number_format($ft, 1);
    $p = number_format($per_s, $perDecimals);
    $d = number_format($dir_deg, $dirDecimals);
    return "{$h}' @ {$p}s & {$d}°";
}


// Canonical: use existing Convert (no re-implementation)
public static function waveCellFromParts(?float $wvht_m, ?float $period_s, ?float $mwd_deg): string
{
    if ($wvht_m === null || $period_s === null || $mwd_deg === null) return '&mdash;';
    $ft = round(Maths::metersToFeet($wvht_m, 1), 1);
    $h  = (abs($ft - round($ft)) < 0.05) ? (string)round($ft) : number_format($ft, 1);
    $p  = (abs($period_s - round($period_s, 1)) < 1e-6) ? rtrim(rtrim(number_format($period_s, 1), '0'), '.') : (string)$period_s;
    $d  = (int)round($mwd_deg);
    return "{$h}' @ {$p}s & {$d}°";
}

// Thin wrappers (safe to keep until all callsites are moved)
public static function waveCellDominant(?array $row): string
{
    if (!$row) return '&mdash;';
    $h_m = isset($row['WVHT']) && is_numeric($row['WVHT']) ? (float)$row['WVHT'] : null;
    $dp  = \Legenda\NormalSurf\Utilities\WavePeriod::computeDominantPeriod($row);
    $dir = isset($row['MWD']) && is_numeric($row['MWD']) ? (float)$row['MWD'] : null;
    return self::waveCellFromParts($h_m, $dp, $dir);
}


    // ——— Placeholders you can swap later with real Utilities ———

    public static function tidePhaseNowPlaceholder(): string
    {
        return 'Low Incoming';
    }

    public static function nextTidePlaceholder(): string
    {
        return 'High @ 7:00PM';
    }

    public static function windPlaceholder(): string
    {
        return 'ESE @ 12kts';
    }
}
