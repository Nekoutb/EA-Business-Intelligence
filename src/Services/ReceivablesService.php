<?php
declare(strict_types=1);

namespace EA\BI\Services;

use EA\BI\Odoo\OdooClient;

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

        $totalAr   = 0.0;
        $overdueAr = 0.0;
        $weightedAgeNum = 0.0;   // sum(age_days * residual)

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
        // DSO = (AR / Revenue) * days_in_window. Defined only with positive revenue.
        $dso = ($revenueYtd > 0) ? ($totalAr / $revenueYtd) * $daysInYtd : null;

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
        }
        unset($d);

        return [
            'currency' => $currency,
            'as_of'    => $today->format('Y-m-d'),
            'kpis' => [
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
            ],
            'aging'        => $buckets,
            'top_debtors'  => $topDebtors,
            'open_invoice_count' => count($records),
            'revenue_ytd'  => $revenueYtd,
            'days_in_ytd'  => $daysInYtd,
        ];
    }
}
