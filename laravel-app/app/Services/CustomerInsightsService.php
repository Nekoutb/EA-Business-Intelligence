<?php
declare(strict_types=1);

namespace App\Services;

use App\Odoo\OdooClient;

final class CustomerInsightsService
{
    /**
     * Optional Odoo client — required only for the per-customer margin
     * computation that drives the profitability scatter chart. Older
     * call-sites that just want the revenue-based insights can still
     * instantiate the service without arguments.
     */
    public function __construct(private ?OdooClient $odoo = null) {}

    /**
     * Build customer-facing insights from the RevenueService analysis payload.
     *
     * Optional [from, to] window enables the per-customer margin
     * computation (Odoo round-trip). Without dates, the scatter
     * payload is omitted from the response and callers fall back to
     * the legacy revenue-only view.
     *
     * @param array $analysis Output from RevenueService::analyze()
     * @return array<string,mixed>
     */
    public function analyze(
        array $analysis,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null
    ): array {
        if ($analysis === []) {
            return [];
        }

        $current      = $analysis['current'] ?? [];
        $prev         = $analysis['previous'] ?? [];
        $revenueMap   = $this->normalizeMap($analysis['partner_revenue_cy'] ?? []);
        $revenueMapPy = $this->normalizeMap($analysis['partner_revenue_py'] ?? []);
        $orderMap     = $this->normalizeIntMap($analysis['partner_orders_cy'] ?? []);
        $monthMap     = $this->normalizeMonthMap($analysis['partner_months_cy'] ?? []);
        $nameMap      = $analysis['partner_names_cy'] ?? [];
        $currency     = (string) ($analysis['currency'] ?? 'USD');

        $totalRevenue = (float) ($current['total'] ?? 0.0);
        $customerCount = count($revenueMap);
        $customerCountPy = count($revenueMapPy);
        $avgRevenuePerCustomer = $customerCount > 0 ? $totalRevenue / $customerCount : null;

        $repeatCustomers = 0;
        $oneTimeCustomers = 0;
        $activeCustomers = 0;
        $inactiveCustomers = 0;
        $classification = [];
        foreach ($orderMap as $pid => $count) {
            $months = $monthMap[$pid] ?? [];
            $run = $this->longestConsecutiveMonths($months);
            $isActive = $run >= 3;
            if ($isActive) {
                $activeCustomers++;
            } else {
                $inactiveCustomers++;
            }
            if ($count <= 1) {
                $oneTimeCustomers++;
            } else {
                $repeatCustomers++;
            }
            $classification[$pid] = $isActive ? 'active' : 'one_time';
        }
        $repeatRate = $customerCount > 0 ? ($repeatCustomers / $customerCount) * 100.0 : null;

        $newCustomers  = count(array_diff_key($revenueMap, $revenueMapPy));
        $lostCustomers = count(array_diff_key($revenueMapPy, $revenueMap));

        $topCustomers   = $this->buildCustomerRows($this->topSlice($revenueMap, 5), $nameMap, $orderMap, $totalRevenue);
        $bottomCustomers = $this->buildCustomerRows($this->bottomSlice($revenueMap, 5), $nameMap, $orderMap, $totalRevenue);

        $avgTopCustomerSpend = $this->averageRevenue($topCustomers);
        $avgBottomCustomerSpend = $this->averageRevenue($bottomCustomers);

        $concentrationTop5  = $this->sharePct($revenueMap, 5, $totalRevenue);
        $concentrationTop10 = $this->sharePct($revenueMap, 10, $totalRevenue);

        [$accelerating, $declining] = $this->momentumBuckets($revenueMap, $revenueMapPy);
        $stable = max($customerCount - $accelerating - $declining, 0);

        $recommendations = $this->buildRecommendations([
            'growth_pct'    => $this->pct($customerCount, $customerCountPy),
            'repeat_rate'   => $repeatRate,
            'new_customers' => $newCustomers,
            'lost_customers'=> $lostCustomers,
            'top5_share'    => $concentrationTop5,
            'accelerating'  => $accelerating,
            'declining'     => $declining,
        ]);

        // ── Profitability scatter — replaces the top/bottom 5 tables ──────
        // For every customer in the YTD window, plot revenue (X) against
        // gross margin contribution (Y). Margin per customer is computed
        // line-by-line from product cost in Odoo (Option A per the user's
        // 2026-05-07 design spec): margin = subtotal − qty × standard_price.
        // Median split classifies into 3 visual bands: Stars (top-right),
        // Underperformers (bottom-left), Mixed (off-diagonal).
        $scatter = null;
        if ($this->odoo !== null && $from !== null && $to !== null && $revenueMap !== []) {
            $marginMap = $this->computeMarginPerCustomer($from, $to);
            $scatter   = $this->buildScatter($revenueMap, $marginMap, $orderMap, $nameMap, $totalRevenue);
        }

        return [
            'currency' => $currency,
            'summary' => [
                'customers'        => $customerCount,
                'customers_py'     => $customerCountPy,
                'growth_pct'       => $this->pct($customerCount, $customerCountPy),
                'avg_revenue'      => $avgRevenuePerCustomer,
                'repeat_customers' => $repeatCustomers,
                'one_time'         => $oneTimeCustomers,
                'repeat_rate'      => $repeatRate,
                'new_customers'    => $newCustomers,
                'lost_customers'   => $lostCustomers,
                'total_revenue'    => $totalRevenue,
            ],
            'profitability' => [
                'most_profitable'  => $topCustomers,
                'least_profitable' => $bottomCustomers,
            ],
            'scatter' => $scatter,
            'concentration' => [
                'top5_share_pct'  => $concentrationTop5,
                'top10_share_pct' => $concentrationTop10,
            ],
            'momentum' => [
                'accelerating' => $accelerating,
                'declining'   => $declining,
                'stable'      => $stable,
            ],
            'recommendations' => $recommendations,
            'classification'  => $classification,
            'active_summary' => [
                'active_customers'   => $activeCustomers,
                'inactive_customers' => $inactiveCustomers,
            ],
            'monthly_series' => $this->buildMonthlySeries($monthMap, $classification),
            'avg_spend' => [
                'top5'    => $avgTopCustomerSpend,
                'bottom5' => $avgBottomCustomerSpend,
            ],
        ];
    }

    /** @param array<int,float|string> $map */
    private function normalizeMap(array $map): array
    {
        $out = [];
        foreach ($map as $key => $value) {
            $val = (float) $value;
            if ($val === 0.0) {
                continue;
            }
            $out[(int) $key] = $val;
        }
        return $out;
    }

    /** @param array<int,int|float|string> $map */
    private function normalizeIntMap(array $map): array
    {
        $out = [];
        foreach ($map as $key => $value) {
            $out[(int) $key] = (int) round((float) $value);
        }
        return $out;
    }

    /** @param array<int,array<string,mixed>> $map */
    private function normalizeMonthMap(array $map): array
    {
        $out = [];
        foreach ($map as $pid => $months) {
            if (!is_array($months)) {
                continue;
            }
            foreach ($months as $month => $flag) {
                if (!is_string($month)) continue;
                $out[(int) $pid][substr($month, 0, 7)] = true;
            }
        }
        return $out;
    }

    /** @param array<string,bool> $months */
    private function longestConsecutiveMonths(array $months): int
    {
        if ($months === []) {
            return 0;
        }
        $keys = array_keys($months);
        sort($keys);
        $best = 1;
        $current = 1;
        for ($i = 1, $n = count($keys); $i < $n; $i++) {
            $prev = new \DateTimeImmutable($keys[$i - 1] . '-01');
            $cur  = new \DateTimeImmutable($keys[$i] . '-01');
            $diff = $prev->diff($cur);
            $monthsApart = ($diff->y * 12) + $diff->m;
            if ($monthsApart === 1) {
                $current++;
                $best = max($best, $current);
            } elseif ($monthsApart === 0) {
                continue;
            } else {
                $current = 1;
            }
        }
        return $best;
    }

    /**
     * @param array<int,float> $map
     * @return array<int,float>
     */
    private function topSlice(array $map, int $limit): array
    {
        arsort($map);
        return array_slice($map, 0, $limit, true);
    }

    /**
     * @param array<int,float> $map
     * @return array<int,float>
     */
    private function bottomSlice(array $map, int $limit): array
    {
        asort($map);
        // Remove zero / negative duplicates once more to focus on active accounts
        $filtered = array_filter($map, static fn($val) => (float) $val !== 0.0);
        return array_slice($filtered, 0, $limit, true);
    }

    /**
     * @param array<int,float> $slice
     * @return array<int,array{name:string,revenue:float,share:float,avg_order:float,orders:int}>
     */
    private function buildCustomerRows(array $slice, array $names, array $orderMap, float $totalRevenue): array
    {
        $rows = [];
        foreach ($slice as $pid => $revenue) {
            $orders = max($orderMap[$pid] ?? 0, 1);
            $rows[] = [
                'id'         => (int) $pid,
                'name'       => (string) ($names[$pid] ?? ('Customer #' . $pid)),
                'revenue'    => (float) $revenue,
                'share'      => $totalRevenue > 0 ? ((float) $revenue / $totalRevenue) * 100.0 : 0.0,
                'orders'     => $orders,
                'avg_order'  => (float) $revenue / $orders,
            ];
        }
        return $rows;
    }

    /** @param array<int,array{revenue:float}> $rows */
    private function averageRevenue(array $rows): ?float
    {
        if ($rows === []) {
            return null;
        }
        $sum = array_sum(array_column($rows, 'revenue'));
        return $sum / count($rows);
    }

    /**
     * Compute cumulative share of top-N customers as a percentage of total revenue.
     *
     * @param array<int,float> $map
     */
    private function sharePct(array $map, int $limit, float $total): ?float
    {
        if ($total === 0.0) {
            return null;
        }
        $slice = $this->topSlice($map, $limit);
        $sum = array_sum($slice);
        return ($sum / $total) * 100.0;
    }

    /**
     * @param array<int,float> $cy
     * @param array<int,float> $py
     * @return array{0:int,1:int} [accelerating, declining]
     */
    private function momentumBuckets(array $cy, array $py): array
    {
        $accelerating = 0;
        $declining    = 0;
        foreach ($cy as $pid => $rev) {
            $prev = $py[$pid] ?? null;
            if ($prev === null || $prev == 0.0) {
                continue;
            }
            $pct = $this->pct($rev, $prev);
            if ($pct !== null && $pct >= 15.0) {
                $accelerating++;
            } elseif ($pct !== null && $pct <= -15.0) {
                $declining++;
            }
        }
        return [$accelerating, $declining];
    }

    private function pct(?float $current, ?float $reference): ?float
    {
        if ($current === null || $reference === null) {
            return null;
        }
        if ((float) $reference === 0.0) {
            return (float) $current === 0.0 ? 0.0 : null;
        }
        return (($current - $reference) / abs($reference)) * 100.0;
    }

    /**
     * Build a lightweight set of operator-facing suggestions so the AI (and UI)
     * can surface obvious next steps without another round trip.
     *
     * @param array{growth_pct:?float,repeat_rate:?float,new_customers:int,lost_customers:int,top5_share:?float,accelerating:int,declining:int} $metrics
     * @return list<string>
     */
    private function buildRecommendations(array $metrics): array
    {
        $tips = [];
        if ($metrics['growth_pct'] !== null) {
            if ($metrics['growth_pct'] < 0) {
                $tips[] = 'Customer base is contracting — run win-back campaigns for lost accounts and inspect pricing friction.';
            } elseif ($metrics['growth_pct'] >= 5) {
                $tips[] = 'Customer count is growing; allocate budget to onboarding journeys to convert newcomers into repeat buyers.';
            }
        }

        if ($metrics['repeat_rate'] !== null) {
            if ($metrics['repeat_rate'] < 40) {
                $tips[] = 'Repeat rate is under 40% — introduce loyalty perks or CX outreach to move one-timers into subscription-like behaviour.';
            } else {
                $tips[] = 'Healthy repeat mix — double-down on referral or membership programs to monetise advocacy.';
            }
        }

        if ($metrics['new_customers'] < $metrics['lost_customers']) {
            $tips[] = 'Lost customers exceed new wins; triage churned accounts for root causes (pricing, service levels, product gaps).';
        } elseif ($metrics['new_customers'] > $metrics['lost_customers'] * 1.5) {
            $tips[] = 'Pipeline is adding far more new customers than it loses — ensure onboarding capacity keeps up to protect NPS.';
        }

        if ($metrics['top5_share'] !== null && $metrics['top5_share'] > 45) {
            $tips[] = 'Revenue is highly concentrated in the top 5 accounts — set diversification targets and executive sponsors per whale.';
        }

        if ($metrics['declining'] > $metrics['accelerating']) {
            $tips[] = 'More accounts are shrinking than growing — deploy account managers to recover wallet share before churn hits revenue.';
        } elseif ($metrics['accelerating'] >= $metrics['declining'] * 2 && $metrics['accelerating'] > 0) {
            $tips[] = 'Several accounts are accelerating rapidly; explore upsell bundles while momentum is strong.';
        }

        return $tips === [] ? ['Customer mix stable — keep monitoring weekly for shifts.'] : array_values(array_unique($tips));
    }

    /**
     * Build month-by-month counts for active vs one-time customers based on classifications.
     *
     * @param array<int,array<string,bool>> $monthMap
     * @param array<int,string> $classification
     * @return array<int,array{label:string,active:int,one_time:int}>
     */
    private function buildMonthlySeries(array $monthMap, array $classification): array
    {
        $counts = [];
        foreach ($monthMap as $pid => $months) {
            $class = $classification[$pid] ?? 'one_time';
            foreach ($months as $month => $flag) {
                if (!is_string($month)) continue;
                $counts[$month][$class] = ($counts[$month][$class] ?? 0) + 1;
            }
        }
        ksort($counts);
        $series = [];
        foreach ($counts as $month => $bucket) {
            $series[] = [
                'label'  => $month,
                'active' => (int) ($bucket['active'] ?? 0),
                'one_time' => (int) ($bucket['one_time'] ?? 0),
            ];
        }
        return $series;
    }

    /**
     * Per-customer gross-margin contribution for posted out_invoices in
     * the [from, to] window (Option A per the user's 2026-05-07 spec):
     *
     *   margin_per_line = price_subtotal − (quantity × product.standard_price)
     *
     * Aggregated by partner_id. Lines with no `product_id` (manual rows
     * — discounts, surcharges, freight) are skipped because there's no
     * cost reference. Products with `standard_price = 0` (typically
     * services or products where cost wasn't captured in Odoo) yield
     * margin == revenue for that line — accurate for true zero-cost
     * services, slightly overstated where cost was simply unrecorded.
     * The methodology footer surfaces this assumption to the auditor.
     *
     * Three Odoo round-trips total:
     *   1) account.move          — sales invoices in window (id, partner)
     *   2) account.move.line     — product lines on those moves
     *   3) product.product       — standard_price for the products seen
     *
     * Returns [partner_id => margin_total]. Returns empty array on any
     * Odoo failure so the caller can render a degraded scatter (X-axis
     * only) rather than crash the whole page.
     *
     * @return array<int,float>
     */
    private function computeMarginPerCustomer(
        \DateTimeImmutable $from,
        \DateTimeImmutable $to
    ): array {
        if ($this->odoo === null) return [];

        try {
            // 1. Sales invoices in window
            $moves = $this->odoo->searchRead('account.move', [
                ['move_type', '=',  'out_invoice'],
                ['state',     '=',  'posted'],
                ['invoice_date', '>=', $from->format('Y-m-d')],
                ['invoice_date', '<=', $to->format('Y-m-d')],
            ], ['id', 'partner_id'], []);
            if (empty($moves)) return [];

            $movePartner = [];
            foreach ($moves as $m) {
                $pid = is_array($m['partner_id'] ?? null) ? (int) $m['partner_id'][0] : null;
                if ($pid !== null) {
                    $movePartner[(int) $m['id']] = $pid;
                }
            }
            if (empty($movePartner)) return [];

            // 2. Product lines on those invoices
            $lines = $this->odoo->searchRead('account.move.line', [
                ['move_id',    'in', array_keys($movePartner)],
                ['product_id', '!=', false],
                ['quantity',   '>',  0],
            ], ['move_id', 'product_id', 'quantity', 'price_subtotal'], []);
            if (empty($lines)) return [];

            // 3. Product costs (standard_price)
            $productIds = [];
            foreach ($lines as $l) {
                $pid = is_array($l['product_id'] ?? null) ? (int) $l['product_id'][0] : null;
                if ($pid !== null) $productIds[$pid] = true;
            }
            if (empty($productIds)) return [];

            $products = $this->odoo->searchRead('product.product',
                [['id', 'in', array_keys($productIds)]],
                ['id', 'standard_price'], []
            );
            $cost = [];
            foreach ($products as $p) {
                $cost[(int) $p['id']] = (float) ($p['standard_price'] ?? 0);
            }

            // 4. Aggregate margin per partner
            $margin = [];
            foreach ($lines as $l) {
                $moveId = is_array($l['move_id'] ?? null)    ? (int) $l['move_id'][0]    : null;
                $prodId = is_array($l['product_id'] ?? null) ? (int) $l['product_id'][0] : null;
                if (!$moveId || !$prodId) continue;
                $partner = $movePartner[$moveId] ?? null;
                if (!$partner) continue;
                $qty      = (float) ($l['quantity'] ?? 0);
                $subtotal = (float) ($l['price_subtotal'] ?? 0);
                $unitCost = $cost[$prodId] ?? 0.0;
                $lineMargin = $subtotal - ($qty * $unitCost);
                $margin[$partner] = ($margin[$partner] ?? 0.0) + $lineMargin;
            }
            return $margin;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Build the scatter chart payload from per-customer revenue + margin
     * maps. Median split classifies each point into one of three visual
     * bands:
     *
     *   • GREEN — Stars: above-median revenue AND above-median margin
     *   • RED   — Underperformers: below-median revenue AND below-median margin
     *   • ORANGE — Mixed: high revenue + low margin (or vice versa)
     *
     * Median split is the most readable for a non-quant auditor — every
     * point is in exactly one bucket and the explanation fits in one
     * sentence ("above-median on both axes = star"). Percentile bands
     * (25/75) were considered but rejected: a 50-customer dataset would
     * leave only ~12 stars, leaking customers into "mixed" who really
     * are stars by any reasonable definition.
     *
     * Customers with zero revenue are filtered out (they shouldn't be
     * on the chart at all). Customers with revenue but no margin data
     * (i.e. partner_id never appeared in the line-level margin pull —
     * usually because their invoices had no product lines, just manual
     * rows) get margin = 0 and land in the bottom-left red band, which
     * is the correct "no visibility" signal for the auditor.
     *
     * @param array<int,float>      $revenueMap
     * @param array<int,float>      $marginMap
     * @param array<int,int>        $orderMap
     * @param array<int,string>     $nameMap
     * @return array{
     *   points: array<int,array<string,mixed>>,
     *   thresholds: array<string,float>,
     *   summary: array<string,array<string,float|int>>,
     *   total_revenue: float, total_margin: float
     * }
     */
    private function buildScatter(
        array $revenueMap,
        array $marginMap,
        array $orderMap,
        array $nameMap,
        float $totalRevenue
    ): array {
        $points = [];
        $totalMargin = 0.0;

        foreach ($revenueMap as $pid => $revenue) {
            $rev = (float) $revenue;
            if ($rev <= 0) continue;
            $margin  = (float) ($marginMap[$pid] ?? 0.0);
            $totalMargin += $margin;
            $points[] = [
                'id'         => (int) $pid,
                'name'       => (string) ($nameMap[$pid] ?? ('Customer #' . $pid)),
                'revenue'    => round($rev, 2),
                'margin'     => round($margin, 2),
                'margin_pct' => $rev > 0 ? round($margin / $rev * 100.0, 1) : 0.0,
                'orders'     => (int) ($orderMap[$pid] ?? 0),
                'share_revenue' => $totalRevenue > 0 ? round($rev / $totalRevenue * 100.0, 1) : 0.0,
                // share_margin computed in second pass (we need totalMargin first)
            ];
        }
        if ($points === []) {
            return [
                'points'        => [],
                'thresholds'    => ['revenue_median' => 0, 'margin_median' => 0],
                'summary'       => $this->emptyScatterSummary(),
                'total_revenue' => $totalRevenue,
                'total_margin'  => 0.0,
            ];
        }

        // Compute medians for the quadrant split
        $revenueValues = array_column($points, 'revenue');
        $marginValues  = array_column($points, 'margin');
        $revenueMedian = $this->median($revenueValues);
        $marginMedian  = $this->median($marginValues);

        // Classify + finalize share_margin
        $summary = [
            'green'  => ['count' => 0, 'revenue' => 0.0, 'margin' => 0.0],
            'orange' => ['count' => 0, 'revenue' => 0.0, 'margin' => 0.0],
            'red'    => ['count' => 0, 'revenue' => 0.0, 'margin' => 0.0],
        ];
        foreach ($points as &$p) {
            $hiRev = $p['revenue'] >= $revenueMedian;
            $hiMar = $p['margin']  >= $marginMedian;
            if ($hiRev && $hiMar)         $band = 'green';
            elseif (!$hiRev && !$hiMar)   $band = 'red';
            else                          $band = 'orange';
            $p['band']         = $band;
            $p['share_margin'] = $totalMargin > 0
                ? round($p['margin'] / $totalMargin * 100.0, 1)
                : 0.0;
            $summary[$band]['count']++;
            $summary[$band]['revenue'] += $p['revenue'];
            $summary[$band]['margin']  += $p['margin'];
        }
        unset($p);

        // Sort points by revenue desc (so the top-right green dots draw
        // last and sit on top of the orange ones)
        usort($points, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);

        // Finalize summary share %
        foreach ($summary as $band => &$row) {
            $row['share_revenue_pct'] = $totalRevenue > 0
                ? round($row['revenue'] / $totalRevenue * 100.0, 1)
                : 0.0;
            $row['share_margin_pct']  = $totalMargin > 0
                ? round($row['margin'] / $totalMargin * 100.0, 1)
                : 0.0;
            $row['revenue'] = round($row['revenue'], 2);
            $row['margin']  = round($row['margin'], 2);
        }
        unset($row);

        return [
            'points'        => $points,
            'thresholds'    => [
                'revenue_median' => round($revenueMedian, 2),
                'margin_median'  => round($marginMedian, 2),
            ],
            'summary'       => $summary,
            'total_revenue' => round($totalRevenue, 2),
            'total_margin'  => round($totalMargin, 2),
        ];
    }

    /** @param array<int,float> $values */
    private function median(array $values): float
    {
        $n = count($values);
        if ($n === 0) return 0.0;
        sort($values);
        $mid = (int) floor($n / 2);
        if ($n % 2 === 1) return (float) $values[$mid];
        return ((float) $values[$mid - 1] + (float) $values[$mid]) / 2.0;
    }

    private function emptyScatterSummary(): array
    {
        return [
            'green'  => ['count' => 0, 'revenue' => 0.0, 'margin' => 0.0, 'share_revenue_pct' => 0.0, 'share_margin_pct' => 0.0],
            'orange' => ['count' => 0, 'revenue' => 0.0, 'margin' => 0.0, 'share_revenue_pct' => 0.0, 'share_margin_pct' => 0.0],
            'red'    => ['count' => 0, 'revenue' => 0.0, 'margin' => 0.0, 'share_revenue_pct' => 0.0, 'share_margin_pct' => 0.0],
        ];
    }
}
