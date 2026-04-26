<?php

namespace App\Services;

use App\Odoo\OdooClient;
use DateTimeImmutable;

/**
 * InventoryService — Inventory days and value trend (YTD).
 *
 * The reconciliation (stock vs trial balance) lives in GLQualityService,
 * surfaced under Financial Reporting → GL Quality. This service powers
 * just the operational metrics on the Inventory page.
 *
 * 1. Inventory Days (textbook two-point avg, P&L-aligned COGS, YTD-rate):
 *        days = (avg_inventory × period_days_ytd) / cogs_ytd
 *      avg_inventory  = (beginning + ending) / 2
 *                       beginning = trial balance End Balance ≤ 31 Dec prior
 *                                   year  (= opening balance for YTD)
 *                       ending    = trial balance End Balance ≤ today
 *      cogs_ytd       = Σ account.move.line.balance for 60X accounts in YTD
 *      period_days_ytd= calendar days elapsed from 1 January
 *
 * 2. Trend — month-end inventory value, January through today, computed by
 *    walking posted move lines for inventory accounts in chronological order
 *    starting from the opening balance, snapshotting at each month-end.
 *
 * All inventory values come from the GL trial balance (3X accounts excluding
 * 39X impairment). stock.valuation.layer was avoided because it's
 * Enterprise-only and absent on Community editions.
 */
class InventoryService
{
    /** Cached company id to avoid repeated authenticate() calls. */
    private ?int $companyId = null;

    /** Cached OHADA inventory account list (3% excluding 39%). */
    private ?array $inventoryAccountsCache = null;

    public function __construct(private OdooClient $odoo) {}

    /**
     * YTD inventory analysis. Period parameter is ignored — by design the
     * inventory page always shows year-to-date so days calculation and trend
     * line share one consistent window.
     */
    public function analyze(DateTimeImmutable $today, array $period = []): array
    {
        $errors = [];

        // Always YTD: 1 January of current year → today.
        $from     = $today->modify('first day of January')->setTime(0, 0, 0);
        $to       = $today->setTime(23, 59, 59);
        $asOfDate = $today;
        $label    = 'YTD ' . $today->format('Y');

        // Beginning inventory = trial balance End Balance ≤ 31 Dec prior year
        // Ending inventory    = trial balance End Balance ≤ today
        $priorYearEnd     = $from->modify('-1 day');
        $beginningBalance = $this->safe(fn() => $this->sumInventoryGL($priorYearEnd), $errors, 'beginning_balance') ?? 0.0;
        $endingBalance    = $this->safe(fn() => $this->sumInventoryGL($asOfDate),     $errors, 'ending_balance')    ?? 0.0;

        $trend    = $this->safe(fn() => $this->buildGLTrend($from, $to), $errors, 'trend')    ?? ['by_month' => []];
        $cogsYtd  = $this->safe(fn() => $this->getCogsYtd($from, $to),    $errors, 'cogs')     ?? 0.0;
        $currency = $this->safe(fn() => $this->detectCurrency(),          $errors, 'currency') ?? 'USD';

        $inventoryDays = $this->computeInventoryDays($beginningBalance, $endingBalance, $cogsYtd, $from, $to);

        return [
            'inventory_days'  => $inventoryDays,
            'trend'           => $trend,
            'currency'        => $currency,
            'period_label'    => $label,
            'from'            => $from->format('Y-m-d'),
            'to'              => $to->format('Y-m-d'),
            'as_of'           => $asOfDate->format('Y-m-d'),
            'error'           => empty($errors) ? null : implode(' | ', $errors),
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    // Inventory accounts (cached) — OHADA 3X excluding 39X
    // ─────────────────────────────────────────────────────────────────

    /**
     * Resolve and cache the inventory account list (id, code, name).
     * Called by getGLInventoryAccounts() and buildGLTrend(); the cache
     * means the 3X account list is fetched once per service instance.
     */
    private function findInventoryAccounts(): array
    {
        if ($this->inventoryAccountsCache !== null) {
            return $this->inventoryAccountsCache;
        }

        $accounts = $this->odoo->searchRead(
            'account.account',
            [['code', '=like', '3%']],
            ['code', 'name'],
            ['limit' => 1000, 'order' => 'code asc']
        );

        // Client-side: keep only accounts whose code starts with 3 but not 39.
        // (Doing this client-side avoids Odoo's `like` operator auto-wrapping
        // values with %s, which made `not like '39%'` unreliable.)
        $accounts = array_values(array_filter($accounts, function ($a) {
            $code = (string)($a['code'] ?? '');
            return str_starts_with($code, '3') && !str_starts_with($code, '39');
        }));

        return $this->inventoryAccountsCache = $accounts;
    }

    // ─────────────────────────────────────────────────────────────────
    // Inventory Days (YTD)
    // ─────────────────────────────────────────────────────────────────

    /**
     * COGS YTD from the GL — OHADA accounts starting with '60'
     * (purchases of goods, raw materials, consumed supplies, and the 603
     * stock-variation accounts that adjust purchases to actual consumption).
     *
     * Sum( account.move.line.balance ) WHERE
     *     account.code =like '60%'
     *     AND parent_state = 'posted'
     *     AND company_id = X
     *     AND date BETWEEN $from AND $to
     *
     * This is the same number the income statement reports for COGS, so the
     * inventory-days ratio aligns with what the CFO sees in the P&L.
     */
    private function getCogsYtd(DateTimeImmutable $from, DateTimeImmutable $to): float
    {
        // Step 1: find COGS accounts (OHADA 60X family).
        // No company_id filter on account.account — that field was removed in
        // Odoo 17+; companies are scoped via account.move.line.company_id.
        $accounts = $this->odoo->searchRead(
            'account.account',
            [['code', '=like', '60%']],
            ['code'],
            ['limit' => 1000]
        );
        if (empty($accounts)) {
            return 0.0;
        }
        $accountIds = array_column($accounts, 'id');

        // Step 2: sum YTD postings against those accounts.
        $domain = [
            ['parent_state', '=', 'posted'],
            ['account_id',   'in', $accountIds],
            ['company_id',   '=', $this->getCompanyId()],
            ['date',         '>=', $from->format('Y-m-d')],
            ['date',         '<=', $to->format('Y-m-d')],
        ];

        $total = 0.0;
        try {
            $groups = $this->odoo->callModel('account.move.line', 'read_group', [
                'domain'  => $domain,
                'fields'  => ['balance:sum'],
                'groupby' => [],
            ]);
            $total = (float)($groups[0]['balance'] ?? 0);
        } catch (\Throwable $e) {
            // Fallback: client-side aggregation
            $lines = $this->odoo->searchRead(
                'account.move.line', $domain, ['balance'], ['limit' => 200000]
            );
            foreach ($lines as $l) {
                $total += (float)($l['balance'] ?? 0);
            }
        }

        // 60X are debit-natural; balance = debit − credit ≥ 0 for COGS.
        // Returns to vendor / refunds reduce balance correctly.
        // abs() guards against unexpected sign reversal in edge configurations.
        return round(abs($total), 2);
    }

    /**
     * Days of inventory outstanding, YTD basis (textbook two-point average).
     *
     *   avg_inventory    = (beginning + ending) / 2
     *     beginning      = trial balance End Balance ≤ 31 Dec prior year
     *                      (= inventory carried into the new year)
     *     ending         = trial balance End Balance ≤ today
     *                      (= same number shown on the reconciliation box)
     *
     *   days_outstanding = (avg_inventory × period_days) / cogs_ytd
     *
     * Standard accounting / audit formula (US GAAP, IFRS, OHADA). Not
     * annualised — the figure remains stable through the year if the
     * consumption rate stays steady.
     */
    private function computeInventoryDays(float $beginning, float $ending, float $cogs, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        // Floor at 0 — a negative trial-balance value on inventory accounts
        // would indicate a bookkeeping error rather than physical reality.
        $beginning = max(0.0, $beginning);
        $ending    = max(0.0, $ending);

        $avgInventory = ($beginning + $ending) / 2.0;
        $periodDays   = max(1, (int)$from->diff($to)->days + 1);
        $days         = ($cogs > 0.01)
            ? ($avgInventory * $periodDays) / $cogs
            : 0.0;

        return [
            'beginning_inventory' => round($beginning,    2),
            'ending_inventory'    => round($ending,       2),
            'avg_inventory'       => round($avgInventory, 2),
            'cogs_ytd'            => round($cogs,         2),
            'period_days'         => $periodDays,
            'days_outstanding'    => round($days,         1),
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    // GL inventory accounts (OHADA 3XX, excluding 39X impairment)
    // ─────────────────────────────────────────────────────────────────

    /**
     * Sum of trial-balance End Balance for inventory accounts as of a date.
     * Used for both the YTD beginning balance and the YTD ending balance
     * fed into computeInventoryDays(). Mirrors Odoo's Trial Balance report
     * domain (parent_state='posted', date <= asOf, scoped by company).
     */
    private function sumInventoryGL(DateTimeImmutable $asOfDate): float
    {
        $accounts = $this->findInventoryAccounts();
        if (empty($accounts)) {
            return 0.0;
        }
        $accountIds = array_column($accounts, 'id');

        $domain = [
            ['parent_state', '=', 'posted'],
            ['account_id',   'in', $accountIds],
            ['company_id',   '=', $this->getCompanyId()],
            ['date',         '<=', $asOfDate->format('Y-m-d')],
        ];

        $total = 0.0;
        try {
            $g = $this->odoo->callModel('account.move.line', 'read_group', [
                'domain'  => $domain,
                'fields'  => ['balance:sum'],
                'groupby' => [],
            ]);
            $total = (float)($g[0]['balance'] ?? 0);
        } catch (\Throwable $e) {
            $rows = $this->odoo->searchRead(
                'account.move.line', $domain, ['balance'], ['limit' => 200000]
            );
            foreach ($rows as $r) {
                $total += (float)($r['balance'] ?? 0);
            }
        }
        return round($total, 2);
    }

    // ─────────────────────────────────────────────────────────────────
    // Trend (YTD inventory value evolution) — month-end snapshots
    // ─────────────────────────────────────────────────────────────────

    /**
     * Build month-end inventory value series from the GL trial balance.
     *
     * Two queries:
     *   1. Opening balance (= sum of inventory account balances ≤ 31 Dec
     *      prior year) — equivalent to a Trial Balance report run with
     *      end_date = day before YTD start.
     *   2. All YTD posted lines on inventory accounts, ordered by date.
     *
     * Then walk the lines forward, starting from the opening balance,
     * snapshotting the running cumulative balance at each month-end.
     *
     * This is what Odoo's Trial Balance report would show for each
     * month-end if you ran it 12 times — but in 2 round-trips total.
     */
    private function buildGLTrend(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $accounts = $this->findInventoryAccounts();
        if (empty($accounts)) {
            return ['by_month' => []];
        }
        $accountIds = array_column($accounts, 'id');
        $companyId  = $this->getCompanyId();

        // Step 1: opening balance (everything posted before YTD started)
        $opening = 0.0;
        try {
            $g = $this->odoo->callModel('account.move.line', 'read_group', [
                'domain' => [
                    ['parent_state', '=', 'posted'],
                    ['account_id',   'in', $accountIds],
                    ['company_id',   '=', $companyId],
                    ['date',         '<', $from->format('Y-m-d')],
                ],
                'fields'  => ['balance:sum'],
                'groupby' => [],
            ]);
            $opening = (float)($g[0]['balance'] ?? 0);
        } catch (\Throwable $e) {
            // Fallback: client-side aggregation
            $rows = $this->odoo->searchRead(
                'account.move.line',
                [
                    ['parent_state', '=', 'posted'],
                    ['account_id',   'in', $accountIds],
                    ['company_id',   '=', $companyId],
                    ['date',         '<', $from->format('Y-m-d')],
                ],
                ['balance'],
                ['limit' => 200000]
            );
            foreach ($rows as $r) {
                $opening += (float)($r['balance'] ?? 0);
            }
        }

        // Step 2: YTD posted lines, ordered by date
        $lines = $this->odoo->searchRead(
            'account.move.line',
            [
                ['parent_state', '=', 'posted'],
                ['account_id',   'in', $accountIds],
                ['company_id',   '=', $companyId],
                ['date',         '>=', $from->format('Y-m-d')],
                ['date',         '<=', $to->format('Y-m-d')],
            ],
            ['date', 'balance'],
            ['limit' => 200000, 'order' => 'date asc, id asc']
        );

        // Step 3: walk lines forward, snapshot at each month-end
        $monthBuckets = $this->generateMonthEnds($from, $to);
        $running      = $opening;
        $li           = 0;
        $count        = count($lines);
        $byMonth      = [];

        foreach ($monthBuckets as $bucketEnd) {
            $endStr = $bucketEnd->format('Y-m-d');
            while ($li < $count && (string)($lines[$li]['date'] ?? '') <= $endStr) {
                $running += (float)($lines[$li]['balance'] ?? 0);
                $li++;
            }
            $byMonth[] = [
                'date'  => $bucketEnd->format('Y-m-d'),
                'month' => $bucketEnd->format('M Y'),
                'value' => round(max(0.0, $running), 2),
            ];
        }

        return ['by_month' => $byMonth];
    }

    /** Last day of each month in [from, to], capped at $to. */
    private function generateMonthEnds(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $ends = [];
        $cursor = $from->modify('last day of this month');
        // Safety cap (avoid runaway loop on bad input)
        for ($i = 0; $i < 60 && $cursor <= $to; $i++) {
            $ends[] = $cursor;
            $cursor = $cursor->modify('+1 day')->modify('last day of this month');
        }
        // Always include the final $to date so the chart ends "now"
        if (empty($ends) || end($ends)->format('Y-m-d') !== $to->format('Y-m-d')) {
            $ends[] = $to;
        }
        return $ends;
    }

    // ─────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────

    private function detectCurrency(): string
    {
        try {
            $companyId = $this->getCompanyId();
            $company = $this->odoo->read('res.company', [$companyId], ['currency_id']);
            $curRef = $company[0]['currency_id'] ?? null;
            if (is_array($curRef) && !empty($curRef[0])) {
                $cur = $this->odoo->read('res.currency', [$curRef[0]], ['name']);
                return $cur[0]['name'] ?? 'USD';
            }
        } catch (\Throwable $e) {
            // fall through
        }
        return 'USD';
    }

    private function getCompanyId(): int
    {
        if ($this->companyId !== null) {
            return $this->companyId;
        }
        try {
            $uid  = $this->odoo->authenticate();
            $user = $this->odoo->read('res.users', [$uid], ['company_id']);
            $cid  = $user[0]['company_id'] ?? null;
            $this->companyId = is_array($cid) ? (int)($cid[0] ?? 1) : 1;
        } catch (\Throwable $e) {
            $this->companyId = 1;
        }
        return $this->companyId;
    }

    /**
     * Run a callable, capture exceptions into the $errors bag, return null on failure.
     * Each section of analyze() uses this so a single failing call doesn't blow up the page.
     */
    private function safe(callable $fn, array &$errors, string $tag)
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            $errors[] = "{$tag}: " . $e->getMessage();
            return null;
        }
    }
}
