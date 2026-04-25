<?php
declare(strict_types=1);

namespace App\Services;

final class CustomerInsightsService
{
    /**
     * Build customer-facing insights from the RevenueService analysis payload.
     *
     * @param array $analysis Output from RevenueService::analyze()
     * @return array<string,mixed>
     */
    public function analyze(array $analysis): array
    {
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
}
