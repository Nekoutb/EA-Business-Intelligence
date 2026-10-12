<?php
declare(strict_types=1);

namespace App\Services;

use App\Odoo\OdooClient;

/**
 * Per-file unit economics for clearing / forwarding tenants (Elimelec):
 *
 *   Revenue per file = Σ class-7 credit balance ÷ files
 *   Cost per file    = Σ class-6 debit balance  ÷ files
 *   Files            = DISTINCT invoice references on posted class-70 lines.
 *                      One invoice carries up to three revenue streams
 *                      (commission, file-opening fee, customs fee), so the
 *                      same reference appears on several 70x lines and
 *                      counts once.
 *
 * Which move-line field holds the invoice reference is a tenant setting
 * (KPI_FILE_REF_FIELD: ref | move_name | name). Figures are cumulative
 * year-to-date per month — a file billed in March and again in June is
 * one file for the year.
 */
final class FileUnitEconomics
{
    public const FIELDS = ['ref', 'move_name', 'name'];

    public function __construct(private OdooClient $odoo, private string $refField) {}

    /**
     * @return array{months:array<int,array{revenue:float,cost:float,files:int}>,last:int}
     *   cumulative YTD figures per month 1..12; `last` = last month with revenue or cost
     */
    public function ytdByMonth(int $year): array
    {
        $from = sprintf('%04d-01-01', $year);
        $to   = sprintf('%04d-12-31', $year);

        $revM  = $this->monthlyBalance('7%', $from, $to);
        $costM = $this->monthlyBalance('6%', $from, $to);

        // First month each reference appears → cumulative distinct count.
        $firstSeen = [];
        $ids = $this->accountIds('70%');
        if ($ids) {
            $offset = 0;
            do {
                $batch = $this->odoo->searchRead('account.move.line', [
                    ['parent_state', '=', 'posted'], ['account_id', 'in', $ids],
                    ['date', '>=', $from], ['date', '<=', $to],
                ], ['date', $this->refField], ['offset' => $offset, 'limit' => 5000, 'order' => 'date asc, id asc']);
                foreach ($batch as $l) {
                    $ref = is_string($l[$this->refField] ?? null) ? strtoupper(trim($l[$this->refField])) : '';
                    if ($ref === '' || isset($firstSeen[$ref])) continue;
                    $firstSeen[$ref] = (int) substr((string) $l['date'], 5, 2);
                }
                $offset += 5000;
            } while (count($batch) === 5000);
        }
        $newFiles = array_fill(1, 12, 0);
        foreach ($firstSeen as $m) if ($m >= 1 && $m <= 12) $newFiles[$m]++;

        $out = []; $r = $c = 0.0; $f = 0; $last = 0;
        for ($m = 1; $m <= 12; $m++) {
            $r += -($revM[$m] ?? 0.0);
            $c += ($costM[$m] ?? 0.0);
            $f += $newFiles[$m];
            $out[$m] = ['revenue' => $r, 'cost' => $c, 'files' => $f];
            // Last month with any activity: a month of costs without billing
            // (e.g. depreciation posted ahead of invoicing) still counts.
            if (abs($revM[$m] ?? 0.0) > 0.005 || abs($costM[$m] ?? 0.0) > 0.005) $last = $m;
        }
        return ['months' => $out, 'last' => $last];
    }

    /**
     * Distinct references per calendar month over a window.
     * @return array<string, array<string,true>>  'Y-m' => [ref => true]
     */
    public function refsByMonth(string $from, string $to): array
    {
        $ids = $this->accountIds('70%');
        if (!$ids) return [];
        $out = [];
        $offset = 0;
        do {
            $batch = $this->odoo->searchRead('account.move.line', [
                ['parent_state', '=', 'posted'], ['account_id', 'in', $ids],
                ['date', '>=', $from], ['date', '<=', $to],
            ], ['date', $this->refField], ['offset' => $offset, 'limit' => 5000, 'order' => 'date asc, id asc']);
            foreach ($batch as $l) {
                $ref = is_string($l[$this->refField] ?? null) ? strtoupper(trim($l[$this->refField])) : '';
                if ($ref !== '') $out[substr((string) $l['date'], 0, 7)][$ref] = true;
            }
            $offset += 5000;
        } while (count($batch) === 5000);
        return $out;
    }

    /** Distinct references over a whole window (a file billed twice counts once). */
    public static function distinctCount(array $refsByMonth): int
    {
        $all = [];
        foreach ($refsByMonth as $set) $all += $set;
        return count($all);
    }

    /**
     * Revenue streams = one per class-7 account with movement in the window.
     * Per stream: total, monthly series, files carrying it, and per-file
     * value against ALL files of the window (stream ÷ total files).
     */
    public function streams(string $from, string $to): array
    {
        $months = [];
        for ($m = new \DateTimeImmutable(substr($from, 0, 7) . '-01'); $m->format('Y-m') <= substr($to, 0, 7); $m = $m->modify('+1 month')) {
            $months[$m->format('Y-m')] = $m->format('M');
        }
        $ids = $this->accountIds('7%');
        $out = ['months' => $months, 'files_total' => 0, 'files_by_month' => array_fill_keys(array_keys($months), 0), 'streams' => [], 'total' => 0.0];
        if (!$ids) return $out;

        $byAcc = []; $allRefs = []; $refsByMonth = [];
        $offset = 0;
        do {
            $batch = $this->odoo->searchRead('account.move.line', [
                ['parent_state', '=', 'posted'], ['account_id', 'in', $ids],
                ['date', '>=', $from], ['date', '<=', $to],
            ], ['date', 'account_id', 'balance', $this->refField], ['offset' => $offset, 'limit' => 5000, 'order' => 'date asc, id asc']);
            foreach ($batch as $l) {
                if (!is_array($l['account_id'] ?? null)) continue;
                $aid   = (int) $l['account_id'][0];
                $ym    = substr((string) $l['date'], 0, 7);
                $amt   = -(float) ($l['balance'] ?? 0);
                $label = (string) $l['account_id'][1];
                $code  = (string) strtok($label, ' ');
                $a = $byAcc[$aid] ?? ['code' => $code, 'name' => trim(substr($label, strlen($code))), 'total' => 0.0,
                                      'monthly' => array_fill_keys(array_keys($months), 0.0), 'refs' => []];
                $a['total'] += $amt;
                if (isset($a['monthly'][$ym])) $a['monthly'][$ym] += $amt;
                $ref = is_string($l[$this->refField] ?? null) ? strtoupper(trim($l[$this->refField])) : '';
                if ($ref !== '') {
                    $a['refs'][$ref] = true;
                    // Files = references on the class-70 sales accounts only.
                    if (str_starts_with($code, '70')) { $allRefs[$ref] = true; $refsByMonth[$ym][$ref] = true; }
                }
                $byAcc[$aid] = $a;
            }
            $offset += 5000;
        } while (count($batch) === 5000);

        $out['files_total'] = count($allRefs);
        foreach ($refsByMonth as $ym => $set) if (isset($out['files_by_month'][$ym])) $out['files_by_month'][$ym] = count($set);
        $out['total'] = array_sum(array_column($byAcc, 'total'));

        foreach ($byAcc as $a) {
            if (abs($a['total']) < 0.5) continue;
            $pfm = [];
            foreach ($a['monthly'] as $ym => $v) {
                $n = $out['files_by_month'][$ym] ?? 0;
                $pfm[$ym] = $n > 0 ? $v / $n : null;
            }
            $out['streams'][] = [
                'code' => $a['code'], 'name' => $a['name'], 'total' => $a['total'],
                'share' => $out['total'] != 0.0 ? 100 * $a['total'] / $out['total'] : 0.0,
                'files_with' => count($a['refs']),
                'per_file' => $out['files_total'] > 0 ? $a['total'] / $out['files_total'] : null,
                'monthly' => $a['monthly'], 'per_file_monthly' => $pfm,
            ];
        }
        usort($out['streams'], fn ($x, $y) => $y['total'] <=> $x['total']);
        return $out;
    }

    private function accountIds(string $like): array
    {
        return array_values(array_filter(array_map(fn ($a) => (int) ($a['id'] ?? 0),
            $this->odoo->searchRead('account.account', [['code', '=like', $like]], ['id'], []))));
    }

    /** @return array<int,float> month => Σ balance (debit − credit) */
    private function monthlyBalance(string $like, string $from, string $to): array
    {
        $ids = $this->accountIds($like);
        if (!$ids) return [];
        $out = [];
        foreach ((array) $this->odoo->callModel('account.move.line', 'read_group', [
            'domain'  => [['parent_state', '=', 'posted'], ['account_id', 'in', $ids], ['date', '>=', $from], ['date', '<=', $to]],
            'fields'  => ['balance:sum'], 'groupby' => ['date:month'], 'lazy' => false,
        ]) as $g) {
            $f = $g['__range']['date:month']['from'] ?? $g['__range']['date']['from'] ?? null;
            if (!is_string($f)) continue;
            $m = (int) substr($f, 5, 2);
            $out[$m] = ($out[$m] ?? 0.0) + (float) ($g['balance'] ?? 0);
        }
        return $out;
    }
}
