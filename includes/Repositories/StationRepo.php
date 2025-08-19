<?php
namespace Legenda\NormalSurf\Repositories;

use PDO;

final class StationRepo
{
    public function __construct(private PDO $pdo) {}

    public function latestStationRow(string $stationId, string $nowUtc): ?array
    {
        $table = 'station_' . preg_replace('/\D+/', '', $stationId);
        $cols  = 'ts, WVHT, SwH, SwP, WWH, WWP, SwD, WWD, STEEPNESS, APD, MWD';
        $stmt  = $this->pdo->prepare("SELECT {$cols} FROM `{$table}` WHERE ts <= ? ORDER BY ts DESC LIMIT 1");
        $stmt->execute([$nowUtc]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    // TEMP config fallback (move to DB when ready)
    private const COORDS = [
        '41112' => ['lat' => 30.709, 'lon' => -81.292],
        '41117' => ['lat' => 29.999, 'lon' => -81.079],
    ];

    /** Return ['lat'=>..., 'lon'=>...] or null */
    public function coords(string $stationId): ?array
    {
        // If you have a table, replace this block with a SELECT.
        return self::COORDS[$stationId] ?? null;
    }

    /** Batch fetch coords: id => ['lat'=>..., 'lon'=>...] (ids that are missing are omitted) */
    public function coordsMany(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $c = $this->coords((string)$id);
            if ($c) $out[(string)$id] = $c;
        }
        return $out;
    }
}
