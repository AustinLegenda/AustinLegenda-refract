<?php
declare(strict_types=1);

namespace Legenda\NormalSurf\Utilities;

use PDO;
use Legenda\NormalSurf\Helpers\Maths;
use Legenda\NormalSurf\Repositories\WaveForecastRepo;

final class Interpolator
{
    /**
     * Weighted “midpoint” (actually inverse-distance weighted) for realtime buoy columns.
     * Keeps your existing columns and behavior.
     */
    public static function interpolateMidpointRow(array $data1, array $data2, array $distances): array
    {
        $columns = ['ts','WVHT','SwH','SwP','WWH','WWP','SwD','WWD','APD','MWD','STEEPNESS'];

        $dist1 = (float)($distances['dist_41112'] ?? 1.0);
        $dist2 = (float)($distances['dist_41117'] ?? 1.0);

        $inv1 = 1.0 / ($dist1 + 0.01);
        $inv2 = 1.0 / ($dist2 + 0.01);
        $sum  = $inv1 + $inv2;
        $w1   = $inv1 / ($sum ?: 1.0);
        $w2   = $inv2 / ($sum ?: 1.0);

        $mid = [];
        foreach ($columns as $col) {
            if ($col === 'ts') { $mid[$col] = '—'; continue; }

            if ($col === 'MWD') {
                $mid[$col] = Maths::circularAverage(
                    [(float)($data1['MWD'] ?? 0), (float)($data2['MWD'] ?? 0)],
                    [$w1, $w2]
                );
                continue;
            }

            $v1 = \is_numeric($data1[$col] ?? null) ? (float)$data1[$col] : null;
            $v2 = \is_numeric($data2[$col] ?? null) ? (float)$data2[$col] : null;
            $mid[$col] = ($v1 !== null && $v2 !== null) ? ($v1 * $w1 + $v2 * $w2) : '—';
        }

        return $mid;
    }

    /**
     * Interpolate forecast at a spot/time from nearby stations’ forecasts.
     * - Pulls each station’s last sample at/before $targetUtc (via repo or SQL fallback)
     * - Inverse-distance weights by great-circle distance
     * - Linear average for hs/per; circular mean for direction
     */
    public static function interpolateForecastForSpot(
        PDO $pdo,
        array $spot,
        string $targetUtc,
        array $stationCoords,
        array $stationIds = ['41112', '41117']
    ): array {
        // Allow exact-time hits by +1 minute when using getPrev()
        $plusOne = (new \DateTime($targetUtc, new \DateTimeZone('UTC')))
            ->modify('+1 minute')
            ->format('Y-m-d H:i:00');

        // Collect most recent samples per station
        $samples = [];
        foreach ($stationIds as $sid) {
            $row = self::getPrevWave($pdo, (string)$sid, $plusOne);
            if ($row) {
                // expect keys: t_utc, hs_m, per_s, dir_deg
                $samples[$sid] = $row;
            }
        }

        if (!$samples) {
            return [
                'hs_m'    => null,
                'per_s'   => null,
                'dir_deg' => null,
                'weights' => [],
                'ts_utc'  => $targetUtc,
            ];
        }

        // Compute weights
        $lat = $spot['spot_lat']  ?? $spot['region_lat'] ?? null;
        $lon = $spot['spot_lon']  ?? $spot['region_lon'] ?? null;

        $weights = [];
        if ($lat === null || $lon === null) {
            // No geometry? average equally.
            $w = 1.0 / \max(\count($samples), 1);
            foreach ($samples as $sid => $_) { $weights[$sid] = $w; }
        } else {
            $inv = [];
            foreach ($samples as $sid => $_) {
                if (!isset($stationCoords[$sid])) continue;
                $d = Maths::haversine(
                    (float)$lat, (float)$lon,
                    (float)$stationCoords[$sid]['lat'],
                    (float)$stationCoords[$sid]['lon']
                );
                $inv[$sid] = 1.0 / \max($d, 1e-6);
            }
            $sum = \array_sum($inv) ?: 1.0;
            foreach ($inv as $sid => $v) { $weights[$sid] = $v / $sum; }
        }

        // Combine: linear for hs/per, circular for direction
        $hs = 0.0; $per = 0.0; $angles = []; $wts = [];
        foreach ($samples as $sid => $row) {
            $w = (float)($weights[$sid] ?? 0.0);
            if (\is_numeric($row['hs_m'] ?? null))  { $hs  += $w * (float)$row['hs_m']; }
            if (\is_numeric($row['per_s'] ?? null)) { $per += $w * (float)$row['per_s']; }
            if (\is_numeric($row['dir_deg'] ?? null)) {
                $angles[] = (float)$row['dir_deg'];
                $wts[]    = $w;
            }
        }
        $dir = $angles ? Maths::circularAverage($angles, $wts) : null;

        return [
            'hs_m'    => $hs ?: null,
            'per_s'   => $per ?: null,
            'dir_deg' => $dir,
            'weights' => $weights,
            'ts_utc'  => $targetUtc,
        ];
    }

    /**
     * Utility to combine pre-fetched station samples with known weights.
     * Expects each sample to have hs_m/per_s/dir_deg.
     */
    public static function combineForecast(array $samples, array $weights): array
    {
        $hs = 0.0; $per = 0.0; $angles = []; $wts = [];

        foreach ($samples as $sid => $s) {
            $w = (float)($weights[$sid] ?? 0.0);
            if (\is_numeric($s['hs_m']    ?? null)) { $hs  += $w * (float)$s['hs_m']; }
            if (\is_numeric($s['per_s']   ?? null)) { $per += $w * (float)$s['per_s']; }
            if (\is_numeric($s['dir_deg'] ?? null)) { $angles[] = (float)$s['dir_deg']; $wts[] = $w; }
        }

        $dir = $angles ? Maths::circularAverage($angles, $wts) : null;

        return [
            'hs_m'    => $hs ?: null,
            'per_s'   => $per ?: null,
            'dir_deg' => $dir,
        ];
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    /**
     * Get most recent wave-forecast row at/before $nowUtc for a station.
     * Tries WaveForecastRepo::getPrev first; falls back to direct SQL if missing.
     */
    private static function getPrevWave(PDO $pdo, string $stationId, string $nowUtc): ?array
    {
        // Prefer the repo method if it exists (newer code path)
        if (\class_exists(WaveForecastRepo::class) &&
            \method_exists(WaveForecastRepo::class, 'getPrev')) {
            /** @var callable $fn */
            $fn = [WaveForecastRepo::class, 'getPrev'];
            return $fn($pdo, $stationId, $nowUtc);
        }

        // Fallback: direct SQL against waves_<stationId>
        if (!\preg_match('/^\d+$/', $stationId)) {
            throw new \InvalidArgumentException('Bad station id: ' . $stationId);
        }
        $table = 'waves_' . $stationId;

        $sql = "SELECT t_utc, hs_m, per_s, dir_deg
                  FROM `$table`
                 WHERE t_utc <= :now
              ORDER BY t_utc DESC
                 LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->bindValue(':now', $nowUtc, PDO::PARAM_STR);
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
