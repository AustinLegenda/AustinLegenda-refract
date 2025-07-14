<?php
namespace Legenda\NormalSurf\Helpers;

class SpectralDataParser
{
    public static function parse(array $lines): array
    {
        $cols = [];
        $startRow = null;

        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*#\s*YY\s+MM\s+DD/', $line)) {
                $cols = preg_split('/\s+/', trim(substr($line, 1)));
                $startRow = $i + 2;
                break;
            }
        }

        if (!$cols || $startRow === null) {
            throw new \Exception("Spec header not found.");
        }

        $dirMap = [
            'N'=>0,'NNE'=>22,'NE'=>45,'ENE'=>67,
            'E'=>90,'ESE'=>112,'SE'=>135,'SSE'=>157,
            'S'=>180,'SSW'=>202,'SW'=>225,'WSW'=>247,
            'W'=>270,'WNW'=>292,'NW'=>315,'NNW'=>337
        ];

        $data = [];
        for ($i = $startRow; $i < count($lines); $i++) {
            $vals = preg_split('/\s+/', trim($lines[$i]));
            if (count($vals) < count($cols)) continue;

            list($YY, $MM, $DD, $hh, $mn) = array_slice($vals, 0, 5);
            $ts = sprintf('%04d-%02d-%02d %02d:%02d:00', $YY, $MM, $DD, $hh, $mn);
            $row = ['ts' => $ts];

            foreach ($cols as $idx => $col) {
                $raw = $vals[$idx] ?? null;
                if ($raw === null || strtoupper(trim($raw)) === 'N/A') {
                    $row[$col] = null;
                } elseif (in_array($col, ['SwD', 'WWD'])) {
                    $row[$col] = $dirMap[$raw] ?? null;
                } elseif ($col === 'STEEPNESS') {
                    $row[$col] = $raw;
                } else {
                    $row[$col] = is_numeric($raw) ? floatval($raw) : null;
                }
            }

            $data[] = $row;
        }

        return ['columns' => $cols, 'data' => $data];
    }
}
