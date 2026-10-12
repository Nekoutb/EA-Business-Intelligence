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
     *   cumulative YTD figures per month 1..12; `last` = last month with revenue
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
            if (abs($revM[$m] ?? 0.0) > 0.005) $last = $m;
        }
        return ['months' => $out, 'last' => $last];
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
