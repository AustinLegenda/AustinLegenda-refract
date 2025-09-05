<?php

declare(strict_types=1);

namespace Legenda\NormalSurf\BatchProcessing;

use PDO;
use Legenda\NormalSurf\BatchProcessing\SpectralDataParser;
use Legenda\NormalSurf\Repositories\WaveBuoyRepo;
use Legenda\NormalSurf\Repositories\WindRepo;


class ImportCC
{
    /* =========================
     * Core: DB handle + paths
     * ========================= */

    public static function pdo(): PDO
    {
        // config.php must define DB_HOST, DB_NAME, DB_USER, DB_PASS
        require_once \dirname(__DIR__, 2) . '/config.php';

        return new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }

    public static function paths(): array
    {
        $root = \realpath(\dirname(__DIR__, 2));

        return [
            'root'       => $root,
            'logs'       => $root . '/logs',
            'tides_xml'  => $root . '/assets/xml_data/8720587_annual.xml',
            'waves_dir'  => $root . '/data/wave-forecast',
        ];
    }

    /* =========================
     * Buoy observations → station_{id}
     * ========================= */

    /**
     * Pulls buoy spectral observations via WaveBuoyRepo and INSERT IGNOREs into station_{id}.
     * Returns tuple: [PDO $pdo, string $station, array $dataCols, string $colsList, string $table]
     */
   
    public static function conn_report(string|array $station = '41112'): array
    {
        // If array: import many, return just [$pdo] for compatibility with [$conn] = ...
        if (is_array($station)) {
            $pdo = self::pdo(); // new helper you added earlier, or inline your PDO construction
            foreach ($station as $s) {
                self::conn_report_one($pdo, (string)$s);
            }
            return [$pdo]; // backward-compatible: first element is PDO
        }

        // Single-station behavior (preserves your previous return shape)
        $pdo = self::pdo();
        [$dataCols, $dataRows] = self::fetch_and_filter_station((string)$station); // helper for clarity
        $table = "station_" . preg_replace('/\D/', '', $station);

        $insertCols   = array_merge(['ts'], $dataCols);
        $placeholders = implode(',', array_fill(0, count($insertCols), '?'));
        $sqlInsert = sprintf(
            "INSERT IGNORE INTO `%s` (%s) VALUES (%s)",
            $table,
            implode(',', $insertCols),
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

        // Optional breadcrumb
        @file_put_contents(
            dirname(__DIR__, 2) . '/logs/conn_report.log',
            sprintf("[%s] station=%s inserted=%d/%d\n", gmdate('c'), $station, $inserted, count($dataRows)),
            FILE_APPEND
        );

        $colsList = implode(',', $dataCols);
        return [$pdo, (string)$station, $dataCols, $colsList, $table];
    }

    /* =========================
     * Wind observations → CoOps Wind Stations
     * ========================= */

    public static function conn_winds(string|array $stations = ['8720030', '8720218', 'SAUF1'], ?int $ttlSec = null): array
    {
        $pdo = self::pdo();

        // TTL guard (optional): skip if recently refreshed
        if ($ttlSec !== null) {
            $root = \realpath(\dirname(__DIR__, 2));
            $flag = $root . '/.ns_winds.flag';
            $stale = !\is_file($flag) || (time() - \filemtime($flag) > $ttlSec);
            if (!$stale) {
                return [$pdo, ['skipped' => true]];
            }
            @\touch($flag);
        }

        $list = \is_array($stations) ? $stations : [$stations];
        $counts = WindRepo::refreshMany($pdo, $list);

        // tiny breadcrumb (non-fatal if logs/ missing)
        $logs = self::paths()['logs'] ?? (\realpath(\dirname(__DIR__, 2)) . '/logs');
        @\mkdir($logs, 0775, true);
        @\file_put_contents(
            $logs . '/conn_winds.log',
            '[' . \gmdate('c') . '] ' . json_encode(['stations' => $list, 'counts' => $counts]) . "\n",
            \FILE_APPEND
        );

        return [$pdo, $counts];
    }

    /* Helpers to keep things tidy. If you don’t like helpers, inline in conn_report(). */
    private static function fetch_and_filter_station(string $station): array
    {
        $raw   = \Legenda\NormalSurf\Repositories\WaveBuoyRepo::get_data($station);
        $data  = \Legenda\NormalSurf\BatchProcessing\SpectralDataParser::filter($raw);
        return [$data['columns'], $data['data']];
    }

    private static function conn_report_one(\PDO $pdo, string $station): void
    {
        [$dataCols, $dataRows] = self::fetch_and_filter_station($station);
        $table = "station_" . preg_replace('/\D/', '', $station);

        $insertCols   = array_merge(['ts'], $dataCols);
        $placeholders = implode(',', array_fill(0, count($insertCols), '?'));
        $sqlInsert = sprintf(
            "INSERT IGNORE INTO `%s` (%s) VALUES (%s)",
            $table,
            implode(',', $insertCols),
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
