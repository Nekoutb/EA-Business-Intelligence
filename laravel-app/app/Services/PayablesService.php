<?php
declare(strict_types=1);

namespace App\Services;

use App\Odoo\OdooClient;

/**
 * Accounts Payable KPIs computed from Odoo posted vendor bills (mirror of
 * ReceivablesService). All amounts are shown as positive numbers.
 *
 * KPIs:
 *   1. total_ap                 Total outstanding payables
 *   2. overdue_ap               Amount past due date
 *   3. overdue_rate_pct         Overdue / Total AP
 *   4. dpo_days                 Days Payable Outstanding = (AP / Total expenses) * N
 *   5. avg_age_days             Weighted-average age
 *   6. top5_concentration_pct   Share of AP held by top-5 suppliers
 *
 * Plus severe-delinquency ratios (>60d, >90d), aging buckets, top suppliers.
 * Requires read access on `account.move` for vendor bills.
 */
final class PayablesService
{
    private OdooClient $odoo;

    public function __construct(OdooClient $odoo)
    {
        $this->odoo = $odoo;
    }

    /**
     * @param float  $totalExpensesYtd YTD total expenses (COGS + OpEx + D&A) for DPO.
     * @param int    $daysInYtd        Number of days in the window.
     * @param string $currency         ISO code for display.
     */
    public function analyze(float $totalExpensesYtd, int $daysInYtd, string $currency, ?\DateTimeImmutable $today = null): array
    {
        $today = ($today ?? new \DateTimeImmutable('today'))->setTime(0, 0, 0);

        $domain = [
            ['move_type', 'in', ['in_invoice', 'in_refund']],
            ['state', '=', 'posted'],
            ['amount_residual', '>', 0],
        ];
        $fields = [
            'id', 'name', 'partner_id',
            'invoice_date', 'invoice_date_due',
            'amount_total_signed', 'amount_residual_signed', 'amount_residual',
            'move_type',
        ];

        $records = [];
        $offset  = 0;
        $limit   = 1000;
        do {
            $batch = $this->odoo->searchRead('account.move', $domain, $fields, [
                'offset' => $offset,
                'limit'  => $limit,
                'order'  => 'invoice_date_due asc',
            ]);
            foreach ($batch as $r) $records[] = $r;
            $offset += $limit;
        } while (count($batch) === $limit);

        $totalAp   = 0.0;
        $overdueAp = 0.0;
        $weightedAgeNum = 0.0;

        $buckets = [
            'current' => ['label' => 'Current (not due)', 'amount' => 0.0, 'count' => 0],
            '1_30'    => ['label' => '1-30 days',         'amount' => 0.0, 'count' => 0],
            '31_60'   => ['label' => '31-60 days',        'amount' => 0.0, 'count' => 0],
            '61_90'   => ['label' => '61-90 days',        'amount' => 0.0, 'count' => 0],
            '90_plus' => ['label' => '90+ days',          'amount' => 0.0, 'count' => 0],
        ];

        $partnerAp = [];
        $partnerNames = [];
        $creditors    = [];

        foreach ($records as $r) {
            // amount_residual is unsigned positive; use it as the AP exposure.
            // in_refund lowers our liability — negate those to offset correctly.
            $raw = (float) ($r['amount_residual'] ?? 0);
            $mtype = (string) ($r['move_type'] ?? 'in_invoice');
            $residual = $mtype === 'in_refund' ? -$raw : $raw;
            if ($residual == 0.0) continue;

            $totalAp += $residual;

            $invoiceDateStr = (string) ($r['invoice_date'] ?? '');
            $age = 0;
            if ($invoiceDateStr !== '') {
                try {
                    $d = new \DateTimeImmutable($invoiceDateStr);
                    $age = max(0, (int) $d->diff($today)->days);
                } catch (\Throwable $e) { /* skip */ }
            }
            $weightedAgeNum += $age * $residual;

            // Overdue logic by due date (vs today)
            $dueStr  = (string) ($r['invoice_date_due'] ?? '');
            $daysPast = null;
            if ($dueStr !== '') {
                try {
                    $due = new \DateTimeImmutable($dueStr);
                    $diff = (int) $due->diff($today)->days;
                    $daysPast = $today > $due ? $diff : -$diff;
                } catch (\Throwable $e) { /* skip */ }
            }

            if ($daysPast === null || $daysPast <= 0) {
                $buckets['current']['amount'] += $residual; $buckets['current']['count']++;
            } else {
                $overdueAp += $residual;
                if ($daysPast <= 30)      { $buckets['1_30']['amount']    += $residual; $buckets['1_30']['count']++; }
                elseif ($daysPast <= 60)  { $buckets['31_60']['amount']   += $residual; $buckets['31_60']['count']++; }
                elseif ($daysPast <= 90)  { $buckets['61_90']['amount']   += $residual; $buckets['61_90']['count']++; }
                else                      { $buckets['90_plus']['amount'] += $residual; $buckets['90_plus']['count']++; }
            }

            // Partner rollup
            if (isset($r['partner_id']) && is_array($r['partner_id']) && isset($r['partner_id'][0])) {
                $pid = (int) $r['partner_id'][0];
                $pname = (string) ($r['partner_id'][1] ?? ('Partner #' . $pid));
                $partnerAp[$pid] = ($partnerAp[$pid] ?? 0.0) + $residual;
                $partnerNames[$pid] = $pname;

                if (!isset($creditors[$pid])) {
                    $creditors[$pid] = [
                        'partner_id' => $pid,
                        'name'       => $pname,
                        'residual'   => 0.0,
                        'overdue'    => 0.0,
                        'invoices'   => 0,
                        'max_days_overdue' => 0,
                    ];
                }
                $creditors[$pid]['residual'] += $residual;
                $creditors[$pid]['invoices']++;
                if ($daysPast !== null && $daysPast > 0) {
                    $creditors[$pid]['overdue'] += $residual;
                    if ($daysPast > $creditors[$pid]['max_days_overdue']) {
                        $creditors[$pid]['max_days_overdue'] = $daysPast;
                    }
                }
            }
        }

        $overdueRate = $totalAp > 0 ? ($overdueAp / $totalAp) * 100.0 : 0.0;
        $avgAgeDays  = $totalAp > 0 ? ($weightedAgeNum / abs($totalAp)) : 0.0;
        // DPO = (AP / total expenses) × days
        $dpo = ($totalExpensesYtd > 0) ? ($totalAp / $totalExpensesYtd) * $daysInYtd : null;

        $over60 = $buckets['61_90']['amount'] + $buckets['90_plus']['amount'];
        $over90 = $buckets['90_plus']['amount'];
        $over60Pct = $totalAp > 0 ? ($over60 / $totalAp) * 100.0 : 0.0;
        $over90Pct = $totalAp > 0 ? ($over90 / $totalAp) * 100.0 : 0.0;

        arsort($partnerAp);
        $top5 = array_slice($partnerAp, 0, 5, true);
        $top5Sum = array_sum($top5);
        $top5ConcentrationPct = $totalAp > 0 ? ($top5Sum / $totalAp) * 100.0 : 0.0;

        uasort($creditors, fn($a, $b) => $b['residual'] <=> $a['residual']);
        $topCreditors = array_values(array_slice($creditors, 0, 30));

        return [
            'currency' => $currency,
            'as_of'    => $today->format('Y-m-d'),
            'kpis' => [
                'total_ap'               => $totalAp,
                'overdue_ap'             => $overdueAp,
                'overdue_rate_pct'       => $overdueRate,
                'dpo_days'               => $dpo,
                'avg_age_days'           => $avgAgeDays,
                'top5_concentration_pct' => $top5ConcentrationPct,
                'over60_pct'             => $over60Pct,
                'over90_pct'             => $over90Pct,
                'over60_amount'          => $over60,
                'over90_amount'          => $over90,
            ],
            'aging'         => $buckets,
            'top_creditors' => $topCreditors,
            'open_bill_count' => count($records),
            'total_expenses_ytd' => $totalExpensesYtd,
            'days_in_ytd'   => $daysInYtd,
        ];
    }

    /**
     * Running (cumulative, YTD) DPO by month — the version that reconciles to
     * the headline dpo_days. For each month m of the year:
     *
     *     DPO(m) = AP(end of m) ÷ cumulative-expenses(Jan→m) × $daysInYtd
     *
     * The GL month-end AP balance (class 40) and the cumulative class-6 (ex 69)
     * expense are each anchored to the headline figures ($totalAp /
     * $totalExpensesYtd), so the LATEST month equals the headline DPO exactly
     * while the earlier months show how that running figure built up.
     *
     * Returns [] (chart hides) on any failure or missing data.
     *
     * @return array<int,array{label:string,dpo:?float}>
     */
    public function dpoRunningSeries(float $totalAp, float $totalExpensesYtd, int $daysInYtd, ?int $year = null, ?\DateTimeImmutable $today = null): array
    {
        $today = $today ?? new \DateTimeImmutable('today');
        $year  = $year  ?? (int) $today->format('Y');
        try {
            $accounts = $this->odoo->searchRead('account.account', ['|', ['code', '=like', '40%'], ['code', '=like', '6%']], ['id', 'code'], []);
            $apIds = []; $expIds = [];
            foreach ($accounts as $a) {
                $id = (int) ($a['id'] ?? 0); $code = (string) ($a['code'] ?? '');
                if ($id <= 0 || $code === '') continue;
                // AP = suppliers (401) + invoices-not-received (408). Exclude
                // 409 (supplier advances, debit) which would net the balance
                // the wrong way.
                if (str_starts_with($code, '401') || str_starts_with($code, '408')) { $apIds[] = $id; }
                elseif (str_starts_with($code, '6') && !str_starts_with($code, '69')) { $expIds[] = $id; }
            }
            if (empty($expIds)) return [];

            $thisYear = (int) $today->format('Y');
            $curMonth = $year < $thisYear ? 12 : ($year > $thisYear ? 0 : (int) $today->format('n'));
            if ($curMonth < 1) return [];
            $endDate = $year < $thisYear ? sprintf('%04d-12-31', $year) : $today->format('Y-m-d');

            $apByMonth  = $this->monthlyBalanceByMonth($apIds, null, $endDate);                       // all-time → balance
            $expByMonth = $this->monthlyBalanceByMonth($expIds, sprintf('%04d-01-01', $year), $endDate); // this year

            // Cumulative AP balance at the end of each month (AP is credit-normal,
            // so a negative GL balance = money owed → flip the sign).
            ksort($apByMonth);
            $running = 0.0; $apCum = [];
            foreach ($apByMonth as $mk => $delta) { $running += $delta; $apCum[$mk] = $running; }
            $apAtEnd = function (string $mk) use ($apCum): float {
                $bal = 0.0;
                foreach ($apCum as $k => $v) { if ($k <= $mk) { $bal = $v; } else { break; } }
                return $bal;
            };

            $rawAp = []; $rawCumExp = []; $labels = []; $cumExp = 0.0;
            for ($m = 1; $m <= $curMonth; $m++) {
                $mk = sprintf('%04d-%02d', $year, $m);
                $cumExp        += (float) ($expByMonth[$mk] ?? 0);   // debit-normal → positive
                $rawAp[$m]      = max(0.0, -1.0 * $apAtEnd($mk));
                $rawCumExp[$m]  = $cumExp;
                $labels[$m]     = (new \DateTimeImmutable($mk . '-01'))->format('M y');
            }

            // Anchor the latest month to the headline so the line reconciles
            // exactly to dpo_days. Cumulative expense is always anchored. For
            // the AP numerator, use the month-end balance SHAPE scaled to the
            // headline total_ap when it's usable; otherwise fall back to a flat
            // current-AP numerator (still reconciles, still builds up).
            $expScale = ($rawCumExp[$curMonth] ?? 0) > 0 ? $totalExpensesYtd / $rawCumExp[$curMonth] : 1.0;
            $useShape = ($rawAp[$curMonth] ?? 0) > 0;
            $apScale  = $useShape ? $totalAp / $rawAp[$curMonth] : 1.0;

            $series = [];
            foreach ($labels as $m => $label) {
                $ap = $useShape ? ($rawAp[$m] * $apScale) : $totalAp;
                $ce = $rawCumExp[$m] * $expScale;
                $series[] = ['label' => $label, 'dpo' => ($ap > 0 && $ce > 0) ? round($ap / $ce * $daysInYtd, 1) : null];
            }
            return $series;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** read_group balance:sum by month → [Y-m => balance]; uses __range['date']. */
    private function monthlyBalanceByMonth(array $accountIds, ?string $from, string $to): array
    {
        if (empty($accountIds)) return [];
        $domain = [['parent_state', '=', 'posted'], ['date', '<=', $to], ['account_id', 'in', $accountIds]];
        if ($from !== null) $domain[] = ['date', '>=', $from];
        $rows = $this->odoo->callModel('account.move.line', 'read_group', [
            'domain' => $domain, 'fields' => ['balance:sum'], 'groupby' => ['date:month'], 'lazy' => false,
        ]);
        $out = [];
        foreach ((array) $rows as $r) {
            $mk = null;
            foreach (['date', 'date:month'] as $rk) {
                if (isset($r['__range'][$rk]['from'])) { $mk = substr((string) $r['__range'][$rk]['from'], 0, 7); break; }
            }
            if ($mk !== null) $out[$mk] = (float) ($r['balance'] ?? 0);
        }
        return $out;
    }
}
