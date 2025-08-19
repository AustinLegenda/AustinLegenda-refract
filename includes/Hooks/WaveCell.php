<?php
declare(strict_types=1);

namespace Legenda\NormalSurf\Hooks;

use Legenda\NormalSurf\Utilities\WavePeriod;
use Legenda\NormalSurf\Helpers\Maths;

final class WaveCell
{
    // Display precision
    private const HEIGHT_DP = 1; // feet
    private const PERIOD_DP = 1; // seconds

    /** Convenience: callers already have parts (meters/seconds/degrees). */
    public static function formatParts($hs_m, $per_s, $dir_deg): string
    {
        return self::renderDTO([
            'hs_m'    => is_numeric($hs_m)    ? (float)$hs_m    : null,
            'per_s'   => is_numeric($per_s)   ? (float)$per_s   : null,
            'dir_deg' => is_numeric($dir_deg) ? (float)$dir_deg : null,
        ]);
    }

    /** Normalize a raw NOAA buoy/station row into DTO (m/s/deg). */
    public static function fromBuoyRow(array $row): array
    {
        $dp = WavePeriod::computeDominantPeriod($row);

        return [
            'hs_m'    => (isset($row['WVHT']) && is_numeric($row['WVHT'])) ? (float)$row['WVHT'] : null,
            'per_s'   => is_numeric($dp) ? (float)$dp
                        : ((isset($row['SwP']) && is_numeric($row['SwP'])) ? (float)$row['SwP']
                        : ((isset($row['APD']) && is_numeric($row['APD'])) ? (float)$row['APD'] : null)),
            'dir_deg' => (isset($row['MWD']) && is_numeric($row['MWD'])) ? (float)$row['MWD'] : null,
        ];
    }

    /** Normalize a forecast/DTO row into the same shape (m/s/deg). */
    public static function fromForecastRow(array $row): array
    {
        return [
            'hs_m'    => (isset($row['hs_m'])    && is_numeric($row['hs_m']))    ? (float)$row['hs_m']    : null,
            'per_s'   => (isset($row['per_s'])   && is_numeric($row['per_s']))   ? (float)$row['per_s']   : null,
            'dir_deg' => (isset($row['dir_deg']) && is_numeric($row['dir_deg'])) ? (float)$row['dir_deg'] : null,
        ];
    }

    /** Instance: render from DTO. */
    public function cellFromDTO(array $dto): string
    {
        return self::renderDTO($dto);
    }

    /** Instance: render from a buoy row. */
    public function cellFromBuoyRow(array $row): string
    {
        return self::renderDTO(self::fromBuoyRow($row));
    }

    /** Instance: render from a forecast row/DTO. */
    public function cellFromForecastRow(array $row): string
    {
        return self::renderDTO(self::fromForecastRow($row));
    }

    /** Back-compat alias (treat input as buoy row). */
    public function dominantCell(array $row): string
    {
        return $this->cellFromBuoyRow($row);
    }

    /** Single source of truth for the formatted string. */
    private static function renderDTO(array $dto): string
    {
        $hs_m    = $dto['hs_m']    ?? null;
        $per_s   = $dto['per_s']   ?? null;
        $dir_deg = $dto['dir_deg'] ?? null;

        if (!is_numeric($hs_m) || !is_numeric($per_s) || !is_numeric($dir_deg)) {
            return '—';
        }

        // All math via Helpers\Maths
        $hs_ft_rounded = Maths::metersToFeet((float)$hs_m, self::HEIGHT_DP); // rounds internally
        $per_rounded   = round((float)$per_s, self::PERIOD_DP);
        $dir_norm_i    = (int) round(Maths::normAngle((float)$dir_deg));
        if ($dir_norm_i === 360) $dir_norm_i = 0;

        // Guarantee fixed decimals on hs & per
        $hs  = number_format($hs_ft_rounded, self::HEIGHT_DP, '.', '');
        $per = number_format($per_rounded,   self::PERIOD_DP, '.', '');

        return "{$hs} ft @ {$per} s & {$dir_norm_i}°";
    }
}
