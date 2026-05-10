<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant;

/**
 * Composition service for the Product Revenue TSD page.
 *
 * Joins three inputs into the shape the Blade view consumes:
 *   - Per-product budgets from BudgetService::loadProducts(tenant, year)
 *   - Monthly scanned volumes from ProductVolumeService::monthlyVolumes(year)
 *   - The product registry from config('budget_products')
 *
 * Output structure (consumed by resources/views/product-revenue-tsd.blade.php):
 *
 *   [
 *     'year'        => 2026,
 *     'today_month' => 5,   // months 1..today_month carry actuals; rest = null
 *     'vat_rate'    => 0.1925,
 *     'categories'  => [...config('budget_products.categories')...],
 *     'products'    => [
 *       'cars' => [
 *         'key', 'label', 'category', 'color', 'pending',
 *         'volume_only',     // bool — Empty containers etc. (price 0, vol tracked)
 *         'budget_price',
 *         'budget_qty'   => [1..12 monthly],
 *         'actual_qty'   => [1..12 monthly, null past today_month],
 *         'actual_price' => [1..12 monthly, null past today_month],
 *         // Convenience derived figures (precomputed server-side so the
 *         // Blade view doesn't have to recompute them):
 *         'ytd_actual_qty', 'ytd_budget_qty',
 *         'ytd_actual_revenue', 'ytd_budget_revenue',
 *         'volume_effect_xaf', 'price_effect_xaf', 'net_variance_xaf',
 *         'pct_of_plan',
 *       ],
 *       ...
 *     ],
 *     'totals' => [
 *       'tracked' => [
 *         'ytd_actual_revenue', 'ytd_budget_revenue',
 *         'volume_effect_xaf', 'price_effect_xaf', 'net_variance_xaf',
 *       ],
 *       'pending_ytd_budget_revenue' => 12_345_678.0,
 *     ],
 *   ]
 */
final class ProductRevenueService
{
    private BudgetService $budgets;
    private ProductVolumeService $volumes;

    public function __construct(BudgetService $budgets, ProductVolumeService $volumes)
    {
        $this->budgets = $budgets;
        $this->volumes = $volumes;
    }

    /**
     * Build the full analysis payload for a tenant + year. `$today` is
     * injectable for testing — defaults to "today".
     */
    public function analyze(Tenant $tenant, int $year, ?\DateTimeImmutable $today = null): array
    {
        $today      = $today ?? new \DateTimeImmutable('today');
        $todayMonth = $this->todayMonthForYear($year, $today);

        $registry  = (array) config('budget_products.products', []);
        $categoriesCfg = (array) config('budget_products.categories', []);
        $vatRate   = (float) config('budget_products.vat_rate', 0.0);

        $budgets = $this->budgets->loadProducts($tenant->id, $year);
        $volumes = $this->volumes->monthlyVolumes($year);

        $products = [];
        $tot = [
            'volume_effect_xaf'    => 0.0,
            'price_effect_xaf'     => 0.0,
            'ytd_actual_revenue'   => 0.0,
            'ytd_budget_revenue'   => 0.0,
        ];
        $pendingYtdBudgetRev = 0.0;

        foreach ($registry as $product) {
            $key       = (string) $product['key'];
            $pending   = (($product['actuals_source'] ?? 'pending') !== 'odoo');
            $budgetRow = $budgets[$key] ?? ['unit_price' => 0.0, 'volumes' => array_fill(1, 12, 0.0)];

            $budgetPrice = (float) $budgetRow['unit_price'];
            $budgetQty   = $this->vector($budgetRow['volumes']);

            // Actuals: from Odoo volumes for non-pending products; for the
            // demo / V1 we don't have a separate "actual_price" Odoo feed,
            // so achieved price defaults to budget price (no price-effect
            // until invoices are wired in). This keeps the variance math
            // correct: with actual_price == budget_price, price_effect = 0.
            $actualQty   = $pending
                ? array_fill(1, 12, null)
                : $this->actualVector($volumes[$key] ?? [], $todayMonth);
            $actualPrice = $pending
                ? array_fill(1, 12, null)
                : $this->priceVector($budgetPrice, $todayMonth);

            // YTD figures (months 1..todayMonth inclusive).
            $ytdActQty = 0; $ytdActRev = 0.0;
            $ytdBudQty = 0; $ytdBudRev = 0.0;
            for ($m = 1; $m <= $todayMonth; $m++) {
                $aq = $actualQty[$m] ?? null;
                $ap = $actualPrice[$m] ?? null;
                if ($aq !== null && $ap !== null) {
                    $ytdActQty += (int) $aq;
                    $ytdActRev += (float) $aq * (float) $ap;
                }
                $ytdBudQty += (int) $budgetQty[$m];
                $ytdBudRev += (float) $budgetQty[$m] * $budgetPrice;
            }

            // Price-volume-mix variance, scoped to YTD.
            // Volume effect = (act_qty − bud_qty) × bud_price
            // Price effect  = (act_price − bud_price) × act_qty
            // (Decomposes the total revenue variance cleanly.)
            if (!$pending) {
                $volEffect   = ((float) $ytdActQty - (float) $ytdBudQty) * $budgetPrice;
                $achievedAvg = $ytdActQty > 0 ? $ytdActRev / $ytdActQty : 0.0;
                $priceEffect = ($achievedAvg - $budgetPrice) * (float) $ytdActQty;
                $netVar      = $ytdActRev - $ytdBudRev;
                $pct         = $ytdBudQty > 0 ? 100.0 * $ytdActQty / max(1, $ytdBudQty) : null;

                $tot['ytd_actual_revenue']  += $ytdActRev;
                $tot['ytd_budget_revenue']  += $ytdBudRev;
                $tot['volume_effect_xaf']   += $volEffect;
                $tot['price_effect_xaf']    += $priceEffect;
            } else {
                $volEffect = 0.0; $priceEffect = 0.0; $netVar = null; $pct = null;
                $pendingYtdBudgetRev += $ytdBudRev;
            }

            $products[$key] = [
                'key'              => $key,
                'label'            => (string) $product['label'],
                'category'         => (string) $product['category'],
                'color'            => (string) ($product['color'] ?? '#0d6e6e'),
                'pending'          => $pending,
                'volume_only'      => (bool) ($product['volume_only'] ?? false),
                'budget_price'     => $budgetPrice,
                'budget_qty'       => array_values($budgetQty),     // 0-indexed for ECharts
                'actual_qty'       => array_values($actualQty),
                'actual_price'     => array_values($actualPrice),
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

        return [
            'year'        => $year,
            'today_month' => $todayMonth,
            'vat_rate'    => $vatRate,
            'categories'  => $categoriesCfg,
            'products'    => $products,
            'totals'      => [
                'tracked'                    => $tot,
                'pending_ytd_budget_revenue' => $pendingYtdBudgetRev,
            ],
        ];
    }

    /**
     * Resolve "today" to a month number 1..12 for the requested year.
     * For a future year the slider points to month 0 (no actuals yet);
     * for a past year the slider points to 12 (full year of actuals).
     * For the current year, today_month = today's month.
     */
    private function todayMonthForYear(int $year, \DateTimeImmutable $today): int
    {
        $currentYear = (int) $today->format('Y');
        if ($year < $currentYear) return 12;
        if ($year > $currentYear) return 0;
        return (int) $today->format('n');
    }

    /** Coerce stored monthly volumes into a 1..12-indexed float vector. */
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
                // Source uses 1..12 indices.
                $out[$m] = $src[$m] ?? null;
            }
        }
        return $out;
    }

    /**
     * Achieved unit price vector. V1: defaults to budget price for months
     * with actuals (no separate price feed yet). When a future iteration
     * adds invoice-derived price tracking, swap this method to read it.
     */
    private function priceVector(float $budgetPrice, int $todayMonth): array
    {
        $out = [];
        for ($m = 1; $m <= 12; $m++) {
            $out[$m] = $m > $todayMonth ? null : $budgetPrice;
        }
        return $out;
    }
}
