<?php
namespace Legenda\NormalSurf\Hooks;

use Legenda\NormalSurf\Utilities\WavePeriod;
use Legenda\NormalSurf\Helpers\Format;

final class WaveCell
{
    /** Normalize a raw buoy/station row into a simple DTO */
    public static function fromBuoyRow(array $row): array
    {
        return [
            'hs_m'    => isset($row['WVHT']) && is_numeric($row['WVHT']) ? (float)$row['WVHT'] : null,
            'per_s'   => isset($row['SwP']) && is_numeric($row['SwP'])
                        ? (float)$row['SwP']
                        : (isset($row['APD']) && is_numeric($row['APD']) ? (float)$row['APD'] : null),
            'dir_deg' => isset($row['MWD']) && is_numeric($row['MWD']) ? (float)$row['MWD'] : null,
        ];
    }

    /** Normalize a forecast row into the same DTO shape */
    public static function fromForecastRow(array $row): array
    {
        return [
            'hs_m'    => isset($row['hs_m']) ? (float)$row['hs_m'] : null,
            'per_s'   => isset($row['per_s']) ? (float)$row['per_s'] : null,
            'dir_deg' => isset($row['dir_deg']) ? (float)$row['dir_deg'] : null,
        ];
    }

    /** Existing: build formatted dominant wave cell string from a row */
    public function dominantCell(array $row): string
    {
        $dp  = WavePeriod::computeDominantPeriod($row);
        $hs  = isset($row['WVHT']) ? (float)$row['WVHT'] : null;
        $mwd = isset($row['MWD'])  ? (float)$row['MWD']  : null;
        return Format::waveCellFromParts($hs, $dp, $mwd);
    }
}
