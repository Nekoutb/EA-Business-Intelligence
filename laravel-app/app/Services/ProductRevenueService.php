<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant;

/**
 * Composition service for the Product Revenue TSD page.
 *
 * Joins four inputs into the shape the Blade view consumes:
 *   - Per-product budgets from BudgetService::loadProducts(tenant, year)
 *   - Monthly scanned volumes from ProductVolumeService::monthlyVolumes(year)
 *   - Class 70 GL revenue from GLRevenueService::fetchClass70Yearly(year)
 *     (per-account monthly amounts + matched-to-product mapping)
 *   - Product registry from config('budget_products')
 *
 * Revenue source per product is governed by `revenue_source` in the
 * registry:
 *   - 'gl_706_named'  → actual revenue from class 706 sub-accounts that
 *                       name-match the product's `revenue_match` block;
 *                       unit price is derived as revenue ÷ qty (which is
 *                       the user-facing "real" rate, not the planned one).
 *   - 'scan_tariff'   → actual revenue from scan_count × operational_tariff
 *                       (the original method — unchanged for vehicles + wood).
 *   - 'pending'       → budget-only product; actuals stay null until Odoo
 *                       integration is configured upstream.
 *
 * Output structure (consumed by resources/views/product-revenue-tsd.blade.php):
 *
 *   [
 *     'year'        => 2026,
 *     'today_month' => 5,
 *     'vat_rate'    => 0.1925,
 *     'categories'  => [...],
 *     'products'    => [ 'cars' => [...], 'container_import_40' => [...], ... ],
 *     'totals'      => [
 *       'tracked'                    => [...],
 *       'pending_ytd_budget_revenue' => float,
 *       'class70_ytd_revenue'        => float,   // grand total (= Dashboard HT)
 *       'class70_monthly'            => [1..12 => float],
 *     ],
 *     'gl_diagnostic' => [
 *       'matched'   => [ ['code', 'name', 'product_key', 'ytd_revenue'], ... ],
 *       'unmatched' => [ ['code', 'name', 'ytd_revenue'], ... ],
 *     ],
 *   ]
 */
final class ProductRevenueService
{
    private BudgetService $budgets;
    private ProductVolumeService $volumes;
    private GLRevenueService $gl;

    public function __construct(BudgetService $budgets, ProductVolumeService $volumes, GLRevenueService $gl)
    {
        $this->budgets = $budgets;
        $this->volumes = $volumes;
        $this->gl      = $gl;
    }

    /**
     * Compute the per-product P&V analysis for the page.
     *
     * The `$periodType` and `$monthFilter` arguments determine the slice
     * of the year that ACT and BUD figures cover:
     *
     *   'ytd'   → months 1..todayMonth   (cumulative, default)
     *   'mtd'   → todayMonth only        (current month only)
     *   'month' → $monthFilter only      (specific past month)
     *
     * The budget proration formula the user expects is annual/12 × N
     * where N is the number of months in the active range, e.g.:
     *   - MTD has N=1   → annual ÷ 12         per product
     *   - YTD at May has N=5  → annual × 5/12 per product
     *
     * That math is implicit in the per-month accumulation loop below
     * (the YTD bucket sums each month's stored qty × price, so even
     * monthly distribution of an annual figure rolls forward correctly).
     */
    public function analyze(
        Tenant $tenant,
        int $year,
        ?\DateTimeImmutable $today = null,
        string $periodType = 'ytd',
        ?int $monthFilter = null
    ): array {
        $today      = $today ?? new \DateTimeImmutable('today');
        $todayMonth = $this->todayMonthForYear($year, $today);

        // Resolve the active month range. End is clamped to todayMonth
        // for current-year requests so we never "leak" data from months
        // that haven't happened yet (past years honour 1..12).
        [$startMonth, $endMonth] = $this->resolveMonthRange(
            $periodType,
            $todayMonth,
            $monthFilter ?? $todayMonth,
            $year,
            (int) $today->format('Y')
        );
        $monthsInRange = max(0, $endMonth - $startMonth + 1);

        $registry      = (array) config('budget_products.products', []);
        $categoriesCfg = (array) config('budget_products.categories', []);
        $vatRate       = (float) config('budget_products.vat_rate', 0.0);

        $budgets = $this->budgets->loadProducts($tenant->id, $year);
        $volumes = $this->volumes->monthlyVolumes($year);
        $class70 = $this->gl->fetchClass70Yearly($year);
        $glMatch = $this->gl->matchAccountsToProducts($class70);

        $glRevenuePerProduct = (array) $glMatch['matched'];   // [key => [month => xaf]]
        $glAccounts          = (array) $class70['accounts'];
        $glByAccountMonth    = (array) $class70['by_account_month'];
        $glMonthlyTotal      = (array) $class70['monthly_totals'];
        $assigned            = (array) $glMatch['assigned'];   // [account_id => product_key]
        $redistributed       = (array) ($glMatch['redistributed'] ?? []);
        $unmatchedIds        = array_keys((array) $glMatch['unmatched']);

        $products = [];
        $tot = [
            'volume_effect_xaf'    => 0.0,
            'price_effect_xaf'     => 0.0,
            'ytd_actual_revenue'   => 0.0,
            'ytd_budget_revenue'   => 0.0,
        ];
        $pendingYtdBudgetRev = 0.0;

        foreach ($registry as $product) {
            $key           = (string) $product['key'];
            $revenueSource = (string) ($product['revenue_source'] ?? 'scan_tariff');
            $pending       = $revenueSource === 'pending'
                          || (($product['actuals_source'] ?? 'pending') !== 'odoo');

            $budgetRow   = $budgets[$key] ?? ['unit_price' => 0.0, 'volumes' => array_fill(1, 12, 0.0)];
            $budgetPrice = (float) $budgetRow['unit_price'];
            $budgetQty   = $this->vector($budgetRow['volumes']);

            $operationalTariff = (float) ($product['operational_tariff'] ?? $budgetPrice);

            // ── Compute actual qty + actual revenue per month, branching
            //    on the configured revenue source.
            if ($pending) {
                $actualQty     = array_fill(1, 12, null);
                $actualRevenue = array_fill(1, 12, null);
                $actualPrice   = array_fill(1, 12, null);
            } else {
                // qty: scanned volume from ops (same for both sources)
                $actualQty = $this->actualVector($volumes[$key] ?? [], $todayMonth);

                if ($revenueSource === 'gl_706_named') {
                    // Revenue from matched 706 accounts. Price = rev / qty
                    // per month (the user's explicit ask: actual unit price
                    // = revenue ÷ quantities). For months where qty == 0
                    // but revenue exists (rare — invoicing precedes scan),
                    // we surface revenue with a null price to avoid
                    // dividing by zero.
                    $glMonthly = (array) ($glRevenuePerProduct[$key] ?? array_fill(1, 12, 0.0));
                    [$actualRevenue, $actualPrice] = $this->revenuePriceFromGL($glMonthly, $actualQty, $todayMonth);
                } else {
                    // scan_tariff: rev = qty × operational_tariff, price = tariff.
                    [$actualRevenue, $actualPrice] = $this->revenuePriceFromTariff($actualQty, $operationalTariff, $todayMonth);
                }
            }

            // Period figures (sum each month from startMonth..endMonth).
            // For YTD this is 1..todayMonth (cumulative). For MTD it's
            // todayMonth..todayMonth (single bucket). For a picked
            // month, the same single-bucket pattern.
            $ytdActQty = 0; $ytdActRev = 0.0;
            $ytdBudQty = 0; $ytdBudRev = 0.0;
            for ($m = $startMonth; $m <= $endMonth; $m++) {
                $aq = $actualQty[$m] ?? null;
                $ar = $actualRevenue[$m] ?? null;
                if ($aq !== null) {
                    $ytdActQty += (int) $aq;
                }
                if ($ar !== null) {
                    $ytdActRev += (float) $ar;
                }
                $ytdBudQty += (int) $budgetQty[$m];
                $ytdBudRev += (float) $budgetQty[$m] * $budgetPrice;
            }

            // Variance — only meaningful when both budget and actuals exist.
            $hasBudget = $ytdBudQty > 0;
            if (!$pending && $hasBudget) {
                $volEffect   = ((float) $ytdActQty - (float) $ytdBudQty) * $budgetPrice;
                $achievedAvg = $ytdActQty > 0 ? $ytdActRev / $ytdActQty : 0.0;
                $priceEffect = ($achievedAvg - $budgetPrice) * (float) $ytdActQty;
                $netVar      = $ytdActRev - $ytdBudRev;
                $pct         = 100.0 * $ytdActQty / $ytdBudQty;

                $tot['ytd_budget_revenue']  += $ytdBudRev;
                $tot['ytd_actual_revenue']  += $ytdActRev;
                $tot['volume_effect_xaf']   += $volEffect;
                $tot['price_effect_xaf']    += $priceEffect;
            } elseif (!$pending && !$hasBudget) {
                // Containers (typically): actuals exist but no budget was
                // entered. Revenue still rolls into the tracked total
                // (we don't want to lose it), but variance is N/A.
                $volEffect = 0.0; $priceEffect = 0.0; $netVar = null; $pct = null;
                $tot['ytd_actual_revenue'] += $ytdActRev;
            } else {
                // Pending — budget without actuals.
                $volEffect = 0.0; $priceEffect = 0.0; $netVar = null; $pct = null;
                $pendingYtdBudgetRev += $ytdBudRev;
            }

            $products[$key] = [
                'key'                 => $key,
                'label'               => (string) $product['label'],
                'category'            => (string) $product['category'],
                'color'               => (string) ($product['color'] ?? '#0d6e6e'),
                'pending'             => $pending,
                'volume_only'         => (bool) ($product['volume_only'] ?? false),
                'revenue_source'      => $revenueSource,
                'has_budget'          => $hasBudget,
                'budget_price'        => $budgetPrice,
                'budget_qty'          => array_values($budgetQty),
                'actual_qty'          => array_values($actualQty),
                'actual_price'        => array_values($actualPrice),
                'actual_revenue'      => array_values($actualRevenue),
                'ytd_actual_qty'      => $ytdActQty,
                'ytd_budget_qty'      => $ytdBudQty,
                'ytd_actual_revenue'  => $ytdActRev,
                'ytd_budget_revenue'  => $ytdBudRev,
                'volume_effect_xaf'   => $volEffect,
                'price_effect_xaf'    => $priceEffect,
                'net_variance_xaf'    => $netVar,
                'pct_of_plan'         => $pct,
            ];
        }

        $tot['net_variance_xaf'] = $tot['ytd_actual_revenue'] - $tot['ytd_budget_revenue'];

        // ── Class 70 period total (Dashboard-comparable HT revenue figure
        //    for the active range). Same start/end window as everything
        //    else, so flipping MTD ↔ YTD updates the headline strip too.
        $class70YtdTotal = 0.0;
        for ($m = $startMonth; $m <= $endMonth; $m++) {
            $class70YtdTotal += (float) ($glMonthlyTotal[$m] ?? 0);
        }

        // ── Diagnostic: every 706 account + which product it landed in.
        //    We pass endMonth (not todayMonth) so the per-account YTD
        //    figures the diagnostic shows reflect the same window as
        //    the rest of the page.
        $diagnostic = $this->buildDiagnostic(
            $glAccounts, $glByAccountMonth, $assigned, $redistributed, $unmatchedIds, $registry, $endMonth
        );

        // ── Reconciliation diagnostic: class 70 postings whose parent
        //    move is NOT a customer invoice/refund. These are the lines
        //    that make TSD's class-70 grand total exceed the Dashboard's
        //    invoice-based revenue figure. Surfaced as a separate panel
        //    in the view so operators can pinpoint where the gap lives.
        //    Window covers months 1..endMonth so YTD context is preserved
        //    even on MTD view (the gap is cumulative by nature).
        $nonInvoice = $this->gl->fetchNonInvoiceClass70Lines($year, $endMonth);

        return [
            'year'        => $year,
            'today_month' => $todayMonth,
            'period_type' => $periodType,
            'start_month' => $startMonth,
            'end_month'   => $endMonth,
            'months_in_range' => $monthsInRange,
            'vat_rate'    => $vatRate,
            'categories'  => $categoriesCfg,
            'products'    => $products,
            'totals'      => [
                'tracked'                    => $tot,
                'pending_ytd_budget_revenue' => $pendingYtdBudgetRev,
                // Full budget across every product that has a budget set
                // — tracked (vehicles + wood) PLUS pending (Other Cargo).
                // This is the figure the user expects to see prorated as
                // annual ÷ 12 × N, since their planning document covers
                // all budgeted lines, not just the tracked-with-actuals
                // subset. Containers stay at 0 (no budget by design).
                'all_period_budget_revenue'  => (float) $tot['ytd_budget_revenue'] + $pendingYtdBudgetRev,
                'class70_ytd_revenue'        => $class70YtdTotal,
                'class70_monthly'            => $glMonthlyTotal,
            ],
            'gl_diagnostic'        => $diagnostic,
            'class70_non_invoice'  => $nonInvoice,
        ];
    }

    /**
     * Translate a master-filter period type into a [startMonth, endMonth]
     * window in month-of-year terms (1..12).
     *
     *   'mtd'   → [todayMonth, todayMonth]  current month only
     *   'month' → [monthFilter, monthFilter] user-picked month only
     *   'ytd'   → [1, todayMonth]           cumulative (default)
     *
     * For a past year, "todayMonth" becomes 12 (todayMonthForYear()
     * already enforces this) so YTD covers the full year and MTD lands
     * on December — which is the sensible default for historical browsing.
     *
     * @return array{0:int,1:int}
     */
    private function resolveMonthRange(string $periodType, int $todayMonth, int $monthFilter, int $year, int $yearOfToday): array
    {
        $monthFilter = max(1, min(12, $monthFilter));
        $todayMonth  = max(1, min(12, $todayMonth));

        switch ($periodType) {
            case 'mtd':
                // Current month only — past years clamp to Dec via todayMonth.
                return [$todayMonth, $todayMonth];

            case 'month':
                // User-picked month. For the current year, never look
                // past today (a future month would always read zeros).
                $end = $year === $yearOfToday ? min($monthFilter, $todayMonth) : $monthFilter;
                return [$end, $end];

            case 'ytd':
            default:
                return [1, $todayMonth];
        }
    }

    /**
     * Compose monthly revenue + monthly per-unit price for products
     * whose actual revenue comes from the GL (class 706 named accounts).
     *
     * @return array{0: array<int, ?float>, 1: array<int, ?float>} [revenue, price]
     */
    private function revenuePriceFromGL(array $glMonthly, array $actualQty, int $todayMonth): array
    {
        $rev = []; $price = [];
        for ($m = 1; $m <= 12; $m++) {
            if ($m > $todayMonth) {
                $rev[$m]   = null;
                $price[$m] = null;
                continue;
            }
            $monthlyRev = (float) ($glMonthly[$m] ?? 0);
            $rev[$m] = $monthlyRev;
            $qty = $actualQty[$m] ?? null;
            $price[$m] = ($qty !== null && $qty > 0)
                ? $monthlyRev / (float) $qty
                : null;
        }
        return [$rev, $price];
    }

    /**
     * Compose monthly revenue + monthly per-unit price for products on
     * the scan_count × operational_tariff method (vehicles + wood,
     * unchanged behaviour).
     *
     * @return array{0: array<int, ?float>, 1: array<int, ?float>} [revenue, price]
     */
    private function revenuePriceFromTariff(array $actualQty, float $tariff, int $todayMonth): array
    {
        $rev = []; $price = [];
        for ($m = 1; $m <= 12; $m++) {
            if ($m > $todayMonth) {
                $rev[$m]   = null;
                $price[$m] = null;
                continue;
            }
            $qty = $actualQty[$m] ?? null;
            $rev[$m]   = $qty === null ? null : (float) $qty * $tariff;
            $price[$m] = $tariff;
        }
        return [$rev, $price];
    }

    /**
     * Build the matched/redistributed/unmatched diagnostic the view
     * shows under the variance table. Three buckets:
     *
     *   matched       — accounts directly assigned to ONE product
     *   redistributed — accounts split across multiple products via a
     *                   config('budget_products.revenue_redistribute') rule
     *   unmatched     — accounts not claimed by either path; flagged in_706
     *                   so the view can highlight the ones inside the
     *                   container revenue class as "needs attention"
     *
     * YTD revenue is included on every row so the operator sees at a
     * glance what's contributing where.
     */
    private function buildDiagnostic(
        array $accounts,
        array $byAccountMonth,
        array $assigned,
        array $redistributed,
        array $unmatchedIds,
        array $registry,
        int $todayMonth
    ): array {
        $productLabel = [];
        foreach ($registry as $p) $productLabel[(string) $p['key']] = (string) $p['label'];

        $sumYtd = function (array $monthly) use ($todayMonth) {
            $sum = 0.0;
            for ($m = 1; $m <= $todayMonth; $m++) {
                $sum += (float) ($monthly[$m] ?? 0);
            }
            return $sum;
        };

        $matched = [];
        foreach ($assigned as $accountId => $productKey) {
            $info = $accounts[$accountId] ?? null;
            if (!$info) continue;
            $matched[] = [
                'code'          => (string) $info['code'],
                'name'          => (string) $info['name'],
                'product_key'   => $productKey,
                'product_label' => $productLabel[$productKey] ?? $productKey,
                'ytd_revenue'   => $sumYtd((array) ($byAccountMonth[$accountId] ?? [])),
            ];
        }
        usort($matched, fn($a, $b) => strcmp($a['code'], $b['code']));

        $redistributedOut = [];
        foreach ($redistributed as $accountId => $info) {
            $acct = $accounts[$accountId] ?? null;
            if (!$acct) continue;
            $targetsForDisplay = [];
            foreach ((array) ($info['targets'] ?? []) as $targetKey => $share) {
                $targetsForDisplay[] = [
                    'product_key'   => (string) $targetKey,
                    'product_label' => $productLabel[(string) $targetKey] ?? (string) $targetKey,
                    'share_pct'     => 100.0 * (float) $share,
                ];
            }
            $redistributedOut[] = [
                'code'        => (string) $acct['code'],
                'name'        => (string) $acct['name'],
                'description' => (string) ($info['description'] ?? ''),
                'targets'     => $targetsForDisplay,
                'ytd_revenue' => $sumYtd((array) ($byAccountMonth[$accountId] ?? [])),
            ];
        }
        usort($redistributedOut, fn($a, $b) => strcmp($a['code'], $b['code']));

        $unmatched = [];
        foreach ($unmatchedIds as $accountId) {
            $info = $accounts[$accountId] ?? null;
            if (!$info) continue;
            $unmatched[] = [
                'code'        => (string) $info['code'],
                'name'        => (string) $info['name'],
                'ytd_revenue' => $sumYtd((array) ($byAccountMonth[$accountId] ?? [])),
                'in_706'      => str_starts_with((string) $info['code'], '706'),
            ];
        }
        usort($unmatched, fn($a, $b) => strcmp($a['code'], $b['code']));

        return [
            'matched'       => $matched,
            'redistributed' => $redistributedOut,
            'unmatched'     => $unmatched,
        ];
    }

    private function todayMonthForYear(int $year, \DateTimeImmutable $today): int
    {
        $currentYear = (int) $today->format('Y');
        if ($year < $currentYear) return 12;
        if ($year > $currentYear) return 0;
        return (int) $today->format('n');
    }

    private function vector(array $src): array
    {
        $out = [];
        for ($m = 1; $m <= 12; $m++) {
            $out[$m] = (float) ($src[$m] ?? $src[(string) $m] ?? 0);
        }
        return $out;
    }

    /**
     * Trim a fetched monthly volumes array so months > today_month return
     * null (future months have no actuals yet). Keeps zero values for
     * past months where the operation legitimately scanned nothing.
     */
    private function actualVector(array $src, int $todayMonth): array
    {
        $out = [];
        for ($m = 1; $m <= 12; $m++) {
            if ($m > $todayMonth) {
                $out[$m] = null;
            } else {
                $out[$m] = $src[$m] ?? null;
            }
        }
        return $out;
    }
}
