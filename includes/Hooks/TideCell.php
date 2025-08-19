<?php
namespace Legenda\NormalSurf\Hooks;

use PDO;
use DateTime;
use DateTimeZone;
use Legenda\NormalSurf\Utilities\TidePhase;
use Legenda\NormalSurf\Utilities\TidePreference;
use Legenda\NormalSurf\Repositories\TideRepo;
use Legenda\NormalSurf\Helpers\Format;

final class TideCell
{
    public function __construct(
        private PDO $pdo,
        private TidePhase $phase = new TidePhase(),
        private TidePreference $pref = new TidePreference(new TidePhase())
    ) {}

    public function currentLabel(string $tideStationId, string $nowUtc): string
    {
        $st = $this->phase->stateForStation($this->pdo, $tideStationId, $nowUtc, 30);
        if (!$st || empty($st['state'])) return '—';
        return match ($st['state']) {
            'High', 'Low', 'Incoming', 'Outgoing' => $st['state'],
            default => '—',
        };
    }

    /** Returns ['minutes'=>int, 'row_label'=>string] or null */
    public function nextPeakInfo(string $tideStationId, string $nowUtc, string $tz): ?array
    {
        $arr = TideRepo::getNextHL($this->pdo, $tideStationId, $nowUtc, 1);
        if (empty($arr)) return null;

        $row = $arr[0];
        if (empty($row['t_utc']) || empty($row['hl_type'])) return null;

        $now  = new DateTime($nowUtc, new DateTimeZone('UTC'));
        $when = new DateTime($row['t_utc'], new DateTimeZone('UTC'));
        $mins = max(0, (int) floor(($when->getTimestamp() - $now->getTimestamp()) / 60));
        $type = $row['hl_type'] === 'H' ? 'High' : 'Low';

        return [
            'minutes'   => $mins,
            'row_label' => $type . ' @ ' . Format::localHm($row['t_utc'], $tz),
        ];
    }
}
