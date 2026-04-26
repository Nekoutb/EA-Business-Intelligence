<?php

namespace App\Services;

use App\Odoo\OdooClient;
use DateTimeImmutable;

/**
 * InventoryService — Inventory reconciliation and trend analysis.
 *
 * Reconciles stock value to the GL trial balance for OHADA 3XX accounts
 * (excluding 39X impairment). Both sides are snapshotted at the SAME date
 * (the period end, clamped to today) so the comparison is like-for-like.
 *
 * Data sources:
 *   - Stock value at as-of date:
 *       * as-of >= today  → stock.quant × product.standard_price (live)
 *       * as-of <  today  → stock.valuation.layer cumulative sum (historical)
 *   - GL trial balance at as-of date:
 *       sum(account.move.line.balance) WHERE account.code =like '3%' AND
 *       NOT '39%' AND parent_state='posted' AND date <= as-of
 *       → matches Odoo's Trial Balance "End Balance" column for the same range
 *   - Trend chart: one search_read on stock.valuation.layer, bucketized
 *     client-side into month/week ends (O(N+B) instead of O(N*B)).
 */
class InventoryService
{
    /** Cached company id to avoid repeated authenticate() calls. */
    private ?int $companyId = null;

    /** Cached internal location ids. */
    private ?array $internalLocationIds = null;

    public function __construct(private OdooClient $odoo) {}

    /**
     * @param array $period ['type' => 'ytd'|'mtd'|'month'|'yoy', 'year' => int, 'month' => int]
     */
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

        $current     = $this->safe(fn() => $this->getInventoryValueAt($asOfDate),       $errors, 'inventory_value') ?? $this->emptyCurrent();
        $glInventory = $this->safe(fn() => $this->getGLInventoryAccounts($asOfDate),    $errors, 'gl_inventory')    ?? $this->emptyGLInventory();
        $trend       = $this->safe(fn() => $this->getInventoryTrend($from, $to),         $errors, 'trend')           ?? ['by_month' => []];
        $cogsYtd     = $this->safe(fn() => $this->getCogsYtd($from, $to),                $errors, 'cogs')            ?? 0.0;
        $currency    = $this->safe(fn() => $this->detectCurrency(),                      $errors, 'currency')        ?? 'USD';

        $reconciliation  = $this->reconcile($current, $glInventory);
        $inventoryDays   = $this->computeInventoryDays($trend, $cogsYtd, $from, $to);

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
    // Inventory Days (YTD)
    // ─────────────────────────────────────────────────────────────────

    /**
     * COGS YTD inferred from inventory outflows.
     *
     * Sum of negative values on stock.valuation.layer between $from and $to.
     * A negative layer value represents stock leaving the warehouse at cost
     * (sale, scrap, internal-transfer-to-customer-location). This matches
     * the 'consumption' side of the inventory days ratio better than a
     * GL-based COGS reconstruction, because it's measured at the ledger
     * Odoo itself uses for inventory valuation.
     */
    private function getCogsYtd(DateTimeImmutable $from, DateTimeImmutable $to): float
    {
        $layers = $this->odoo->searchRead(
            'stock.valuation.layer',
            [
                ['company_id', '=', $this->getCompanyId()],
                ['create_date', '>=', $from->format('Y-m-d')],
                ['create_date', '<=', $to->format('Y-m-d') . ' 23:59:59'],
            ],
            ['value'],
            ['limit' => 200000]
        );

        $cogs = 0.0;
        foreach ($layers as $l) {
            $v = (float)($l['value'] ?? 0);
            if ($v < 0) {
                $cogs += -$v;   // accumulate as positive
            }
        }
        return round($cogs, 2);
    }

    /**
     * Days of inventory outstanding, YTD basis.
     *
     *   days_outstanding = avg_inventory / (cogs_ytd / period_days_ytd)
     *                    = (avg_inventory * period_days_ytd) / cogs_ytd
     *
     * This answers "how many days of stock am I holding at the current
     * YTD consumption rate?" — not annualized, so the figure remains
     * stable as the year progresses (provided consumption stays steady).
     */
    private function computeInventoryDays(array $trend, float $cogs, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        // Avg inventory: mean of month-end snapshots in the trend
        $points = $trend['by_month'] ?? [];
        $sum = 0.0;
        $n   = 0;
        foreach ($points as $p) {
            $sum += (float)($p['value'] ?? 0);
            $n++;
        }
        $avgInventory = $n > 0 ? $sum / $n : 0.0;

        $periodDays = max(1, (int)$from->diff($to)->days + 1);

        $days = ($cogs > 0.01)
            ? ($avgInventory * $periodDays) / $cogs
            : 0.0;

        return [
            'avg_inventory'    => round($avgInventory, 2),
            'cogs_ytd'         => round($cogs, 2),
            'period_days'      => $periodDays,
            'days_outstanding' => round($days, 1),
            'sample_size'      => $n,
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    // Inventory value at as-of date
    // ─────────────────────────────────────────────────────────────────

    /**
     * Dispatch: live snapshot for "now", historical reconstruction for past.
     */
    private function getInventoryValueAt(DateTimeImmutable $asOfDate): array
    {
        $todayStr = (new DateTimeImmutable())->format('Y-m-d');
        if ($asOfDate->format('Y-m-d') >= $todayStr) {
            return $this->getCurrentInventory();
        }
        return $this->getHistoricalInventory($asOfDate);
    }

    /**
     * Historical inventory at a past date — reconstruct from
     * stock.valuation.layer cumulative sums per product.
     * Layer.value is signed (positive on inflow, negative on outflow / write-down)
     * so the running sum equals the net inventory book value at that point.
     */
    private function getHistoricalInventory(DateTimeImmutable $asOfDate): array
    {
        $companyId = $this->getCompanyId();

        try {
            $layers = $this->odoo->searchRead(
                'stock.valuation.layer',
                [
                    ['company_id', '=', $companyId],
                    ['create_date', '<=', $asOfDate->format('Y-m-d') . ' 23:59:59'],
                ],
                ['product_id', 'quantity', 'value'],
                ['limit' => 100000]
            );
        } catch (\Throwable $e) {
            // Model unavailable — fall back to current snapshot but flag it
            $current = $this->getCurrentInventory();
            $current['historical_unavailable'] = true;
            return $current;
        }

        if (empty($layers)) {
            return $this->emptyCurrent();
        }

        // Aggregate qty + value per product
        $byPid = [];
        $totalValue = 0.0;
        $totalQty   = 0.0;
        foreach ($layers as $l) {
            $pid = is_array($l['product_id'] ?? null) ? ($l['product_id'][0] ?? null) : null;
            if (!$pid) continue;
            if (!isset($byPid[$pid])) {
                $byPid[$pid] = ['qty' => 0.0, 'value' => 0.0];
            }
            $q = (float)($l['quantity'] ?? 0);
            $v = (float)($l['value']    ?? 0);
            $byPid[$pid]['qty']   += $q;
            $byPid[$pid]['value'] += $v;
            $totalQty   += $q;
            $totalValue += $v;
        }

        // Pull product names/codes
        $pids = array_keys($byPid);
        $info = [];
        if (!empty($pids)) {
            foreach (array_chunk($pids, 500) as $chunk) {
                foreach ($this->odoo->read('product.product', $chunk, ['name', 'default_code']) as $r) {
                    $info[$r['id']] = $r;
                }
            }
        }

        $byProduct = [];
        foreach ($byPid as $pid => $row) {
            $name = $info[$pid]['name'] ?? ('Product #' . $pid);
            $code = $info[$pid]['default_code'] ?? '';
            $qty  = $row['qty'];
            $val  = $row['value'];
            // Implied unit cost (avoids needing a separate read on standard_price,
            // and reflects the actual cost basis used by Odoo's valuation engine)
            $cost = abs($qty) > 0.0001 ? $val / $qty : 0.0;
            $byProduct[$name] = [
                'qty'   => round($qty, 2),
                'cost'  => round($cost, 2),
                'value' => round($val, 2),
                'code'  => $code,
            ];
        }
        uasort($byProduct, fn($a, $b) => $b['value'] <=> $a['value']);
        $byProduct = array_slice($byProduct, 0, 50, true);

        return [
            'total_value'  => round(max(0.0, $totalValue), 2),
            'total_qty'    => round($totalQty, 2),
            'by_product'   => $byProduct,
            'record_count' => count($layers),
            'historical'   => true,
        ];
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
        // Step 1: find inventory accounts (code starts with 3, excluding 39X impairment).
        //
        // Note: `account.account.company_id` was REMOVED in Odoo 17 — the chart of
        // accounts is now shared across companies via the `company_ids` m2m. Filtering
        // by company here would crash. We rely on the `company_id` filter on
        // account.move.line (which still has it) to scope balances per company.
        //
        // Note: we don't combine `=like '3%'` with `not like '39%'` in the domain,
        // because Odoo's `like` operator auto-wraps the value with %s — making
        // `not like '39%'` equivalent to `NOT LIKE '%39%%'`, which is unreliable
        // for "doesn't start with 39". Instead we fetch all 3X accounts and
        // exclude 39X client-side.
        $accounts = $this->odoo->searchRead(
            'account.account',
            [
                ['code', '=like', '3%'],
            ],
            ['code', 'name'],
            ['limit' => 1000, 'order' => 'code asc']
        );

        // Client-side: keep only accounts whose code starts with 3 but not 39
        $accounts = array_values(array_filter($accounts, function ($a) {
            $code = (string)($a['code'] ?? '');
            return str_starts_with($code, '3') && !str_starts_with($code, '39');
        }));

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
     * Month-end inventory value series for the YTD chart.
     *
     * Single search_read on stock.valuation.layer ordered chronologically;
     * cumulative running sum is snapshotted at each month-end bucket within
     * [$from, $to]. O(N+B) — one round-trip total regardless of bucket count.
     *
     * The cumulative sum starts from the beginning of the layers (not from
     * $from) because layers BEFORE January 1 represent opening inventory —
     * we need that base for January's value to be correct.
     */
    private function getInventoryTrend(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $companyId = $this->getCompanyId();

        $layers = [];
        try {
            $layers = $this->odoo->searchRead(
                'stock.valuation.layer',
                [
                    ['company_id', '=', $companyId],
                    ['create_date', '<=', $to->format('Y-m-d') . ' 23:59:59'],
                ],
                ['value', 'create_date'],
                ['limit' => 200000, 'order' => 'create_date asc']
            );
        } catch (\Throwable $e) {
            return ['by_month' => [], 'error' => 'stock.valuation.layer not available: ' . $e->getMessage()];
        }

        if (empty($layers)) {
            return ['by_month' => []];
        }

        $monthBuckets = $this->generateMonthEnds($from, $to);
        $monthSeries  = $this->bucketize($layers, $monthBuckets);

        $byMonth = [];
        foreach ($monthSeries as $row) {
            $byMonth[] = [
                'date'  => $row['date']->format('Y-m-d'),
                'month' => $row['date']->format('M Y'),
                'value' => round($row['value'], 2),
            ];
        }

        return ['by_month' => $byMonth];
    }

    /**
     * Walk through chronologically-ordered valuation layers and snapshot
     * the cumulative sum at each bucket boundary. O(N + B) instead of O(N*B).
     */
    private function bucketize(array $layers, array $bucketEnds): array
    {
        $running = 0.0;
        $li = 0;
        $count = count($layers);
        $out = [];

        foreach ($bucketEnds as $bucketEnd) {
            $endStr = $bucketEnd->format('Y-m-d') . ' 23:59:59';
            // Advance through layers up to and including bucketEnd
            while ($li < $count) {
                $layerDate = (string)($layers[$li]['create_date'] ?? '');
                if ($layerDate === '' || $layerDate <= $endStr) {
                    $running += (float)($layers[$li]['value'] ?? 0);
                    $li++;
                } else {
                    break;
                }
            }
            $out[] = ['date' => $bucketEnd, 'value' => max(0, $running)];
        }

        return $out;
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

    /** Sundays (week ends) in [from, to], capped at $to. */
    private function generateWeekEnds(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $ends = [];
        // Find first Sunday on/after $from
        $cursor = $from->modify('Sunday this week');
        if ($cursor < $from) {
            $cursor = $cursor->modify('+7 days');
        }
        for ($i = 0; $i < 260 && $cursor <= $to; $i++) {
            $ends[] = $cursor;
            $cursor = $cursor->modify('+7 days');
        }
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
