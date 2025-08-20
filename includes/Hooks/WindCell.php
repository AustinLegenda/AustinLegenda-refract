<?php
declare(strict_types=1);

namespace Legenda\NormalSurf\Hooks;

final class WindCell
{
    /**
     * Format like: "NE @ 12 kt (045°)"
     * Falls back to "—" if missing.
     */
    public static function format(?int $degTrue, ?float $kt): string
    {
        if ($degTrue === null && $kt === null) return '&mdash;';
        $card = self::degToCardinal($degTrue);
        $spd  = is_null($kt) ? '&mdash;' : (string) (round($kt, 1) === floor(round($kt,1)) ? (int)round($kt,1) : round($kt,1));
        $deg  = is_null($degTrue) ? '&mdash;' : sprintf('%03d°', ($degTrue % 360 + 360) % 360);
        if ($card === '&mdash;') {
            return "{$spd} kt ({$deg})";
        }
        return "{$card} ({$deg}) @ {$spd} kt";
    }

    /**
     * 16‑point compass, returns "N", "NNE", "NE", ... or "—" if null.
     */
    public static function degToCardinal(?int $degTrue): string
    {
        if ($degTrue === null) return '&mdash;';
        $d = ($degTrue % 360 + 360) % 360;
        $dirs = ['N','NNE','NE','ENE','E','ESE','SE','SSE','S','SSW','SW','WSW','W','WNW','NW','NNW'];
        $idx = (int) floor(($d + 11.25) / 22.5) % 16;
        return $dirs[$idx];
    }
}
