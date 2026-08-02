<?php
declare(strict_types=1);

namespace App\Services;

use App\Odoo\OdooClient;
use DateTimeImmutable;

/**
 * Treasury · Collections by Revenue Stream (TSD).
 *
 * Breaks COLLECTED customer-invoice revenue into the same product streams as
 * the Product Revenue TSD page, for MTD and YTD windows.
 *
 * Alignment contract (why these numbers tie to the rest of the BMS):
 *  - Stream mapping reuses GLRevenueService::matchAccountsToProducts() — the
 *    exact matcher behind Product Revenue TSD — over the same class-70
 *    accounts (including its redistribution shares), so a franc lands in the
 *    same stream on both pages.
 *  - "Collected" uses the same construction as the Scan→Cash reconciliation
 *    (BillingService): per invoice, collected = HT × payment progress, where
 *    progress = (amount_total − amount_residual) ÷ amount_total. Fully paid
 *    and in-payment invoices weigh 1, partials their paid fraction, unpaid 0.
 *  - Grand total therefore equals Σ HT × progress over the window's posted
 *    customer invoices — the recon "Collected (HT)". Class-70 streams plus
 *    the disclosed "Unmatched 70x" and "Other income (non-70)" buckets sum
 *    exactly to it; nothing is silently dropped.
 */
final class CollectionsByStreamService
{
    /** Safety cap on invoices per window (≫ TSD's annual volume). */
    private const CAP = 8000;

    public function __construct(
        private OdooClient $odoo,
        private GLRevenueService $gl,
    ) {}

    /**
     * @return array{
     *   as_of: string,
     *   streams: list<array{key:string,label:string,category:string,color:string,mtd:float,ytd:float}>,
     *   categories: array<string, array{label:string,color:string,order:int}>,
     *   cat_totals: array<string, array{mtd:float,ytd:float}>,
     *   unmatched: array{mtd:float,ytd:float,accounts:list<string>},
     *   other_income: array{mtd:float,ytd:float},
     *   totals: array{mtd:float,ytd:float},
     *   recon: array{mtd:float,ytd:float},
     *   meta: array{partials_mtd:int,partials_ytd:int,invoices_ytd:int}
     * }
     */
    public function analyze(?DateTimeImmutable $today = null): array
    {
        $today   = $today ?? new DateTimeImmutable('today');
        $to      = $today->format('Y-m-d');
        $ytdFrom = $today->format('Y-01-01');
        $mtdFrom = $today->format('Y-m-01');

        // ── Class-70 accounts, mapped through the page's own matcher. ────
        $accIndex = [];
        foreach ($this->odoo->searchRead('account.account', [['code', '=like', '70%']], ['id', 'code', 'name'], []) as $a) {
            $id = (int) ($a['id'] ?? 0);
            if ($id > 0) {
                $accIndex[$id] = ['name' => (string) ($a['name'] ?? ''), 'code' => (string) ($a['code'] ?? '')];
            }
        }
        $match         = $this->gl->matchAccountsToProducts(['accounts' => $accIndex, 'by_account_month' => []]);
        $assigned      = (array) ($match['assigned'] ?? []);        // account_id → product_key
        $redistributed = (array) ($match['redistributed'] ?? []);   // account_id → ['targets' => [key => share]]

        $ytd = $this->collectedByAccount($ytdFrom, $to, array_keys($accIndex));
        $mtd = $this->collectedByAccount($mtdFrom, $to, array_keys($accIndex));

        // ── Fold account sums into product streams (assigned + shares). ──
        $products = (array) config('budget_products.products', []);
        $cats     = (array) config('budget_products.categories', []);
        $streams  = [];
        foreach ($products as $p) {
            if (($p['revenue_source'] ?? null) !== 'gl_706_named') continue;
            $streams[(string) $p['key']] = [
                'key'      => (string) $p['key'],
                'label'    => (string) ($p['label'] ?? $p['key']),
                'category' => (string) ($p['category'] ?? 'other_cargo'),
                'color'    => (string) ($p['color'] ?? '#64748b'),
                'mtd'      => 0.0,
                'ytd'      => 0.0,
            ];
        }

        $un70 = ['mtd' => 0.0, 'ytd' => 0.0, 'accounts' => []];
        foreach ($accIndex as $accId => $meta) {
            $m = (float) ($mtd['by_account'][$accId] ?? 0.0);
            $y = (float) ($ytd['by_account'][$accId] ?? 0.0);
            if ($m == 0.0 && $y == 0.0) continue;

            $pk = $assigned[$accId] ?? null;
            if ($pk !== null && isset($streams[$pk])) {
                $streams[$pk]['mtd'] += $m;
                $streams[$pk]['ytd'] += $y;
                continue;
            }
            $targets = (array) (($redistributed[$accId]['targets'] ?? []));
            if ($targets !== []) {
                foreach ($targets as $tk => $share) {
                    if (!isset($streams[(string) $tk])) continue;
                    $streams[(string) $tk]['mtd'] += $m * (float) $share;
                    $streams[(string) $tk]['ytd'] += $y * (float) $share;
                }
                continue;
            }
            $un70['mtd'] += $m;
            $un70['ytd'] += $y;
            if (abs($y) > 0.005 || abs($m) > 0.005) {
                $un70['accounts'][] = trim($meta['code'] . ' ' . $meta['name']);
            }
        }

        // Presentation order: registry category order, then label.
        $orderOf = [];
        foreach ($cats as $ck => $c) $orderOf[$ck] = (int) ($c['order'] ?? 99);
        $list = array_values($streams);
        usort($list, function (array $a, array $b) use ($orderOf) {
            return [($orderOf[$a['category']] ?? 99), $a['label']] <=> [($orderOf[$b['category']] ?? 99), $b['label']];
        });

        $catTotals = [];
        foreach ($list as $s) {
            $catTotals[$s['category']] ??= ['mtd' => 0.0, 'ytd' => 0.0];
            $catTotals[$s['category']]['mtd'] += $s['mtd'];
            $catTotals[$s['category']]['ytd'] += $s['ytd'];
        }

        $streamTotals = [
            'mtd' => array_sum(array_column($list, 'mtd')) + $un70['mtd'],
            'ytd' => array_sum(array_column($list, 'ytd')) + $un70['ytd'],
        ];

        return [
            'as_of'        => $to,
            'streams'      => $list,
            'categories'   => $cats,
            'cat_totals'   => $catTotals,
            'unmatched'    => $un70,
            // Non-70 income share — keeps the grand total equal to the recon
            // Collected (HT) figure instead of silently under-reporting.
            'other_income' => [
                'mtd' => (float) $mtd['recon_total'] - $streamTotals['mtd'],
                'ytd' => (float) $ytd['recon_total'] - $streamTotals['ytd'],
            ],
            'totals'       => $streamTotals,
            'recon'        => ['mtd' => (float) $mtd['recon_total'], 'ytd' => (float) $ytd['recon_total']],
            'meta'         => [
                'partials_mtd' => (int) $mtd['partials'],
                'partials_ytd' => (int) $ytd['partials'],
                'invoices_ytd' => (int) $ytd['invoices'],
            ],
        ];
    }

    /**
     * Collected HT per class-70 account over [from, to] (by invoice date),
     * plus the recon-equivalent window total (whole-invoice HT × progress).
     *
     * @param list<int> $accountIds
     * @return array{by_account: array<int,float>, recon_total: float, partials: int, invoices: int}
     */
    private function collectedByAccount(string $from, string $to, array $accountIds): array
    {
        $progressByMove = [];
        $reconTotal = 0.0; $partials = 0; $invoices = 0;

        $offset = 0;
        while (true) {
            $page = $this->odoo->searchRead('account.move',
                [['move_type', '=', 'out_invoice'], ['state', '=', 'posted'],
                 ['invoice_date', '>=', $from], ['invoice_date', '<=', $to]],
                ['id', 'amount_untaxed_signed', 'amount_total_signed', 'amount_residual_signed', 'payment_state'],
                ['limit' => 1000, 'offset' => $offset]);
            foreach ($page as $m) {
                $total = (float) ($m['amount_total_signed'] ?? 0);
                $resid = (float) ($m['amount_residual_signed'] ?? 0);
                $ht    = (float) ($m['amount_untaxed_signed'] ?? 0);
                $prog  = $total != 0.0 ? max(0.0, min(1.0, ($total - $resid) / $total)) : 0.0;
                $progressByMove[(int) ($m['id'] ?? 0)] = $prog;
                $reconTotal += $ht * $prog;
                $invoices++;
                if (($m['payment_state'] ?? '') === 'partial') $partials++;
            }
            if (count($page) < 1000 || count($progressByMove) >= self::CAP) break;
            $offset += 1000;
        }
        if ($progressByMove === []) {
            return ['by_account' => [], 'recon_total' => 0.0, 'partials' => 0, 'invoices' => 0];
        }

        // Class-70 lines of those invoices, weighted by invoice progress.
        // Tax and receivable counter-lines post outside class 70, so the
        // account filter alone isolates the revenue lines.
        $by = [];
        foreach (array_chunk(array_keys($progressByMove), 400) as $chunk) {
            $offset = 0;
            while (true) {
                $lines = $this->odoo->searchRead('account.move.line',
                    [['move_id', 'in', $chunk], ['account_id', 'in', $accountIds]],
                    ['move_id', 'account_id', 'price_subtotal'],
                    ['limit' => 1000, 'offset' => $offset]);
                foreach ($lines as $l) {
                    $mid = is_array($l['move_id'] ?? null) ? (int) $l['move_id'][0] : (int) ($l['move_id'] ?? 0);
                    $aid = is_array($l['account_id'] ?? null) ? (int) $l['account_id'][0] : (int) ($l['account_id'] ?? 0);
                    $by[$aid] = ($by[$aid] ?? 0.0)
                        + ((float) ($l['price_subtotal'] ?? 0)) * ($progressByMove[$mid] ?? 0.0);
                }
                if (count($lines) < 1000) break;
                $offset += 1000;
            }
        }

        return ['by_account' => $by, 'recon_total' => $reconTotal, 'partials' => $partials, 'invoices' => $invoices];
    }
}
