<?php

declare(strict_types=1);

namespace Legenda\NormalSurf\BatchProcessing;

use PDO;
use Throwable;
use Legenda\NormalSurf\BatchProcessing\SpectralDataParser;
use Legenda\NormalSurf\Repositories\WaveBuoyRepo;
use Legenda\NormalSurf\Repositories\WindRepo;
use Legenda\NormalSurf\Infra\Db;

class ImportCC
{
    /* =========================
     * Core: DB handle + paths
     * ========================= */

public static function pdo(): PDO
{
    return Db::get();
    
}    public static function paths(): array
    {
        $root = \realpath(\dirname(__DIR__, 2)) ?: \getcwd();

        return [
            'root'      => $root,
            'logs'      => $root . '/logs',
            // kept for parity; CC doesn’t use these directly
            'tides_xml' => $root . '/assets/xml_data/8720587_annual.xml',
            'waves_dir' => $root . '/data/wave-forecast',
        ];
    }

    private static function log(string $msg, string $file = 'cc.log'): void
    {
        $paths = self::paths();
        if (!\is_dir($paths['logs'])) {
            @\mkdir($paths['logs'], 0775, true);
        }
        @\file_put_contents(
            $paths['logs'] . '/' . $file,
            '[' . \gmdate('c') . '] ' . $msg . \PHP_EOL,
            \FILE_APPEND
        );
    }

    /* =========================
     * Buoy observations → station_{id}
     * ========================= */

    /**
     * Pull buoy spectral observations via WaveBuoyRepo and INSERT IGNORE into station_{id}.
     * Returns:
     *  - if $station is array: [$pdo]
     *  - if $station is string: [$pdo, string $station, array $dataCols, string $colsList, string $table]
     */
    public static function conn_report(string|array $station = '41112', ?PDO $pdo = null): array
    {
        $pdo = $pdo ?? self::pdo();

        if (\is_array($station)) {
            foreach ($station as $s) {
                self::conn_report_one($pdo, (string)$s);
            }
            return [$pdo]; // backward-compatible: first element is PDO
        }

        [$dataCols, $dataRows] = self::fetch_and_filter_station((string)$station);
        $table = 'station_' . \preg_replace('/\D/', '', $station);

        $pdo->beginTransaction();
        try {
            $insertCols   = \array_merge(['ts'], $dataCols);
            $placeholders = \implode(',', \array_fill(0, \count($insertCols), '?'));
            $sqlInsert = \sprintf(
                'INSERT IGNORE INTO `%s` (%s) VALUES (%s)',
                $table,
                \implode(',', $insertCols),
                $placeholders
            );
            $stmt = $pdo->prepare($sqlInsert);

            $inserted = 0;
            foreach ($dataRows as $row) {
                $params = [$row['ts']];
                foreach ($dataCols as $col) {
                    $params[] = $row[$col] ?? null;
                }
                $stmt->execute($params);
                $inserted += $stmt->rowCount();
            }
            $pdo->commit();

            self::log(\sprintf('conn_report station=%s inserted=%d/%d', $station, $inserted, \count($dataRows)), 'conn_report.log');
        } catch (Throwable $e) {
            $pdo->rollBack();
            self::log('conn_report failed: ' . $e->getMessage(), 'conn_report.log');
            throw $e;
        }

        $colsList = \implode(',', $dataCols);
        return [$pdo, (string)$station, $dataCols, $colsList, $table];
    }

    /* =========================
     * Wind observations → Co-OPS/NDBC
     * ========================= */

    /**
     * @param string[]|string $stations e.g. ['8720030','8720218','SAUF1']
     * @param int|null        $ttlSec   skip if refreshed within this many seconds
     * @param PDO|null        $pdo      reuse caller's PDO if provided
     * @return array          [$pdo, $counts|['skipped'=>true]]
     */
    public static function conn_winds(
        string|array $stations = ['8720030', '8720218', 'SAUF1'],
        ?int $ttlSec = null,
        ?PDO $pdo = null
    ): array {
        $pdo = $pdo ?? self::pdo();

        // TTL guard
        if ($ttlSec !== null) {
            $root  = \realpath(\dirname(__DIR__, 2)) ?: \getcwd();
            $flag  = $root . '/.ns_winds.flag';
            $stale = !\is_file($flag) || (\time() - \filemtime($flag) > $ttlSec);
            if (!$stale) {
                return [$pdo, ['skipped' => true]];
            }
            @\touch($flag);
        }

        $list   = \is_array($stations) ? $stations : [$stations];
        $counts = WindRepo::refreshMany($pdo, $list);

        $logs = self::paths()['logs'] ?? (($root ?? \getcwd()) . '/logs');
        @\mkdir($logs, 0775, true);
        @\file_put_contents(
            $logs . '/conn_winds.log',
            '[' . \gmdate('c') . '] ' . \json_encode(['stations' => $list, 'counts' => $counts]) . \PHP_EOL,
            \FILE_APPEND
        );

        return [$pdo, $counts];
    }

    /* =========================
     * Helpers
     * ========================= */

    private static function fetch_and_filter_station(string $station): array
    {
        $raw  = WaveBuoyRepo::get_data($station);
        $data = SpectralDataParser::filter($raw);
        return [$data['columns'], $data['data']];
    }

    private static function conn_report_one(PDO $pdo, string $station): void
    {
        [$dataCols, $dataRows] = self::fetch_and_filter_station($station);
        $table = 'station_' . \preg_replace('/\D/', '', $station);

        $insertCols   = \array_merge(['ts'], $dataCols);
        $placeholders = \implode(',', \array_fill(0, \count($insertCols), '?'));
        $sqlInsert = \sprintf(
            'INSERT IGNORE INTO `%s` (%s) VALUES (%s)',
            $table,
            \implode(',', $insertCols),
            $placeholders
        );
        $stmt = $pdo->prepare($sqlInsert);

        foreach ($dataRows as $row) {
            $params = [$row['ts']];
            foreach ($dataCols as $col) {
                $params[] = $row[$col] ?? null;
            }
            $stmt->execute($params);
        }
    }

    public static function winds_latest(PDO $pdo, string $stationCode): ?array
    {
        return WindRepo::latest($pdo, $stationCode);
    }
}
