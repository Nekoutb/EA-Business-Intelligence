<?php
declare(strict_types=1);

namespace App\Services;

use App\Odoo\OdooClient;

/**
 * Accounts Receivable KPIs computed from Odoo posted customer invoices.
 *
 * KPIs (McKinsey-style credit/liquidity lens):
 *   1. total_ar                 Total outstanding receivables (headline)
 *   2. overdue_ar               Overdue receivables (absolute)
 *   3. overdue_rate_pct         Overdue / Total AR
 *   4. dso_days                 Days Sales Outstanding = (AR / Revenue YTD) * N_days
 *   5. avg_age_days             Weighted-average age of outstanding AR
 *   6. top5_concentration_pct   Share of AR held by the top-5 customers
 *
 * Also returns:
 *   - aging buckets (Current / 1-30 / 31-60 / 61-90 / 90+) with amount + count
 *   - top debtors list for the table on the Receivables page
 *
 * All values are in company currency. Requires the API key owner to have read
 * access on `account.move` (and `res.currency` for formatting).
 */
final class ReceivablesService
{
    private OdooClient $odoo;

    public function __construct(OdooClient $odoo)
    {
        $this->odoo = $odoo;
    }

    /**
     * @param float $revenueYtd    YTD revenue (from RevenueService) used for DSO.
     * @param int   $daysInYtd     Number of days in the YTD window (for DSO).
     * @param string $currency     ISO code for display.
     */
    public function analyze(float $revenueYtd, int $daysInYtd, string $currency, ?\DateTimeImmutable $today = null): array
    {
        $today = ($today ?? new \DateTimeImmutable('today'))->setTime(0, 0, 0);

        // Pull all open customer invoices/refunds. Unpaid = amount_residual > 0
        // (post-paid or reversed documents already drop to 0).
        $domain = [
            ['move_type', 'in', ['out_invoice', 'out_refund']],
            ['state', '=', 'posted'],
            ['amount_residual', '>', 0],
        ];
        $fields = [
            'id', 'name', 'partner_id',
            'invoice_date', 'invoice_date_due',
            'amount_total_signed', 'amount_residual_signed', 'amount_residual',
            'move_type',
        ];

        // P3 — read the open-AR set from the warehouse when it is populated
        // for this tenant. The rows are reshaped into the exact Odoo record
        // structure the rest of this method expects, so nothing downstream
        // changes. Falls back to Odoo whenever the flag is off, the tenant
        // has not been synced, or anything goes wrong.
        $records = null;
        if (config('bi.warehouse_receivables', false)) {
            try {
                $tenantId = (int) (optional(tenant())->id ?? 0);
                if ($tenantId > 0) {
                    $rows = \Illuminate\Support\Facades\DB::table('wh_fact_invoice as i')
                        ->leftJoin('wh_dim_partner as p', function ($j) {
                            $j->on('p.tenant_id', '=', 'i.tenant_id')
                              ->on('p.odoo_id', '=', 'i.partner_odoo_id');
                        })
                        ->where('i.tenant_id', $tenantId)
                        ->whereIn('i.move_type', ['out_invoice', 'out_refund'])
                        ->where('i.state', 'posted')
                        // Odoo filters on the UNSIGNED residual; the warehouse
                        // stores the signed one, so "still open" is |residual|>0
                        // — which also keeps open credit notes in scope.
                        ->whereRaw('ABS(i.amount_residual) > 0.005')
                        ->orderBy('i.invoice_date_due')
                        ->get([
                            'i.odoo_id', 'i.name', 'i.partner_odoo_id', 'p.name as partner_name',
                            'i.invoice_date', 'i.invoice_date_due', 'i.amount_total',
                            'i.amount_residual', 'i.move_type',
                        ]);

                    if ($rows->isNotEmpty()) {
                        $records = [];
                        foreach ($rows as $r) {
                            $records[] = [
                                'id'                     => (int) $r->odoo_id,
                                'name'                   => (string) ($r->name ?? ''),
                                'partner_id'             => $r->partner_odoo_id
                                    ? [(int) $r->partner_odoo_id, (string) ($r->partner_name ?? '')]
                                    : false,
                                'invoice_date'           => $r->invoice_date,
                                'invoice_date_due'       => $r->invoice_date_due,
                                'amount_total_signed'    => (float) $r->amount_total,
                                'amount_residual_signed' => (float) $r->amount_residual,
                                'amount_residual'        => abs((float) $r->amount_residual),
                                'move_type'              => (string) ($r->move_type ?? ''),
                            ];
                        }
                    }
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('receivables warehouse read failed, using Odoo', [
                    'error' => $e->getMessage(),
                ]);
                $records = null;
            }
        }

        // Odoo path — used unless the warehouse already supplied the set.
        if ($records === null) {
            $records = [];
            $offset = 0;
            $limit  = 1000;
            do {
                $batch = $this->odoo->searchRead('account.move', $domain, $fields, [
                    'offset' => $offset,
                    'limit'  => $limit,
                    'order'  => 'invoice_date_due asc',
                ]);
                foreach ($batch as $r) {
                    $records[] = $r;
                }
                $offset += $limit;
            } while (count($batch) === $limit);
        }

        // Ledger-based tenants (REVENUE_SOURCE=gl): sales reach the books
        // as journal entries, so there are no open invoices to age; rebuild
        // the open items from the receivable accounts instead.
        if ($records === [] && strtolower((string) config_env('REVENUE_SOURCE', 'invoice')) === 'gl') {
            try { $records = (new GLOpenItems($this->odoo))->fetch('receivable'); }
            catch (\Throwable $e) { \Illuminate\Support\Facades\Log::warning('GL open receivables failed', ['error' => $e->getMessage()]); }
        }

        $totalAr   = 0.0;
        $overdueAr = 0.0;
        $weightedAgeNum = 0.0;   // sum(age_days * residual)

        // Pre-build last 12 months trend buckets (inclusive of current month)
        $trendBuckets = [];
        for ($i = 11; $i >= 0; $i--) {
            $month = $today->modify("-{$i} months");
            $key   = $month->format('Y-m');
            $trendBuckets[$key] = [
                'label'   => $month->format('M Y'),
                'overdue' => 0.0,
                'count'   => 0,
            ];
        }

        // Aging buckets (amount + count).
        $buckets = [
            'current' => ['label' => 'Current (not due)', 'amount' => 0.0, 'count' => 0],
            '1_30'    => ['label' => '1-30 days',         'amount' => 0.0, 'count' => 0],
            '31_60'   => ['label' => '31-60 days',        'amount' => 0.0, 'count' => 0],
            '61_90'   => ['label' => '61-90 days',        'amount' => 0.0, 'count' => 0],
            '90_plus' => ['label' => '90+ days',          'amount' => 0.0, 'count' => 0],
        ];

        $partnerAr    = []; // partnerId => residual
        $partnerNames = [];
        $debtors      = []; // partnerId => aggregate row for the table

        foreach ($records as $r) {
            // Residual of an out_refund is naturally negative in amount_residual_signed,
            // which correctly REDUCES AR at the net level.
            $residual = (float) ($r['amount_residual_signed'] ?? $r['amount_residual'] ?? 0);
            if ($residual == 0.0) continue;

            $totalAr += $residual;

            // Age = days since invoice_date
            $invoiceDateStr = (string) ($r['invoice_date'] ?? '');
            $age = 0;
            if ($invoiceDateStr !== '') {
                try {
                    $invDate = new \DateTimeImmutable(substr($invoiceDateStr, 0, 10));
                    $age = max(0, (int) $today->diff($invDate)->days);
                } catch (\Throwable $e) { /* ignore */ }
            }
            $weightedAgeNum += $age * abs($residual);

            // Overdue = invoice_date_due in the past (strictly before today).
            $dueStr = (string) ($r['invoice_date_due'] ?? '');
            $daysOverdue = 0;
            if ($dueStr !== '') {
                try {
                    $dueDate = new \DateTimeImmutable(substr($dueStr, 0, 10));
                    if ($dueDate < $today) {
                        $daysOverdue = (int) $today->diff($dueDate)->days;
                    }
                } catch (\Throwable $e) { /* ignore */ }
            } else {
                // No due date -> treat as current.
                $daysOverdue = 0;
            }

            if ($daysOverdue > 0) {
                $overdueAr += $residual;
                if     ($daysOverdue <= 30) $bucket = '1_30';
                elseif ($daysOverdue <= 60) $bucket = '31_60';
                elseif ($daysOverdue <= 90) $bucket = '61_90';
                else                        $bucket = '90_plus';

                if (isset($dueDate)) {
                    $trendKey = $dueDate->format('Y-m');
                    if (isset($trendBuckets[$trendKey])) {
                        $trendBuckets[$trendKey]['overdue'] += $residual;
                        $trendBuckets[$trendKey]['count']++;
                    } else {
                        // Older than 12 months – accumulate into earliest bucket
                        $firstKey = array_key_first($trendBuckets);
                        $trendBuckets[$firstKey]['overdue'] += $residual;
                        $trendBuckets[$firstKey]['count']++;
                    }
                }
            } else {
                $bucket = 'current';
            }
            $buckets[$bucket]['amount'] += $residual;
            $buckets[$bucket]['count']  += 1;

            if (isset($r['partner_id']) && is_array($r['partner_id'])) {
                $pid   = (int) $r['partner_id'][0];
                $pname = (string) ($r['partner_id'][1] ?? '');
                $partnerAr[$pid] = ($partnerAr[$pid] ?? 0.0) + $residual;
                if (!isset($partnerNames[$pid])) {
                    $partnerNames[$pid] = $pname;
                }
                if (!isset($debtors[$pid])) {
                    $debtors[$pid] = [
                        'id' => $pid, 'name' => $pname,
                        'residual' => 0.0, 'overdue' => 0.0,
                        'invoices' => 0, 'max_days_overdue' => 0,
                    ];
                }
                $debtors[$pid]['residual'] += $residual;
                $debtors[$pid]['invoices']++;
                if ($daysOverdue > 0) {
                    $debtors[$pid]['overdue'] += $residual;
                    if ($daysOverdue > $debtors[$pid]['max_days_overdue']) {
                        $debtors[$pid]['max_days_overdue'] = $daysOverdue;
                    }
                }
            }
        }

        // ---- KPIs -------------------------------------------------------
        $overdueRate = $totalAr > 0 ? ($overdueAr / $totalAr) * 100.0 : 0.0;
        $avgAgeDays  = $totalAr > 0 ? ($weightedAgeNum / abs($totalAr)) : 0.0;

        // ── GL-backed DSO ──────────────────────────────────────────
        // OHADA standard: DSO = (class 41 balance / class 70 90d credit) × 90.
        //
        //   Numerator   = sum of net debit on every 41XX account today.
        //                 Catches every customer balance regardless of
        //                 origin (out_invoices, manual journals, advances,
        //                 reclassifications). Wider than the invoice-derived
        //                 totalAr by definition.
        //   Denominator = net credit movement on every 70XX account over
        //                 the trailing window (default 90 days). Reflects
        //                 commercial reality on a stable basis that doesn't
        //                 expand with the calendar.
        //   Window      = config('bi.dso_window_days', 90).
        //
        // If the GL service can't reach Odoo or the chart has no 41/70
        // accounts (non-OHADA chart), $dso falls back to the legacy
        // invoice-derived calculation so the page never 500s.
        $dso = null;
        $dsoBasis = [
            'method'        => 'legacy_invoice',
            'window_days'   => $daysInYtd,
            'numerator'     => $totalAr,
            'denominator'   => $revenueYtd,
            'numerator_label'   => 'AR (invoice-derived)',
            'denominator_label' => 'Revenue (YTD invoiced)',
        ];

        try {
            $glSvc = new GLAccountService($this->odoo);
            $windowDays = (int) (function_exists('config') ? config('bi.dso_window_days', 90) : 90);
            $windowDays = $windowDays > 0 ? $windowDays : 90;
            $windowFrom = $today->modify('-' . $windowDays . ' days');

            $arPrefix    = (string) (function_exists('config') ? config('bi.dso_ar_prefix', '41') : '41');
            $salesPrefix = (string) (function_exists('config') ? config('bi.dso_sales_prefix', '70') : '70');

            $arBalance     = $glSvc->balanceByPrefix($arPrefix, null, $today);
            $salesBalance  = $glSvc->creditByPrefix ($salesPrefix, $windowFrom, $today);

            if ($arBalance !== null && $salesBalance !== null && $salesBalance > 0) {
                $dso = ($arBalance / $salesBalance) * $windowDays;
                $dsoBasis = [
                    'method'        => 'gl_class_41_70',
                    'window_days'   => $windowDays,
                    'window_from'   => $windowFrom->format('Y-m-d'),
                    'window_to'     => $today->format('Y-m-d'),
                    'numerator'     => $arBalance,
                    'denominator'   => $salesBalance,
                    'numerator_label'   => "Class {$arPrefix} balance · today",
                    'denominator_label' => "Class {$salesPrefix} credit · trailing {$windowDays} d",
                ];
            } elseif ($arBalance !== null && ($salesBalance === null || $salesBalance <= 0)) {
                // GL reachable but sales side is dormant → DSO is undefined.
                $dso = null;
                $dsoBasis = [
                    'method'        => 'gl_dormant',
                    'window_days'   => $windowDays,
                    'numerator'     => $arBalance,
                    'denominator'   => $salesBalance ?? 0,
                    'numerator_label'   => "Class {$arPrefix} balance · today",
                    'denominator_label' => "Class {$salesPrefix} credit · trailing {$windowDays} d (no sales)",
                ];
            }
        } catch (\Throwable $e) {
            // Already null — fall through to legacy below.
        }

        // Legacy fallback: invoice-derived DSO. Only used when the GL
        // probe returned null/0 and we haven't already computed a
        // GL-backed value above.
        if ($dso === null && $dsoBasis['method'] === 'legacy_invoice' && $revenueYtd > 0) {
            $dso = ($totalAr / $revenueYtd) * $daysInYtd;
        }

        // Concentration of severely overdue balance: high values signal
        // imminent write-off / bad-debt risk.
        $over60 = $buckets['61_90']['amount'] + $buckets['90_plus']['amount'];
        $over90 = $buckets['90_plus']['amount'];
        $over60Pct = $totalAr > 0 ? ($over60 / $totalAr) * 100.0 : 0.0;
        $over90Pct = $totalAr > 0 ? ($over90 / $totalAr) * 100.0 : 0.0;

        // Top-5 customer concentration
        arsort($partnerAr);
        $top5 = array_slice($partnerAr, 0, 5, true);
        $top5Sum = array_sum($top5);
        $top5ConcentrationPct = $totalAr > 0 ? ($top5Sum / $totalAr) * 100.0 : 0.0;

        // Debtor table (top 10 by residual).
        uasort($debtors, fn($a, $b) => $b['residual'] <=> $a['residual']);
        $topDebtors = array_values(array_slice($debtors, 0, 10));
        foreach ($topDebtors as &$d) {
            $d['share_pct'] = $totalAr > 0 ? ($d['residual'] / $totalAr) * 100.0 : 0.0;
            $rating = $this->rateDebtor($d);
            $d['rating_label'] = $rating['label'];
            $d['rating_level'] = $rating['level'];
            $d['rating_reason'] = $rating['reason'];
        }
        unset($d);

        // ── Monthly AR Evolution (Jan → current month of selected year)
        // For each month-end snapshot M, classify each currently-open
        // invoice:
        //   - "open at M" iff invoice_date ≤ M (residual > 0 today is
        //     the proxy for "was unpaid then" — exact for invoices
        //     issued in the period; introduces minor lag for ones that
        //     were paid then re-credited).
        //   - aging at M = (M - due_date) days, only when due_date < M.
        // Sums total_at_m, over_60_at_m, over_90_at_m. Powers the
        // 3-line evolution chart on the Receivables page.
        $evolution = [];
        $year      = (int) $today->format('Y');
        $endMonNum = (int) $today->format('n');

        // Parse each invoice's dates ONCE (P1, Aug 2026). This loop used to
        // construct two DateTimeImmutable objects per invoice per month —
        // ~240,000 date parses on a tenant with 15k open invoices, and the
        // bulk of a 151-second page. The snapshot logic below is unchanged;
        // it just compares integer timestamps instead of re-parsing strings.
        $snap = [];
        foreach ($records as $r) {
            $residual = (float) ($r['amount_residual_signed'] ?? $r['amount_residual'] ?? 0);
            if ($residual == 0.0) continue;
            $invStr = (string) ($r['invoice_date'] ?? '');
            if ($invStr === '') continue;
            $invTs = strtotime(substr($invStr, 0, 10));
            if ($invTs === false) continue;
            $dueStr = (string) ($r['invoice_date_due'] ?? '');
            $dueTs  = $dueStr !== '' ? strtotime(substr($dueStr, 0, 10)) : false;
            $snap[] = [$residual, $invTs, $dueTs === false ? null : $dueTs];
        }

        for ($m = 1; $m <= $endMonNum; $m++) {
            $monthEnd = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $m));
            $monthEnd = $monthEnd->modify('last day of this month')->setTime(23, 59, 59);
            if ($monthEnd > $today) $monthEnd = $today;
            $endTs = $monthEnd->getTimestamp();

            $totalAtM = 0.0; $over60AtM = 0.0; $over90AtM = 0.0;
            foreach ($snap as [$residual, $invTs, $dueTs]) {
                if ($invTs > $endTs) continue;   // not yet issued at snapshot

                $totalAtM += $residual;

                if ($dueTs !== null && $dueTs < $endTs) {
                    // Whole days between due date and snapshot — identical to
                    // DateTimeImmutable::diff()->days for these date-only values.
                    $daysOverdueAtM = intdiv($endTs - $dueTs, 86400);
                    if ($daysOverdueAtM > 60) $over60AtM += $residual;
                    if ($daysOverdueAtM > 90) $over90AtM += $residual;
                }
            }

            $evolution[] = [
                'label'   => $monthEnd->format('M'),
                'ym'      => $monthEnd->format('Y-m'),
                'total'   => round($totalAtM, 2),
                'over_60' => round($over60AtM, 2),
                'over_90' => round($over90AtM, 2),
            ];
        }

        $summary = [
            'currency' => $currency,
            'as_of'    => $today->format('Y-m-d'),
            // Flattened KPI keys (legacy naming used by Blade + AI panel)
            'total_ar'   => $totalAr,
            'overdue_ar' => $overdueAr,
            'overdue_rate' => $overdueRate,
            'dso'        => $dso,
            'dso_basis'  => $dsoBasis,
            'avg_age'    => $avgAgeDays,
            'top5_pct'   => $top5ConcentrationPct,
            'pct_gt_60'  => $over60Pct,
            'pct_gt_90'  => $over90Pct,
            'over60_amount' => $over60,
            'over90_amount' => $over90,
            'aging'      => $buckets,
            'overdue_trend' => array_values($trendBuckets),
            'ar_evolution' => $evolution,
            'top_partners' => array_map(fn($d) => [
                'id'      => $d['id'],
                'name'    => $d['name'],
                'ar'      => $d['residual'],
                'overdue' => $d['overdue'],
                'share_pct' => $d['share_pct'],
                'invoices'  => $d['invoices'],
                'max_days_overdue' => $d['max_days_overdue'],
                'rating_label' => $d['rating_label'],
                'rating_level' => $d['rating_level'],
                'rating_reason'=> $d['rating_reason'],
            ], $topDebtors),
            'open_invoice_count' => count($records),
            'revenue_ytd'  => $revenueYtd,
            'days_in_ytd'  => $daysInYtd,
        ];

        // Preserve detailed structure for programmatic consumers (AI, APIs)
        $summary['kpis'] = [
            'total_ar'               => $totalAr,
            'overdue_ar'             => $overdueAr,
            'overdue_rate_pct'       => $overdueRate,
            'dso_days'               => $dso,
            'avg_age_days'           => $avgAgeDays,
            'top5_concentration_pct' => $top5ConcentrationPct,
            'over60_pct'             => $over60Pct,
            'over90_pct'             => $over90Pct,
            'over60_amount'          => $over60,
            'over90_amount'          => $over90,
        ];
        $summary['top_debtors'] = $topDebtors;

        return $summary;
    }

    private function rateDebtor(array $debtor): array
    {
        $maxDays = (int) ($debtor['max_days_overdue'] ?? 0);
        $residual = (float) ($debtor['residual'] ?? 0);
        $overdue = (float) ($debtor['overdue'] ?? 0);
        $overdueRatio = $residual > 0 ? ($overdue / $residual) : 0;

        if ($maxDays <= 15 && $overdueRatio <= 0.1) {
            return ['label' => 'Prime', 'level' => 'prime', 'reason' => 'Consistently pays within 15 days of due date'];
        }
        if ($maxDays <= 45 && $overdueRatio <= 0.35) {
            return ['label' => 'Watch', 'level' => 'watch', 'reason' => 'Occasional delays but generally reliable'];
        }
        if ($maxDays <= 90 && $overdueRatio <= 0.6) {
            return ['label' => 'Risk', 'level' => 'risk', 'reason' => 'Frequently late, monitor collections'];
        }
        return ['label' => 'Critical', 'level' => 'critical', 'reason' => 'Severe delinquency, immediate action required'];
    }

    /**
     * Running (cumulative, YTD) DSO by month — the receivables twin of
     * PayablesService::dpoRunningSeries. For each month m of the year:
     *
     *     DSO(m) = AR(end of m) ÷ cumulative-revenue(Jan→m) × $daysInYtd
     *
     * The GL month-end AR balance (class 41 ex 419) and cumulative class-7
     * (ex 77/79) revenue are each anchored to the headline figures
     * ($totalAr / $totalRevenueYtd), so the LATEST month equals the headline
     * cumulative DSO exactly while earlier months show the running build-up.
     *
     * @return array<int,array{label:string,dso:?float}>
     */
    public function dsoRunningSeries(float $totalAr, float $totalRevenueYtd, int $daysInYtd, ?int $year = null, ?\DateTimeImmutable $today = null): array
    {
        $today = $today ?? new \DateTimeImmutable('today');
        $year  = $year  ?? (int) $today->format('Y');
        try {
            $accounts = $this->odoo->searchRead('account.account', ['|', ['code', '=like', '41%'], ['code', '=like', '7%']], ['id', 'code'], []);
            $arIds = []; $revIds = [];
            foreach ($accounts as $a) {
                $id = (int) ($a['id'] ?? 0); $code = (string) ($a['code'] ?? '');
                if ($id <= 0 || $code === '') continue;
                // AR = client receivables (41x) EXCEPT 419 (client credit
                // balances / advances received, which net the wrong way).
                if (str_starts_with($code, '41') && !str_starts_with($code, '419')) { $arIds[] = $id; }
                // Revenue = operating income (class 7) EXCEPT financial income
                // (77) and reversals (79), mirroring the P&L revenue line.
                elseif (str_starts_with($code, '7') && !str_starts_with($code, '77') && !str_starts_with($code, '79')) { $revIds[] = $id; }
            }
            if (empty($revIds)) return [];

            $thisYear = (int) $today->format('Y');
            $curMonth = $year < $thisYear ? 12 : ($year > $thisYear ? 0 : (int) $today->format('n'));
            if ($curMonth < 1) return [];
            $endDate = $year < $thisYear ? sprintf('%04d-12-31', $year) : $today->format('Y-m-d');

            $arByMonth  = $this->monthlyBalanceByMonth($arIds, null, $endDate);                          // all-time → balance
            $revByMonth = $this->monthlyBalanceByMonth($revIds, sprintf('%04d-01-01', $year), $endDate); // this year

            // Cumulative AR balance at end of each month (AR is debit-normal,
            // so the GL balance is already a positive asset → no sign flip).
            ksort($arByMonth);
            $running = 0.0; $arCum = [];
            foreach ($arByMonth as $mk => $delta) { $running += $delta; $arCum[$mk] = $running; }
            $arAtEnd = function (string $mk) use ($arCum): float {
                $bal = 0.0;
                foreach ($arCum as $k => $v) { if ($k <= $mk) { $bal = $v; } else { break; } }
                return $bal;
            };

            $rawAr = []; $rawCumRev = []; $labels = []; $cumRev = 0.0;
            for ($m = 1; $m <= $curMonth; $m++) {
                $mk = sprintf('%04d-%02d', $year, $m);
                // Revenue (class 7) is credit-normal → GL balance negative; flip.
                $cumRev       += -1.0 * (float) ($revByMonth[$mk] ?? 0);
                $rawAr[$m]     = max(0.0, $arAtEnd($mk));
                $rawCumRev[$m] = $cumRev;
                $labels[$m]    = (new \DateTimeImmutable($mk . '-01'))->format('M y');
            }

            $revScale = ($rawCumRev[$curMonth] ?? 0) > 0 ? $totalRevenueYtd / $rawCumRev[$curMonth] : 1.0;
            $useShape = ($rawAr[$curMonth] ?? 0) > 0;
            $arScale  = $useShape ? $totalAr / $rawAr[$curMonth] : 1.0;

            $series = [];
            foreach ($labels as $m => $label) {
                $ar = $useShape ? ($rawAr[$m] * $arScale) : $totalAr;
                $cr = $rawCumRev[$m] * $revScale;
                $series[] = ['label' => $label, 'dso' => ($ar > 0 && $cr > 0) ? round($ar / $cr * $daysInYtd, 1) : null];
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
