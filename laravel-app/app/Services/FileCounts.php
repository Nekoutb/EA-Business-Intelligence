<?php
declare(strict_types=1);

namespace App\Services;

use App\Odoo\OdooClient;

/**
 * Number of files per month — one rule for every per-file figure.
 *
 * FILE_COUNT_SOURCE=clearance (clearing agents on the clearance app):
 *   months after the clearance go-live (CLEARANCE_GO_LIVE, default the
 *   clearance pack's go_live) → files opened in the clearance application
 *   (logistics.file date_opened; imported / cancelled excluded);
 *   months before → the manual figure from Productivity settings, else the
 *   caller's system count.
 * Otherwise: the manual figure where entered, else the system count.
 * Manual figures only apply while Productivity settings are switched on.
 */
final class FileCounts
{
    private static array $memo = [];

    public static function clearanceMode(): bool
    {
        return strtolower((string) config_env('FILE_COUNT_SOURCE', '')) === 'clearance';
    }

    public static function goLive(): string
    {
        return substr((string) (config_env('CLEARANCE_GO_LIVE', null) ?: config('clearance_reports.thresholds.go_live', '2026-08-31')), 0, 10);
    }

    /** Clearance files opened per month within [from, to]: 'Y-m' => n. */
    public static function clearanceByMonth(OdooClient $odoo, string $from, string $to): array
    {
        $k = md5($from . $to . (optional(tenant())->id ?? 0));
        if (isset(self::$memo[$k])) return self::$memo[$k];
        $out = [];
        foreach ($odoo->searchRead('logistics.file', [['date_opened', '>=', $from], ['date_opened', '<=', $to]],
                     ['date_opened', 'state'], ['limit' => 100000]) as $f) {
            if (in_array($f['state'] ?? '', ['imported', 'cancel'], true)) continue;
            $ym = substr((string) $f['date_opened'], 0, 7);
            $out[$ym] = ($out[$ym] ?? 0) + 1;
        }
        return self::$memo[$k] = $out;
    }

    /**
     * @param array<string,int> $system  the caller's own count per 'Y-m'
     * @return array{by_month:array<string,int>, source:array<string,string>, total:int, mixed:bool}
     */
    public static function monthly(OdooClient $odoo, string $from, string $to, array $system): array
    {
        $months = [];
        for ($m = new \DateTimeImmutable(substr($from, 0, 7) . '-01'); $m->format('Y-m') <= substr($to, 0, 7); $m = $m->modify('+1 month')) {
            $months[] = $m->format('Y-m');
        }
        $manual = [];
        foreach (array_unique([substr($from, 0, 4), substr($to, 0, 4)]) as $y) $manual += ProductivityOverrides::files((int) $y);
        $clr = self::clearanceMode() ? self::clearanceByMonth($odoo, $from, $to) : [];
        $goLive = self::goLive();

        $by = []; $src = [];
        foreach ($months as $ym) {
            if (self::clearanceMode() && $ym . '-01' > $goLive) { $by[$ym] = $clr[$ym] ?? 0; $src[$ym] = 'clearance'; }
            elseif (isset($manual[$ym]))                        { $by[$ym] = $manual[$ym];  $src[$ym] = 'manual'; }
            else                                                { $by[$ym] = $system[$ym] ?? 0; $src[$ym] = 'system'; }
        }
        $mixed = in_array('manual', $src, true) || in_array('clearance', $src, true);
        return ['by_month' => $by, 'source' => $src, 'total' => array_sum($by), 'mixed' => $mixed];
    }
}
