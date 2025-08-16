<?php
namespace Legenda\NormalSurf\Models;

use PDO;

class MidTideModel
{
    public static function nextMid(PDO $pdo, string $table): ?array
    {
        // get "now" in UTC without Convert
        $nowUtc = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:00');

        // prev HL
        $prev = $pdo->prepare("
          SELECT t_utc, t_local, hl_type, height_ft, height_m
          FROM `{$table}`
          WHERE (hl_type='H' OR hl_type='L') AND t_utc < :now
          ORDER BY t_utc DESC
          LIMIT 1
        ");
        $prev->execute([':now' => $nowUtc]);
        $prev = $prev->fetch(\PDO::FETCH_ASSOC);

        // next HL
        $next = $pdo->prepare("
          SELECT t_utc, t_local, hl_type, height_ft, height_m
          FROM `{$table}`
          WHERE (hl_type='H' OR hl_type='L') AND t_utc >= :now
          ORDER BY t_utc ASC
          LIMIT 1
        ");
        $next->execute([':now' => $nowUtc]);
        $next = $next->fetch(\PDO::FETCH_ASSOC);

        if (!$prev || !$next) return null;

        // ensure we have opposing HLs (rare guard)
        if ($prev['hl_type'] === $next['hl_type']) {
            $want = ($prev['hl_type'] === 'H') ? 'L' : 'H';
            $fix = $pdo->query("
              SELECT t_utc, t_local, hl_type, height_ft, height_m
              FROM `{$table}`
              WHERE hl_type='{$want}' AND t_utc > '{$prev['t_utc']}'
              ORDER BY t_utc ASC LIMIT 1
            ")->fetch(\PDO::FETCH_ASSOC);
            if ($fix) $next = $fix;
        }

        // midpoint in UTC
        $prevEpoch = \strtotime($prev['t_utc']);
        $nextEpoch = \strtotime($next['t_utc']);
        $midEpoch  = intdiv($prevEpoch + $nextEpoch, 2);
        $midUtc    = \gmdate('Y-m-d H:i:00', $midEpoch);

        // label: L->H = incoming (M+), H->L = outgoing (M-)
        $label = ($prev['hl_type'] === 'L' && $next['hl_type'] === 'H') ? 'M+' : 'M-';

        // simple height estimate (avg of extremes)
        $midFt = \round(((float)$prev['height_ft'] + (float)$next['height_ft']) / 2, 2);

        // format local
        $dtLocal = (new \DateTime($midUtc, new \DateTimeZone('UTC')))
                    ->setTimezone(new \DateTimeZone('America/New_York'));
        $pretty  = $dtLocal->format('l, F j, g:i A');

        return [
            'label'     => $label,           // 'M+' or 'M-'
            't_local'   => $dtLocal->format('Y-m-d H:i:00'),
            'pretty'    => $pretty,
            'height_ft' => $midFt,
            'prev'      => $prev,
            'next'      => $next,
        ];
        
    }

}

