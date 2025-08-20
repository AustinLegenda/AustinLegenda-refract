<?php
declare(strict_types=1);

namespace Legenda\NormalSurf\API;

use Exception;
use DateTimeImmutable;
use DateTimeZone;
use DateInterval;

final class NWSGridpointWindRequest
{
    /** Build Gridpoint URL (forecast grid data) */
    public static function url(string $office, int $x, int $y): string
    {
        // Full grid data (not the /forecast text); contains windSpeed + windDirection with validTime ranges
        return "https://api.weather.gov/gridpoints/{$office}/{$x},{$y}";
    }

    /** Fetch JSON with required headers */
    public static function fetch_json(string $office, int $x, int $y): array
    {
        $url = self::url($office, $x, $y);

        $ctx = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'header'  => implode("\r\n", [
                    'Accept: application/geo+json',
                    // Use your domain/email here; NWS requires a UA
                    'User-Agent: HazardSurf/1.0 (https://phpstack-1452178-5683884.cloudwaysapps.com, austin@legenda.co)',
                ]),
                'timeout' => 20,
            ],
        ]);

        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            throw new Exception("NWSGridpointWindRequest: fetch failed for {$office}/{$x},{$y}");
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['properties'])) {
            throw new Exception("NWSGridpointWindRequest: bad JSON for {$office}/{$x},{$y}");
        }
        return $data['properties'];
    }

    /** Expand an ISO-8601 interval like "2025-08-20T18:00:00+00:00/PT3H" to hourly timestamps (UTC). */
    private static function expandHourly(string $validTime): array
    {
        // Split "start/period"
        [$startStr, $periodStr] = explode('/', $validTime, 2);
        $start = new DateTimeImmutable($startStr, new DateTimeZone('UTC'));
        // NWS uses PTnH or PTnM. We’ll support hours (H) and minutes (M), step hourly.
        $period = new DateInterval($periodStr); // relies on PHP’s ISO8601 parsing
        $end = $start->add($period);

        $ts = [];
        for ($t = $start; $t < $end; $t = $t->add(new DateInterval('PT1H'))) {
            $ts[] = $t->format('Y-m-d H:00:00'); // hourly buckets
        }
        // If duration < 1h, still include the start hour
        if (empty($ts)) {
            $ts[] = $start->format('Y-m-d H:00:00');
        }
        return $ts;
    }

    /** Normalize unit to kt + m/s (speed) */
    private static function speedToKtMs(?float $v, string $uom): array
    {
        if ($v === null) return [null, null];
        $u = strtolower($uom);
        if (str_contains($u, 'km_h')) {               // wmoUnit:km_h-1
            $kt = round($v * 0.5399568, 2);
            $ms = round($v / 3.6, 2);
        } elseif (str_contains($u, 'kn')) {          // wmoUnit:knots
            $kt = round($v, 2);
            $ms = round($v * 0.514444, 2);
        } else {                                      // fallback assume m/s
            $ms = round($v, 2);
            $kt = round($v / 0.514444, 2);
        }
        return [$kt, $ms];
    }

    /** Fetch + map hourly rows: [['ts','WDIR','WSPD_ms','WSPD_kt'], ...] */
    public static function fetch_rows(string $office, int $x, int $y): array
    {
        $p = self::fetch_json($office, $x, $y);

        $spd = $p['windSpeed']     ?? null; // ['uom' => ..., 'values' => [ ['validTime'=>'...', 'value'=>..], ...]]
        $dir = $p['windDirection'] ?? null;

        if (!$spd || !$dir || empty($spd['values'])) {
            return [];
        }

        $spdUom = $spd['uom'] ?? 'wmoUnit:km_h-1';
        $dirUom = $dir['uom'] ?? 'wmoUnit:degree_(angle)';

        // Build hourly maps
        $spdMap = []; // ts => numeric speed (original units)
        foreach ($spd['values'] as $v) {
            $val = is_numeric($v['value'] ?? null) ? (float)$v['value'] : null;
            foreach (self::expandHourly($v['validTime']) as $ts) {
                $spdMap[$ts] = $val;
            }
        }

        $dirMap = []; // ts => degrees
        foreach (($dir['values'] ?? []) as $v) {
            $val = is_numeric($v['value'] ?? null) ? (float)$v['value'] : null;
            foreach (self::expandHourly($v['validTime']) as $ts) {
                $dirMap[$ts] = $val;
            }
        }

        // Join
        $rows = [];
        $allTs = array_unique(array_merge(array_keys($spdMap), array_keys($dirMap)));
        sort($allTs);
        foreach ($allTs as $ts) {
            $s = $spdMap[$ts] ?? null;
            [$kt, $ms] = ($s === null) ? [null, null] : self::speedToKtMs($s, $spdUom);
            $d = $dirMap[$ts] ?? null;
            if ($d !== null) {
                // normalize to [0,360)
                $d = fmod(($d % 360) + 360, 360);
            }
            $rows[] = [
                'ts'       => $ts,
                'WDIR'     => $d !== null ? (int)round($d) : null,
                'WSPD_ms'  => $ms,
                'WSPD_kt'  => $kt,
            ];
        }

        return $rows;
    }
}
