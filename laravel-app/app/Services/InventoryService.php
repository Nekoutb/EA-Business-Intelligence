<?php

namespace App\Services;

use App\Odoo\OdooClient;
use DateTimeImmutable;

/**
 * InventoryService — Inventory reconciliation, days, and trend (YTD).
 *
 * Three blocks of analytics, all sharing one YTD window (1 January → today)
 * and ALL deriving inventory value from the GL trial balance — Odoo's
 * stock.valuation.layer is Enterprise-only and absent on Community editions
 * or instances configured with manual_periodic valuation. The trial balance
 * is the lowest-common-denominator data source that's always present.
 *
 * 1. Reconciliation — physical stock vs trial balance, OHADA 3XX accounts
 *    (excluding 39X impairment). Stock side from stock.quant; GL side from
 *    account.move.line. Both snapshotted at the SAME date.
 *
 * 2. Inventory Days (textbook two-point avg, P&L-aligned COGS, YTD-rate):
 *        days = (avg_inventory × period_days_ytd) / cogs_ytd
 *      avg_inventory  = (beginning + ending) / 2
 *                       beginning = trial balance End Balance ≤ 31 Dec prior
 *                                   year  (= opening balance for YTD)
 *                       ending    = trial balance End Balance ≤ today
 *      cogs_ytd       = Σ account.move.line.balance for 60X accounts in YTD
 *      period_days_ytd= calendar days elapsed from 1 January
 *
 * 3. Trend — month-end inventory value, January through today, computed by
 *    walking posted move lines for inventory accounts in chronological order
 *    starting from the opening balance, snapshotting at each month-end.
 *
 * Data sources (everything in the GL):
 *   - Stock value:        stock.quant × product.standard_price (live only)
 *   - GL trial balance:   account.move.line, accounts code =like '3%' (not 39%)
 *   - COGS YTD:           account.move.line, accounts code =like '60%'
 *   - Trend chart:        same 3% accounts, walked forward from opening balance
 */
class InventoryService
{
    /** Cached company id to avoid repeated authenticate() calls. */
    private ?int $companyId = null;

    /** Cached internal location ids. */
    private ?array $internalLocationIds = null;

    /** Cached OHADA inventory account list (3% excluding 39%). */
    private ?array $inventoryAccountsCache = null;

    public function __construct(private OdooClient $odoo) {}

    /**
     * YTD inventory analysis. Period parameter is ignored — by design the
     * inventory page always shows year-to-date so reconciliation, days
     * calculation, and trend line all share one consistent window.
     */
    public function analyze(DateTimeImmutable $today, array $period = []): array
    {
        $errors = [];

        // Always YTD: 1 January of current year → today.
        $from     = $today->modify('first day of January')->setTime(0, 0, 0);
        $to       = $today->setTime(23, 59, 59);
        $asOfDate = $today;
        $label    = 'YTD ' . $today->format('Y');

        $current     = $this->safe(fn() => $this->getInventoryValueAt($asOfDate),    $errors, 'inventory_value') ?? $this->emptyCurrent();
        $glInventory = $this->safe(fn() => $this->getGLInventoryAccounts($asOfDate), $errors, 'gl_inventory')    ?? $this->emptyGLInventory();

        // Beginning inventory = trial balance just before YTD started
        // (i.e. closing balance of the prior accounting year). We feed this
        // back through getGLInventoryAccounts() which already implements the
        // correct domain — date <= asOfDate — using the same accounts list
        // (cached so we don't refetch the 3X account list).
        $priorYearEnd     = $from->modify('-1 day');
        $beginningGL      = $this->safe(fn() => $this->getGLInventoryAccounts($priorYearEnd), $errors, 'beginning_balance') ?? $this->emptyGLInventory();
        $beginningBalance = (float)($beginningGL['total_balance'] ?? 0);
        $endingBalance    = (float)($glInventory['total_balance']  ?? 0);

        $trend    = $this->safe(fn() => $this->buildGLTrend($from, $to), $errors, 'trend')    ?? ['by_month' => []];
        $cogsYtd  = $this->safe(fn() => $this->getCogsYtd($from, $to),    $errors, 'cogs')     ?? 0.0;
        $currency = $this->safe(fn() => $this->detectCurrency(),          $errors, 'currency') ?? 'USD';

        $reconciliation = $this->reconcile($current, $glInventory);
        $inventoryDays  = $this->computeInventoryDays($beginningBalance, $endingBalance, $cogsYtd, $from, $to);

        return [
            'current'         => $current,
            'gl_inventory'    => $glInventory,
            'reconciliation'  => $reconciliation,
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
    // Inventory value at as-of date — live snapshot from stock.quant
    // ─────────────────────────────────────────────────────────────────

    /**
     * In YTD-only mode the as-of date is always today, so this just
     * returns the live snapshot. Kept as a thin wrapper for clarity
     * and to leave the door open for historical reconstructions
     * (would have to rely on GL move lines now that
     * stock.valuation.layer is no longer assumed to exist).
     */
    private function getInventoryValueAt(DateTimeImmutable $asOfDate): array
    {
        return $this->getCurrentInventory();
    }

    // ─────────────────────────────────────────────────────────────────
    // Current inventory (live snapshot from stock.quant)
    // ─────────────────────────────────────────────────────────────────

    private function getCurrentInventory(): array
    {
        $companyId = $this->getCompanyId();

        // Filter to internal locations only (skip vendor/customer/transit/inventory adjustment).
        $locationIds = $this->getInternalLocationIds();
        $domain = [['company_id', '=', $companyId]];
        if (!empty($locationIds)) {
            $domain[] = ['location_id', 'in', $locationIds];
        }

        $quants = $this->odoo->searchRead(
            'stock.quant',
            $domain,
            ['product_id', 'quantity'],
            ['limit' => 10000]
        );

        // Aggregate qty by product
        $qtyByProduct = [];
        foreach ($quants as $q) {
            $pid = is_array($q['product_id'] ?? null) ? ($q['product_id'][0] ?? null) : null;
            if (!$pid) continue;
            $qtyByProduct[$pid] = ($qtyByProduct[$pid] ?? 0) + (float)($q['quantity'] ?? 0);
        }

        // Batch fetch product info
        $productIds = array_keys($qtyByProduct);
        $productInfo = [];
        if (!empty($productIds)) {
            // Read in chunks of 500 to avoid huge URLs
            foreach (array_chunk($productIds, 500) as $chunk) {
                $rows = $this->odoo->read('product.product', $chunk, ['name', 'standard_price', 'default_code']);
                foreach ($rows as $r) {
                    $productInfo[$r['id']] = $r;
                }
            }
        }

        $totalValue = 0.0;
        $totalQty   = 0.0;
        $byProduct  = [];

        foreach ($qtyByProduct as $pid => $qty) {
            $info = $productInfo[$pid] ?? null;
            $name = $info['name']           ?? ('Product #' . $pid);
            $cost = (float)($info['standard_price'] ?? 0);
            $code = $info['default_code']   ?? '';
            $value = $qty * $cost;

            $totalQty   += $qty;
            $totalValue += $value;

            // Group by product name (different products may share name; using id as key safer)
            $byProduct[$name] = [
                'qty'   => round($qty, 2),
                'cost'  => round($cost, 2),
                'value' => round($value, 2),
                'code'  => $code,
            ];
        }

        // Sort by value descending, keep top 50 in the table to avoid massive HTML
        uasort($byProduct, fn($a, $b) => $b['value'] <=> $a['value']);
        $byProduct = array_slice($byProduct, 0, 50, true);

        return [
            'total_value'  => round($totalValue, 2),
            'total_qty'    => round($totalQty,   2),
            'by_product'   => $byProduct,
            'record_count' => count($quants),
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    // GL inventory accounts (OHADA 3XX, excluding 39X impairment)
    // ─────────────────────────────────────────────────────────────────

    /**
     * Compute trial balance "End Balance" for inventory accounts as of a date.
     * Domain mirrors what Odoo's Trial Balance report uses when end_date = $asOfDate.
     */
    private function getGLInventoryAccounts(DateTimeImmutable $asOfDate): array
    {
        $accounts = $this->findInventoryAccounts();
        if (empty($accounts)) {
            return $this->emptyGLInventory();
        }

        $accountIds = array_column($accounts, 'id');
        $accountMap = [];
        foreach ($accounts as $a) {
            $accountMap[$a['id']] = [
                'name'    => $a['name'] ?? '',
                'code'    => $a['code'] ?? '',
                'balance' => 0.0,
            ];
        }

        // Domain shared by both query strategies — `date <= as-of` is the
        // critical filter that aligns this with Odoo's Trial Balance report.
        $domain = [
            ['parent_state', '=', 'posted'],
            ['account_id',   'in', $accountIds],
            ['company_id',   '=', $this->getCompanyId()],
            ['date',         '<=', $asOfDate->format('Y-m-d')],
        ];

        // Step 2: aggregate balance from posted move lines.
        // Use callModel(read_group) for efficient server-side aggregation.
        try {
            $groups = $this->odoo->callModel('account.move.line', 'read_group', [
                'domain'  => $domain,
                'fields'  => ['account_id', 'balance:sum'],
                'groupby' => ['account_id'],
            ]);

            foreach ($groups as $g) {
                $aid = is_array($g['account_id'] ?? null) ? ($g['account_id'][0] ?? null) : null;
                if ($aid && isset($accountMap[$aid])) {
                    $accountMap[$aid]['balance'] = round((float)($g['balance'] ?? 0), 2);
                }
            }
        } catch (\Throwable $e) {
            // Fallback: searchRead and aggregate client-side
            $lines = $this->odoo->searchRead(
                'account.move.line',
                $domain,
                ['account_id', 'balance'],
                ['limit' => 100000]
            );
            foreach ($lines as $line) {
                $aid = is_array($line['account_id'] ?? null) ? ($line['account_id'][0] ?? null) : null;
                if ($aid && isset($accountMap[$aid])) {
                    $accountMap[$aid]['balance'] += (float)($line['balance'] ?? 0);
                }
            }
            foreach ($accountMap as &$row) {
                $row['balance'] = round($row['balance'], 2);
            }
            unset($row);
        }

        // Drop accounts with zero balance to keep table compact
        $accountsList = array_filter(array_values($accountMap), fn($a) => abs($a['balance']) > 0.005);
        // Re-sort by code for readable display
        usort($accountsList, fn($a, $b) => strcmp($a['code'], $b['code']));

        $totalBalance = array_sum(array_column($accountsList, 'balance'));

        return [
            'total_balance' => round($totalBalance, 2),
            'by_account'    => $accountsList,
            'account_count' => count($accountsList),
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    // Reconciliation
    // ─────────────────────────────────────────────────────────────────

    private function reconcile(array $current, array $glInventory): array
    {
        $stockValue = (float)($current['total_value']       ?? 0);
        $glBalance  = (float)($glInventory['total_balance'] ?? 0);
        $variance   = $glBalance - $stockValue;
        $variancePct = $stockValue > 0 ? round(($variance / $stockValue) * 100, 2) : 0.0;

        // Accuracy %: 100 when stock == GL, drops as the gap widens.
        // Symmetric: uses max(|stock|, |GL|) as denominator so over- and
        // under-statement of GL are treated identically. Floor at 0.
        // Edge case: both zero → degenerate match (100%).
        $denominator = max(abs($stockValue), abs($glBalance));
        if ($denominator < 0.01) {
            $accuracyPct = 100.0; // both effectively zero
        } else {
            $gapPct      = min(100.0, abs($variance) / $denominator * 100);
            $accuracyPct = round(max(0.0, 100.0 - $gapPct), 2);
        }

        return [
            'stock_value'      => round($stockValue, 2),
            'gl_balance'       => round($glBalance,  2),
            'variance'         => round($variance,   2),
            'variance_percent' => $variancePct,
            'accuracy_percent' => $accuracyPct,
            'reconciled'       => abs($variance) < 0.01,
            'interpretation'   => $this->interpretVariance($variance, $stockValue, $accuracyPct),
        ];
    }

    private function interpretVariance(float $variance, float $stockValue, float $accuracyPct): string
    {
        if (abs($variance) < 0.01) {
            return 'Inventory perfectly reconciled with the general ledger.';
        }
        $accStr = number_format($accuracyPct, 1) . '%';

        if ($variance > 0) {
            return "GL balance exceeds stock value ({$accStr} accuracy) — possible unrecorded outflows or impairments not captured in stock.quant.";
        }
        return "Stock value exceeds GL balance ({$accStr} accuracy) — possible missing cost-of-goods entries or in-transit movements not yet posted.";
    }

    // ─────────────────────────────────────────────────────────────────
    // Trend (YTD inventory value evolution) — single Odoo call
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

    /** Lazy-loaded internal location ids (`usage = 'internal'`). */
    private function getInternalLocationIds(): array
    {
        if ($this->internalLocationIds !== null) {
            return $this->internalLocationIds;
        }
        try {
            $rows = $this->odoo->searchRead(
                'stock.location',
                [['usage', '=', 'internal']],
                ['id'],
                ['limit' => 500]
            );
            $this->internalLocationIds = array_column($rows, 'id');
        } catch (\Throwable $e) {
            $this->internalLocationIds = [];
        }
        return $this->internalLocationIds;
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

    private function emptyCurrent(): array
    {
        return [
            'total_value'  => 0,
            'total_qty'    => 0,
            'by_product'   => [],
            'record_count' => 0,
        ];
    }

    private function emptyGLInventory(): array
    {
        return [
            'total_balance' => 0,
            'by_account'    => [],
            'account_count' => 0,
        ];
    }
}
