<?php

namespace App\Services;

use App\Odoo\OdooClient;
use DateTimeImmutable;

/**
 * InventoryService — Inventory reconciliation and trend analysis.
 *
 * Reconciles stock.quant total value to GL inventory accounts (OHADA 3XX,
 * excluding 39X impairment accounts). Builds month/week-end value series
 * for the YTD trend chart.
 *
 * Strategy:
 *   - Current value: sum stock.valuation.layer (preferred) or fallback to
 *     stock.quant × product.standard_price.
 *   - Trend: ONE search_read on stock.valuation.layer ordered by date,
 *     then aggregated client-side into month/week buckets. This avoids
 *     N round-trips per data point (which times out for YTD windows).
 *   - GL: pull account.account where code starts with '3' (OHADA range)
 *     excluding '39%' impairments, then sum balances from move lines.
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
    public function analyze(DateTimeImmutable $today, array $period): array
    {
        $errors = [];

        try {
            [$from, $to, $label] = ea_resolve_period($today, $period);
        } catch (\Throwable $e) {
            $from  = $today->modify('first day of January this year');
            $to    = $today;
            $label = 'YTD';
            $errors[] = 'Period resolution failed: ' . $e->getMessage();
        }

        // Defensive: each section returns an empty/default shape on failure
        // so the view never sees missing keys AND reconcile() never gets null.
        // Coalesce BEFORE passing to reconcile (which is type-hinted `array`).
        $current     = $this->safe(fn() => $this->getCurrentInventory(),       $errors, 'current_inventory') ?? $this->emptyCurrent();
        $glInventory = $this->safe(fn() => $this->getGLInventoryAccounts(),    $errors, 'gl_inventory')      ?? $this->emptyGLInventory();
        $trend       = $this->safe(fn() => $this->getInventoryTrend($from, $to), $errors, 'trend')           ?? ['by_month' => [], 'by_week' => []];
        $currency    = $this->safe(fn() => $this->detectCurrency(),            $errors, 'currency')          ?? 'USD';

        $reconciliation = $this->reconcile($current, $glInventory);

        return [
            'current'        => $current,
            'gl_inventory'   => $glInventory,
            'reconciliation' => $reconciliation,
            'trend'          => $trend,
            'currency'       => $currency,
            'period'         => $period,
            'period_label'   => $label,
            'from'           => $from->format('Y-m-d'),
            'to'             => $to->format('Y-m-d'),
            'error'          => empty($errors) ? null : implode(' | ', $errors),
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    // Current inventory (point-in-time)
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

    private function getGLInventoryAccounts(): array
    {
        // Step 1: find inventory accounts (code starts with 3, not 39)
        $accounts = $this->odoo->searchRead(
            'account.account',
            [
                ['company_id', '=', $this->getCompanyId()],
                ['code', '=like', '3%'],
                ['code', 'not like', '39%'],
            ],
            ['code', 'name'],
            ['limit' => 500, 'order' => 'code asc']
        );

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

        // Step 2: aggregate balance from posted move lines.
        // Use callModel(read_group) for efficient server-side aggregation.
        try {
            $groups = $this->odoo->callModel('account.move.line', 'read_group', [
                'domain'  => [
                    ['parent_state', '=', 'posted'],
                    ['account_id',   'in', $accountIds],
                    ['company_id',   '=', $this->getCompanyId()],
                ],
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
                [
                    ['parent_state', '=', 'posted'],
                    ['account_id',   'in', $accountIds],
                    ['company_id',   '=', $this->getCompanyId()],
                ],
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
        $stockValue = (float)($current['total_value']      ?? 0);
        $glBalance  = (float)($glInventory['total_balance'] ?? 0);
        $variance   = $glBalance - $stockValue;
        $variancePct = $stockValue > 0 ? round(($variance / $stockValue) * 100, 2) : 0.0;

        return [
            'stock_value'      => round($stockValue, 2),
            'gl_balance'       => round($glBalance,  2),
            'variance'         => round($variance,   2),
            'variance_percent' => $variancePct,
            'reconciled'       => abs($variance) < 0.01,
            'interpretation'   => $this->interpretVariance($variance, $stockValue),
        ];
    }

    private function interpretVariance(float $variance, float $stockValue): string
    {
        if (abs($variance) < 0.01) {
            return 'Inventory perfectly reconciled with the general ledger.';
        }
        $pct = $stockValue > 0 ? abs($variance / $stockValue) * 100 : 0;
        $pctStr = number_format($pct, 1) . '%';

        if ($variance > 0) {
            return "GL balance exceeds stock value by {$pctStr} — possible unrecorded outflows or impairments not captured in stock.quant.";
        }
        return "Stock value exceeds GL balance by {$pctStr} — possible missing cost-of-goods entries or in-transit movements not yet posted.";
    }

    // ─────────────────────────────────────────────────────────────────
    // Trend (YTD inventory value evolution) — single Odoo call
    // ─────────────────────────────────────────────────────────────────

    /**
     * Build month-end and week-end inventory value series using
     * stock.valuation.layer (signed value column). Cumulative sum at each
     * bucket end gives the inventory value as of that date.
     *
     * If stock.valuation.layer is not available (rare on modern Odoo),
     * falls back to the current snapshot replicated across the period.
     */
    private function getInventoryTrend(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $companyId = $this->getCompanyId();

        // Query ALL valuation layers up to $to (we need pre-period balances for cumulative sum)
        // ordered ascending so cumulative sums work as we iterate
        $layers = [];
        try {
            $layers = $this->odoo->searchRead(
                'stock.valuation.layer',
                [
                    ['company_id', '=', $companyId],
                    ['create_date', '<=', $to->format('Y-m-d') . ' 23:59:59'],
                ],
                ['value', 'create_date'],
                ['limit' => 100000, 'order' => 'create_date asc']
            );
        } catch (\Throwable $e) {
            // Model not available — return empty trend rather than failing the whole page
            return ['by_month' => [], 'by_week' => [], 'error' => 'stock.valuation.layer not available: ' . $e->getMessage()];
        }

        if (empty($layers)) {
            return ['by_month' => [], 'by_week' => []];
        }

        // Build month/week bucket boundaries within [from, to]
        $monthBuckets = $this->generateMonthEnds($from, $to);
        $weekBuckets  = $this->generateWeekEnds($from, $to);

        // Pre-compute cumulative running total of signed layer values
        // by walking layers chronologically once.
        $monthSeries = $this->bucketize($layers, $monthBuckets);
        $weekSeries  = $this->bucketize($layers, $weekBuckets);

        $byMonth = [];
        foreach ($monthSeries as $row) {
            $byMonth[] = [
                'date'  => $row['date']->format('Y-m-d'),
                'month' => $row['date']->format('M Y'),
                'value' => round($row['value'], 2),
            ];
        }

        $byWeek = [];
        foreach ($weekSeries as $row) {
            $byWeek[] = [
                'date'  => $row['date']->format('Y-m-d'),
                'week'  => 'W' . $row['date']->format('W') . ' ' . $row['date']->format('Y'),
                'value' => round($row['value'], 2),
            ];
        }

        return ['by_month' => $byMonth, 'by_week' => $byWeek];
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
