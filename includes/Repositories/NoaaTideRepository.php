<?php
namespace Legenda\NormalSurf\Repositories;

use PDO;

class NoaaTideRepository
{
    /**
     * Import a NOAA annual XML into a per-station table.
     * - Table name: pass explicitly OR auto-derive from filename (e.g., 8720030_annual.xml → tides_8720030)
     * - Stores both local (Eastern, LST_LDT) and UTC times
     * - Heights in meters + feet
     * - hl_type: 'H' (High), 'L' (Low), 'I' (interval/no H/L)
     *
     * @return string Table name used (e.g., tides_8720030)
     */
    public static function importAnnualHLXml(
        PDO $pdo,
        string $xmlPath,
        ?string $tableName = null,
        string $localTz = 'America/New_York'
    ): string {
        if (!file_exists($xmlPath)) {
            throw new \RuntimeException("XML not found: {$xmlPath}");
        }
        $xml = simplexml_load_file($xmlPath);
        if (!$xml) {
            throw new \RuntimeException("Failed to parse XML: {$xmlPath}");
        }

        // Resolve table name
        $table = $tableName ?: self::deriveTableNameFromFilename($xmlPath);

        // Metadata
        $datum   = self::readDatum($xml) ?: 'MLLW';
        $srcYear = self::readSrcYear($xml) ?: (int)date('Y');

        // Ensure table exists (lean schema; hl_type NOT NULL with 'I' to avoid NULL duplicate uniqueness edge case)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `{$table}` (
              id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              t_local    DATETIME        NOT NULL,
              t_utc      DATETIME        NOT NULL,
              height_m   DECIMAL(7,3)    NOT NULL,
              height_ft  DECIMAL(7,3)    NOT NULL,
              hl_type    ENUM('H','L','I') NOT NULL,
              datum      VARCHAR(16)     NOT NULL,
              src_year   SMALLINT        NOT NULL,
              created_at TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
              updated_at TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              UNIQUE KEY uq_time_type (t_utc, hl_type),
              KEY idx_time (t_utc)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $ins = $pdo->prepare("
            INSERT INTO `{$table}` (t_local, t_utc, height_m, height_ft, hl_type, datum, src_year)
            VALUES (:t_local, :t_utc, :height_m, :height_ft, :hl_type, :datum, :src_year)
            ON DUPLICATE KEY UPDATE
              height_m = VALUES(height_m),
              height_ft = VALUES(height_ft),
              datum    = VALUES(datum),
              updated_at = CURRENT_TIMESTAMP
        ");

        $tzLocal = new \DateTimeZone($localTz);

        // Support BOTH NOAA schemas:
        // (A) child-node: <item><date>...</date><time>...</time><highlow>H</highlow><pred_in_ft>..</pred_in_ft><pred_in_cm>..</pred_in_cm></item>
        // (B) attribute:  <item t="YYYY-MM-DD HH:MM" v="4.12" type="H"/> with optional root units="feet|meters"
        $items = $xml->xpath('//item') ?: [];
        $rootUnits = strtolower((string)($xml['units'] ?? 'feet')); // default feet if absent

        foreach ($items as $it) {
            $hasChildren = count($it->children()) > 0;

            if ($hasChildren) {
                // CHILD-NODE SCHEMA
                $date  = trim((string)$it->date);              // "2025/01/01"
                $time  = trim((string)$it->time);              // "02:55 AM"
                $hlRaw = strtoupper(trim((string)$it->highlow)); // "H" | "L" (sometimes blank)
                $ftStr = trim((string)$it->pred_in_ft);
                $cmStr = trim((string)$it->pred_in_cm);

                if ($date === '' || $time === '') continue;

                $dtLocal = \DateTime::createFromFormat('Y/m/d h:i A', "{$date} {$time}", $tzLocal);
                if (!$dtLocal) continue;

                $dtUtc = clone $dtLocal;
                $dtUtc->setTimezone(new \DateTimeZone('UTC'));

                // Prefer cm → m (exact), keep feet too
                $height_ft = is_numeric($ftStr) ? (float)$ftStr : (is_numeric($cmStr) ? ((float)$cmStr / 30.48) : null);
                $height_m  = is_numeric($cmStr) ? ((float)$cmStr / 100.0) : (is_numeric($ftStr) ? ((float)$ftStr * 0.3048) : null);
                if ($height_m === null || $height_ft === null) continue;

                $hl = ($hlRaw === 'H' || $hlRaw === 'L') ? $hlRaw : 'I';

            } else {
                // ATTRIBUTE SCHEMA
                $tAttr = isset($it['t']) ? trim((string)$it['t']) : '';
                $vAttr = isset($it['v']) ? trim((string)$it['v']) : '';
                $hlRaw = isset($it['type']) ? strtoupper((string)$it['type']) : '';

                if ($tAttr === '' || !is_numeric($vAttr)) continue;

                // Most of these files provide local station time. If your source is UTC, change here.
                $dtLocal = new \DateTime($tAttr, $tzLocal);
                $dtUtc   = clone $dtLocal;
                $dtUtc->setTimezone(new \DateTimeZone('UTC'));

                // Convert based on units at root (fallback feet)
                if ($rootUnits === 'meters' || $rootUnits === 'm') {
                    $height_m  = (float)$vAttr;
                    $height_ft = $height_m / 0.3048;
                } else {
                    $height_ft = (float)$vAttr;
                    $height_m  = $height_ft * 0.3048;
                }

                $hl = ($hlRaw === 'H' || $hlRaw === 'L') ? $hlRaw : 'I';
            }

            $ins->execute([
                ':t_local'   => $dtLocal->format('Y-m-d H:i:00'),
                ':t_utc'     => $dtUtc->format('Y-m-d H:i:00'),
                ':height_m'  => round($height_m, 3),
                ':height_ft' => round($height_ft, 3),
                ':hl_type'   => $hl,       // 'H' | 'L' | 'I'
                ':datum'     => $datum,    // e.g., MLLW
                ':src_year'  => $srcYear,
            ]);
        }

        return $table;
    }

    /** Next N HL/interval predictions from 'now' (UTC), using a station id like '8720030'. */
    public static function getNextHL(PDO $pdo, string $stationId, string $nowUtc, int $limit = 2): array
    {
        return self::getNextHLByTable($pdo, self::tableFor($stationId), $nowUtc, $limit);
    }

    /** Most recent HL/interval strictly before 'now' (UTC), by station id. */
    public static function getPrevHL(PDO $pdo, string $stationId, string $nowUtc): ?array
    {
        return self::getPrevHLByTable($pdo, self::tableFor($stationId), $nowUtc);
    }

    /** Next N rows by explicit table name (e.g., 'tides_8720030'). */
    public static function getNextHLByTable(PDO $pdo, string $table, string $nowUtc, int $limit = 2): array
    {
        $stmt = $pdo->prepare("
            SELECT t_utc, t_local, height_ft, height_m, hl_type
            FROM `{$table}`
            WHERE t_utc >= :now
            ORDER BY t_utc ASC
            LIMIT :lim
        ");
        $stmt->bindValue(':now', $nowUtc);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Previous row by explicit table name (e.g., 'tides_8720030'). */
    public static function getPrevHLByTable(PDO $pdo, string $table, string $nowUtc): ?array
    {
        $stmt = $pdo->prepare("
            SELECT t_utc, t_local, height_ft, height_m, hl_type
            FROM `{$table}`
            WHERE t_utc < :now
            ORDER BY t_utc DESC
            LIMIT 1
        ");
        $stmt->execute([':now' => $nowUtc]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    // ---------- helpers ----------

    /** Map '8720030' → 'tides_8720030' (digits only, safety). */
    private static function tableFor(string $stationId): string
    {
        return 'tides_' . preg_replace('/\D+/', '', $stationId);
    }

    /** Derive table name from filename (8720030_annual.xml → tides_8720030). */
    private static function deriveTableNameFromFilename(string $xmlPath): string
    {
        if (preg_match('/(\d{6,9})/', basename($xmlPath), $m)) {
            return 'tides_' . $m[1];
        }
        throw new \RuntimeException("Unable to derive table name from filename; pass \$tableName.");
    }

    /** Read datum from XML if present. */
    private static function readDatum(\SimpleXMLElement $xml): ?string
    {
        if (isset($xml->datainfo->Datum)) return trim((string)$xml->datainfo->Datum);
        if (isset($xml['datum']))         return trim((string)$xml['datum']);
        return null;
    }

    /** Read source year from XML if present (e.g., datainfo/BeginDate '20250101 00:00'). */
    private static function readSrcYear(\SimpleXMLElement $xml): ?int
    {
        if (isset($xml->datainfo->BeginDate)) {
            $bd = trim((string)$xml->datainfo->BeginDate);
            if ($bd !== '' && preg_match('/^\d{4}/', $bd, $m)) return (int)$m[0];
        }
        return null;
    }
}
