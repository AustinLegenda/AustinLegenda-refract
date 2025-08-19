<?php
namespace Legenda\NormalSurf\BatchProcessing;

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
            throw new \Exception("Header not found in spec file");
        }

        $dirMap = [
            'N'=>0,'NNE'=>22,'NE'=>45,'ENE'=>67,
            'E'=>90,'ESE'=>112,'SE'=>135,'SSE'=>157,
            'S'=>180,'SSW'=>202,'SW'=>225,'WSW'=>247,
            'W'=>270,'WNW'=>292,'NW'=>315,'NNW'=>337
        ];

        $data = [];

        for ($i = $startRow; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if ($line === '' || str_starts_with($line, '#')) continue;

            $vals = preg_split('/\s+/', $line);
            if (count($vals) < count($cols)) continue;

            list($YY, $MM, $DD, $hh, $mn) = array_slice($vals, 0, 5);
            $ts = sprintf('%04d-%02d-%02d %02d:%02d:00', $YY, $MM, $DD, $hh, $mn);

            $row = ['ts' => $ts];
            foreach ($cols as $idx => $col) {
                $raw = $vals[$idx] ?? null;
                if ($raw === null || strtoupper($raw) === 'N/A' || trim($raw) === '') {
                    $row[$col] = null;
                } elseif (in_array($col, ['SwD', 'WWD'], true)) {
                    $row[$col] = $dirMap[$raw] ?? null;
                } elseif ($col === 'STEEPNESS') {
                    $row[$col] = $raw;
                } else {
                    $row[$col] = is_numeric($raw) ? (float) $raw : null;
                }
            }

            $data[] = $row;
        }

        return ['columns' => $cols, 'data' => $data];
    }

    public static function filter(array $parsed, array $exclude = ['YY', 'MM', 'DD', 'hh', 'mm']): array
    {
        $filteredCols = array_filter($parsed['columns'], fn($c) => !in_array($c, $exclude, true));
        return [
            'columns' => $filteredCols,
            'data' => $parsed['data']
        ];
    }
}
