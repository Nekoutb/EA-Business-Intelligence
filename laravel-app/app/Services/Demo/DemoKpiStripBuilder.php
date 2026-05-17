<?php
declare(strict_types=1);

namespace App\Services\Demo;

/**
 * Per-module KPI strip builder for the demo sandbox.
 *
 * One method per module — each returns an array of KPI cards in the
 * shape the shared `_kpi-strip.blade.php` partial expects:
 *
 *   [
 *     ['label' => '...', 'value_fmt' => '...', 'status' => 'green|amber|red|na',
 *      'subtitle' => '...', 'threshold' => '...'],
 *     ...
 *   ]
 *
 * Status logic uses the same conventions as BusinessInsightsScorer for
 * the dashboard KPIs (Revenue YoY, GM, EBITDA, DSO, Overdue) and
 * applies module-specific thresholds for the rest. All thresholds are
 * documented in the `threshold` field so visitors see what 'good' looks
 * like for each metric.
 *
 * Pattern: every module gets 4 KPIs unless there's a strong reason for
 * 3 or 5. Keeps the strip visually consistent across the suite.
 */
final class DemoKpiStripBuilder
{
    // ── Banding helpers (same logic as BusinessInsightsScorer) ────────

    private static function higherBetter($value, float $greenAt, float $redAt): string
    {
        if (!is_numeric($value)) return 'na';
        $v = (float) $value;
        if ($v >= $greenAt) return 'green';
        if ($v >= $redAt)   return 'amber';
        return 'red';
    }

    private static function lowerBetter($value, float $greenAt, float $amberCap): string
    {
        if (!is_numeric($value)) return 'na';
        $v = (float) $value;
        if ($v <= $greenAt)  return 'green';
        if ($v <= $amberCap) return 'amber';
        return 'red';
    }

    private static function amount(float $v): string { return number_format($v, 0); }
    private static function pct(float $v): string { return number_format($v, 1) . '%'; }
    private static function pctSigned(float $v): string { return ($v >= 0 ? '+' : '') . number_format($v, 1) . '%'; }

    // ── Business Intelligence (10 modules) ────────────────────────

    public static function forRevenue(array $data): array
    {
        $cur = $data['current']; $prv = $data['previous']; $yoy = (float) $data['yoy_pct'];
        return [
            ['label' => 'Revenue HT',     'value_fmt' => self::amount((float) $cur['revenue_ht']),         'status' => 'na', 'subtitle' => 'XAF · current ' . $data['days'] . 'd',                                'threshold' => null],
            ['label' => 'YoY growth',     'value_fmt' => self::pctSigned($yoy),                            'status' => self::higherBetter($yoy, 5.0, -5.0), 'subtitle' => 'prev ' . self::amount((float) $prv['revenue_ht']), 'threshold' => 'green > +5%, amber -5..+5, red < -5%'],
            ['label' => 'Invoices',       'value_fmt' => (string) $cur['invoice_count'],                   'status' => 'na', 'subtitle' => 'prev ' . $prv['invoice_count'],                                       'threshold' => null],
            ['label' => 'Without PO',     'value_fmt' => (string) $cur['no_po_count'],                     'status' => self::lowerBetter($cur['no_po_count'], 1, 5), 'subtitle' => 'GL quality flag',          'threshold' => 'green = 0, amber 1-5, red > 5'],
        ];
    }

    public static function forReceivables(array $data): array
    {
        return [
            ['label' => 'Total AR',       'value_fmt' => self::amount((float) $data['total_ar']),         'status' => 'na', 'subtitle' => $data['open_invoice_count'] . ' open invoices',     'threshold' => null],
            ['label' => 'Overdue rate',   'value_fmt' => self::pct((float) $data['overdue_rate']),        'status' => self::lowerBetter($data['overdue_rate'], 10, 30), 'subtitle' => self::amount((float) $data['overdue_ar']) . ' overdue', 'threshold' => 'green < 10%, amber 10-30%, red > 30%'],
            ['label' => 'DSO',            'value_fmt' => number_format((float) $data['dso'], 0) . 'd',    'status' => self::lowerBetter($data['dso'], 30, 60), 'subtitle' => 'days sales outstanding',     'threshold' => 'green < 30d, amber 30-60d, red > 60d'],
            ['label' => 'Top 5 share',    'value_fmt' => self::pct((float) $data['top5_pct']),            'status' => self::lowerBetter($data['top5_pct'], 50, 65), 'subtitle' => 'concentration risk',      'threshold' => 'green < 50%, amber 50-65%, red > 65%'],
        ];
    }

    public static function forTreasury(array $data): array
    {
        $net = (float) $data['net_movement'];
        return [
            ['label' => 'Cash position',  'value_fmt' => self::amount((float) $data['cash_total']),       'status' => $data['cash_total'] > 0 ? 'green' : 'red', 'subtitle' => 'all bank + cash accounts', 'threshold' => 'green > 0, red ≤ 0'],
            ['label' => 'Inflows · ' . $data['days'] . 'd', 'value_fmt' => '+' . self::amount((float) $data['inflow_total']),  'status' => 'na', 'subtitle' => 'customer payments',                                      'threshold' => null],
            ['label' => 'Outflows · ' . $data['days'] . 'd','value_fmt' => '−' . self::amount((float) $data['outflow_total']), 'status' => 'na', 'subtitle' => 'suppliers + opex',                                       'threshold' => null],
            ['label' => 'Net movement',   'value_fmt' => ($net >= 0 ? '+' : '−') . self::amount(abs($net)), 'status' => self::higherBetter($net, 1, -1), 'subtitle' => 'inflows − outflows',                            'threshold' => 'green > 0, amber ≈ 0, red < 0'],
        ];
    }

    public static function forPayables(array $data): array
    {
        return [
            ['label' => 'Total AP',       'value_fmt' => self::amount((float) $data['total_ap']),         'status' => 'na', 'subtitle' => $data['open_bill_count'] . ' open bills',           'threshold' => null],
            ['label' => 'Overdue rate',   'value_fmt' => self::pct((float) $data['overdue_rate']),        'status' => self::lowerBetter($data['overdue_rate'], 10, 30), 'subtitle' => self::amount((float) $data['overdue_ap']) . ' overdue', 'threshold' => 'green < 10%, amber 10-30%, red > 30%'],
            // DPO is "higher better" up to a point — taking longer to pay = better cash position
            ['label' => 'DPO',            'value_fmt' => number_format((float) $data['dpo'], 0) . 'd',    'status' => self::higherBetter($data['dpo'], 30, 15), 'subtitle' => 'days payable outstanding', 'threshold' => 'green > 30d (negotiated), amber 15-30d'],
            ['label' => 'Suppliers',      'value_fmt' => (string) count($data['by_supplier']),            'status' => 'na', 'subtitle' => 'with open balance',                                'threshold' => null],
        ];
    }

    public static function forProfitLoss(array $data): array
    {
        return [
            ['label' => 'Revenue',        'value_fmt' => self::amount((float) $data['revenue']),          'status' => 'na', 'subtitle' => 'last ' . $data['days'] . ' days HT',                                              'threshold' => null],
            ['label' => 'Gross margin',   'value_fmt' => self::pct((float) $data['gross_margin_pct']),    'status' => self::higherBetter($data['gross_margin_pct'], 35, 20), 'subtitle' => 'gross ' . self::amount((float) $data['gross_profit']), 'threshold' => 'green > 35%, amber 20-35%, red < 20%'],
            ['label' => 'EBITDA margin',  'value_fmt' => self::pctSigned((float) $data['ebitda_margin_pct']), 'status' => self::higherBetter($data['ebitda_margin_pct'], 10, 0), 'subtitle' => 'ebitda ' . self::amount((float) $data['ebitda']),  'threshold' => 'green > 10%, amber 0-10%, red < 0%'],
            ['label' => 'Net margin',     'value_fmt' => self::pctSigned((float) $data['net_margin_pct']),    'status' => self::higherBetter($data['net_margin_pct'], 8, 0),  'subtitle' => 'net ' . self::amount((float) $data['net_profit']),    'threshold' => 'green > 8%, amber 0-8%, red < 0%'],
        ];
    }

    public static function forBilling(array $data): array
    {
        $collectPct = $data['total_billed_ttc'] > 0 ? ($data['total_collected'] / $data['total_billed_ttc']) * 100 : 0;
        return [
            ['label' => 'Invoices',       'value_fmt' => (string) $data['total_invoices'],                'status' => 'na', 'subtitle' => 'issued in window',                                'threshold' => null],
            ['label' => 'Total billed HT','value_fmt' => self::amount((float) $data['total_billed_ht']),  'status' => 'na', 'subtitle' => 'XAF',                                             'threshold' => null],
            ['label' => 'Avg invoice',    'value_fmt' => self::amount((float) $data['avg_invoice']),      'status' => 'na', 'subtitle' => 'per invoice',                                     'threshold' => null],
            ['label' => 'Collected',      'value_fmt' => self::pct($collectPct),                          'status' => self::higherBetter($collectPct, 85, 70), 'subtitle' => 'of billed TTC',                                'threshold' => 'green > 85%, amber 70-85%, red < 70%'],
        ];
    }

    public static function forProcurement(array $data): array
    {
        return [
            ['label' => 'Total spend HT',  'value_fmt' => self::amount((float) $data['total_spend']),     'status' => 'na', 'subtitle' => $data['bill_count'] . ' bills',                                'threshold' => null],
            ['label' => 'Avg bill',        'value_fmt' => self::amount((float) $data['avg_bill']),        'status' => 'na', 'subtitle' => 'XAF per bill',                                                'threshold' => null],
            ['label' => 'Maverick spend',  'value_fmt' => self::pct((float) $data['no_po_pct']),          'status' => self::lowerBetter($data['no_po_pct'], 5, 15), 'subtitle' => $data['no_po_count'] . ' bills without PO', 'threshold' => 'green < 5%, amber 5-15%, red > 15%'],
            ['label' => 'Top 5 share',     'value_fmt' => self::pct((float) $data['top5_supplier_pct']),  'status' => self::lowerBetter($data['top5_supplier_pct'], 50, 65), 'subtitle' => 'supplier concentration',          'threshold' => 'green < 50%, amber 50-65%, red > 65%'],
        ];
    }

    public static function forCustomerInsights(array $data): array
    {
        $atRiskPct = $data['customer_count'] > 0 ? ($data['at_risk_count'] / $data['customer_count']) * 100 : 0;
        return [
            ['label' => 'Total customers', 'value_fmt' => (string) $data['customer_count'],               'status' => 'na', 'subtitle' => 'on the books',                                    'threshold' => null],
            ['label' => 'Paid up',         'value_fmt' => (string) $data['paid_up_count'],                'status' => 'green', 'subtitle' => 'no balance',                                  'threshold' => null],
            ['label' => 'Healthy open',    'value_fmt' => (string) $data['healthy_count'],                'status' => 'amber', 'subtitle' => 'in-terms outstanding',                       'threshold' => null],
            ['label' => 'At risk',         'value_fmt' => (string) $data['at_risk_count'],                'status' => self::lowerBetter($atRiskPct, 10, 30), 'subtitle' => self::pct($atRiskPct) . ' of customers', 'threshold' => 'green < 10%, amber 10-30%, red > 30%'],
        ];
    }

    public static function forWorkingCapital(array $data): array
    {
        return [
            ['label' => 'Current ratio',  'value_fmt' => number_format((float) $data['current_ratio'], 2) . 'x', 'status' => self::higherBetter($data['current_ratio'], 1.5, 1.0), 'subtitle' => 'CA / CL',                  'threshold' => 'green ≥ 1.5x, amber 1.0-1.5x, red < 1.0x'],
            ['label' => 'Quick ratio',    'value_fmt' => number_format((float) $data['quick_ratio'], 2) . 'x',   'status' => self::higherBetter($data['quick_ratio'], 1.0, 0.7),   'subtitle' => '(Cash+AR) / CL',           'threshold' => 'green ≥ 1.0x, amber 0.7-1.0x, red < 0.7x'],
            ['label' => 'CCC',            'value_fmt' => number_format((float) $data['cash_conversion_cycle'], 0) . 'd', 'status' => self::lowerBetter($data['cash_conversion_cycle'], 30, 60), 'subtitle' => 'DSO − DPO',  'threshold' => 'green < 30d, amber 30-60d, red > 60d'],
            ['label' => 'Working capital','value_fmt' => self::amount((float) $data['working_capital']),  'status' => $data['working_capital'] >= 0 ? 'green' : 'red', 'subtitle' => 'CA − CL', 'threshold' => 'green > 0, red ≤ 0'],
        ];
    }

    public static function forFixedAssets(array $data): array
    {
        $pctRemaining = $data['total_cost'] > 0 ? ($data['total_nbv'] / $data['total_cost']) * 100 : 0;
        return [
            ['label' => 'Asset count',     'value_fmt' => (string) $data['asset_count'],                  'status' => 'na', 'subtitle' => 'in register',                                     'threshold' => null],
            ['label' => 'Acquisition cost','value_fmt' => self::amount((float) $data['total_cost']),      'status' => 'na', 'subtitle' => 'XAF',                                             'threshold' => null],
            ['label' => 'Net book value',  'value_fmt' => self::amount((float) $data['total_nbv']),       'status' => self::higherBetter($pctRemaining, 50, 25), 'subtitle' => self::pct($pctRemaining) . ' of cost remaining', 'threshold' => 'green > 50%, amber 25-50%, red < 25%'],
            ['label' => 'Monthly dep',     'value_fmt' => self::amount((float) $data['monthly_dep_run']), 'status' => 'na', 'subtitle' => 'recurring run-rate',                              'threshold' => null],
        ];
    }

    public static function forInventory(array $data): array
    {
        return [
            ['label' => 'Stock on hand',  'value_fmt' => number_format((float) $data['total_units_in_stock'], 0), 'status' => 'na', 'subtitle' => 'units',                                'threshold' => null],
            ['label' => 'Stock value',    'value_fmt' => self::amount((float) $data['total_stock_value']),         'status' => 'na', 'subtitle' => 'at COGS',                              'threshold' => null],
            ['label' => 'Units sold 90d', 'value_fmt' => number_format((float) $data['total_units_sold_90d'], 0),  'status' => 'na', 'subtitle' => 'last 90 days',                         'threshold' => null],
            ['label' => 'Avg turnover',   'value_fmt' => number_format((float) $data['avg_turnover'], 2) . 'x',    'status' => self::higherBetter($data['avg_turnover'], 1.0, 0.5),  'subtitle' => '90d basis',                            'threshold' => 'green ≥ 1.0x, amber 0.5-1.0x, red < 0.5x'],
        ];
    }

    // ── Financial Reporting (4 modules) ───────────────────────────

    public static function forTrialBalance(array $data): array
    {
        $deltaAbs = abs((float) $data['total_debit'] - (float) $data['total_credit']);
        return [
            ['label' => 'Total debits',   'value_fmt' => self::amount((float) $data['total_debit']),      'status' => 'na', 'subtitle' => 'XAF',                                             'threshold' => null],
            ['label' => 'Total credits',  'value_fmt' => self::amount((float) $data['total_credit']),     'status' => 'na', 'subtitle' => 'XAF',                                             'threshold' => null],
            ['label' => 'Δ (Dr − Cr)',    'value_fmt' => self::amount($deltaAbs),                          'status' => $data['balanced'] ? 'green' : 'red', 'subtitle' => 'absolute difference',                                                 'threshold' => 'green < 0.5 XAF, red ≥ 0.5 XAF'],
            ['label' => 'Balance check',  'value_fmt' => $data['balanced'] ? 'Balanced ✓' : 'Out',         'status' => $data['balanced'] ? 'green' : 'red', 'subtitle' => 'double-entry integrity',                                              'threshold' => null],
        ];
    }

    public static function forGlQuality(array $data): array
    {
        $score = (int) $data['overall_score'];
        return [
            ['label' => 'Quality score',  'value_fmt' => $score . '/100',                                 'status' => $score >= 80 ? 'green' : ($score >= 60 ? 'amber' : 'red'), 'subtitle' => 'composite of all findings', 'threshold' => 'green ≥ 80, amber 60-79, red < 60'],
            ['label' => 'Invoices w/o PO','value_fmt' => (string) $data['no_po_count'],                   'status' => self::lowerBetter($data['no_po_pct'], 5, 15),  'subtitle' => self::pct((float) $data['no_po_pct']) . ' of total',                  'threshold' => 'green < 5%, amber 5-15%, red > 15%'],
            ['label' => 'Unbalanced JE',  'value_fmt' => (string) $data['unbalanced_count'],              'status' => $data['unbalanced_count'] === 0 ? 'green' : 'red', 'subtitle' => 'posted entries',                                                  'threshold' => 'green = 0, red > 0'],
            ['label' => 'Draft invoices', 'value_fmt' => (string) $data['draft_count'],                   'status' => self::lowerBetter($data['draft_count'], 0, 3), 'subtitle' => 'not yet posted',                                                  'threshold' => 'green = 0, amber 1-3, red > 3'],
        ];
    }

    public static function forBalanceSheet(array $data): array
    {
        $check = (float) $data['balance_check'];
        return [
            ['label' => 'Total assets',     'value_fmt' => self::amount((float) $data['total_assets']),     'status' => 'green', 'subtitle' => 'XAF',                                                            'threshold' => null],
            ['label' => 'Total liabilities','value_fmt' => self::amount((float) $data['total_liabilities']),'status' => 'na',    'subtitle' => 'XAF',                                                            'threshold' => null],
            ['label' => 'Total equity',     'value_fmt' => self::amount((float) $data['total_equity']),     'status' => 'na',    'subtitle' => 'XAF',                                                            'threshold' => null],
            ['label' => 'Balance check',    'value_fmt' => self::amount(abs($check)),                       'status' => abs($check) < 100 ? 'green' : 'amber', 'subtitle' => 'A − (L + E)',                     'threshold' => 'green |Δ| < 100 XAF'],
        ];
    }

    public static function forOhadaStatements(array $data): array
    {
        $actif = (float) $data['bilan_actif_total'];
        $passif = (float) $data['bilan_passif_total'];
        $delta = abs($actif - $passif);
        // Find subtotal lines in CR
        $cr = collect($data['cr_lines']);
        $grossProfit = (float) ($cr->firstWhere('code', 'XB')['amount'] ?? 0);
        $ebitda = (float) ($cr->firstWhere('code', 'XD')['amount'] ?? 0);
        $netProfit = (float) ($cr->firstWhere('code', 'XI')['amount'] ?? 0);
        return [
            ['label' => 'Total ACTIF',    'value_fmt' => self::amount($actif),                            'status' => 'green', 'subtitle' => 'XAF',                                              'threshold' => null],
            ['label' => 'Total PASSIF',   'value_fmt' => self::amount($passif),                           'status' => 'na',    'subtitle' => 'XAF',                                              'threshold' => null],
            ['label' => 'Marge brute',    'value_fmt' => self::amount($grossProfit),                      'status' => $grossProfit > 0 ? 'green' : 'red',  'subtitle' => 'XB · gross profit',     'threshold' => null],
            ['label' => 'Résultat net',   'value_fmt' => self::amount($netProfit),                        'status' => $netProfit > 0 ? 'green' : 'red',    'subtitle' => 'XI · net profit',       'threshold' => null],
        ];
    }

    // ── Tax Compliance (6 modules) ────────────────────────────────

    public static function forTaxDashboard(array $data): array
    {
        return [
            ['label' => 'VAT due current', 'value_fmt' => self::amount((float) $data['vat_payable_current']),    'status' => 'na', 'subtitle' => 'this month',                                  'threshold' => null],
            ['label' => 'IRPP current',    'value_fmt' => self::amount((float) $data['irpp_payable_current']),   'status' => 'na', 'subtitle' => 'withheld on salaries',                        'threshold' => null],
            ['label' => 'IS annual est',   'value_fmt' => self::amount((float) $data['is_estimated_annual']),    'status' => 'na', 'subtitle' => 'corporate tax',                               'threshold' => null],
            ['label' => 'Invoice compliance','value_fmt' => self::pct((float) $data['invoice_compliance_pct']),  'status' => self::higherBetter($data['invoice_compliance_pct'], 95, 80), 'subtitle' => $data['invoice_issues'] . ' issues', 'threshold' => 'green > 95%, amber 80-95%, red < 80%'],
        ];
    }

    public static function forIncomeTax(array $data): array
    {
        $basisCls = $data['tax_basis'] === 'IS_calc' ? 'amber' : 'red';
        return [
            ['label' => 'Annualised revenue','value_fmt' => self::amount((float) $data['annualised_revenue']),   'status' => 'na', 'subtitle' => 'YTD ' . $data['days_ytd'] . 'd × 365/' . $data['days_ytd'], 'threshold' => null],
            ['label' => 'Taxable profit',    'value_fmt' => self::amount((float) $data['annualised_taxable']),    'status' => $data['annualised_taxable'] > 0 ? 'green' : 'red', 'subtitle' => 'annualised', 'threshold' => null],
            ['label' => 'IS 33%',            'value_fmt' => self::amount((float) $data['is_annual']),            'status' => 'na', 'subtitle' => '33% × taxable',                              'threshold' => null],
            ['label' => 'IS payable',        'value_fmt' => self::amount((float) $data['effective_tax']),        'status' => $basisCls, 'subtitle' => 'max(IS calc, 2% × CA)',                  'threshold' => 'amber on IS calc, red on minimum tax floor'],
        ];
    }

    public static function forVatCompliance(array $data): array
    {
        return [
            ['label' => 'VAT 6m total',   'value_fmt' => self::amount((float) $data['total_vat_6m']),     'status' => 'na', 'subtitle' => 'last 6 months payable',                           'threshold' => null],
            ['label' => 'Filed',          'value_fmt' => (string) $data['filed_count'],                   'status' => 'green', 'subtitle' => 'returns submitted',                            'threshold' => null],
            ['label' => 'Pending',        'value_fmt' => (string) $data['pending_count'],                 'status' => $data['pending_count'] > 0 ? 'amber' : 'green', 'subtitle' => 'awaiting filing',                                                  'threshold' => null],
            ['label' => 'Overdue',        'value_fmt' => (string) $data['overdue_count'],                 'status' => $data['overdue_count'] > 0 ? 'red' : 'green',   'subtitle' => 'past 15th of month',                                              'threshold' => 'green = 0, red > 0'],
        ];
    }

    public static function forInvoiceCompliance(array $data): array
    {
        $pct = (float) $data['compliance_pct'];
        return [
            ['label' => 'Compliance score','value_fmt' => self::pct($pct),                                'status' => self::higherBetter($pct, 95, 80), 'subtitle' => 'CGI checks · per invoice', 'threshold' => 'green > 95%, amber 80-95%, red < 80%'],
            ['label' => 'Invoices checked','value_fmt' => (string) $data['total_checked'],                'status' => 'na', 'subtitle' => 'in trailing window',                              'threshold' => null],
            ['label' => 'Issues found',    'value_fmt' => (string) $data['issue_count'],                  'status' => $data['issue_count'] === 0 ? 'green' : ($data['issue_count'] <= 3 ? 'amber' : 'red'), 'subtitle' => 'rule failures',                              'threshold' => 'green = 0, amber 1-3, red > 3'],
            ['label' => 'Issue rate',      'value_fmt' => self::pct($data['total_checked'] > 0 ? (((int) $data['issue_count']) / (int) $data['total_checked']) * 100 : 0), 'status' => self::lowerBetter($data['total_checked'] > 0 ? (((int) $data['issue_count']) / (int) $data['total_checked']) * 100 : 0, 5, 20), 'subtitle' => 'issues / checked', 'threshold' => 'green < 5%, amber 5-20%, red > 20%'],
        ];
    }

    public static function forTaxReturn(array $data): array
    {
        return [
            ['label' => 'CA HT (sales)',  'value_fmt' => self::amount((float) $data['ca_ht']),            'status' => 'na', 'subtitle' => $data['invoice_count'] . ' invoices',              'threshold' => null],
            ['label' => 'VAT collected',  'value_fmt' => self::amount((float) $data['vat_collected']),    'status' => 'na', 'subtitle' => '19.25% × CA HT',                                  'threshold' => null],
            ['label' => 'VAT deductible', 'value_fmt' => self::amount((float) $data['vat_deductible']),   'status' => 'na', 'subtitle' => '~19.25% × opex',                                  'threshold' => null],
            ['label' => 'VAT payable',    'value_fmt' => self::amount((float) $data['vat_payable']),      'status' => 'red', 'subtitle' => 'collected − deductible',                          'threshold' => null],
        ];
    }

    public static function forReturnReconciliation(array $data): array
    {
        return [
            ['label' => 'GL Class 70',    'value_fmt' => self::amount((float) $data['gl_class_70']),      'status' => 'na', 'subtitle' => 'explicit revenue JE',                             'threshold' => null],
            ['label' => 'Invoice CA HT',  'value_fmt' => self::amount((float) $data['invoice_ht']),       'status' => 'na', 'subtitle' => 'from posted invoices',                            'threshold' => null],
            ['label' => 'GL total',       'value_fmt' => self::amount((float) $data['gl_total']),         'status' => 'na', 'subtitle' => 'ground truth',                                    'threshold' => null],
            ['label' => 'Variance',       'value_fmt' => ($data['variance'] >= 0 ? '+' : '') . self::amount((float) $data['variance']), 'status' => $data['reconciled'] ? 'green' : 'red', 'subtitle' => $data['reconciled'] ? 'reconciled ✓' : self::pct((float) $data['variance_pct']) . ' off', 'threshold' => 'green |Δ| < 100 XAF'],
        ];
    }

    // ── Financial Planning (5 live modules) ───────────────────────

    public static function forMacroEconomics(array $data): array
    {
        // Pull 4 headline indicators from the array (gdp, cpi, usd_xaf, beac_policy)
        $idx = collect($data['indicators'])->keyBy('key');
        return [
            ['label' => 'GDP growth (Y/Y)',     'value_fmt' => (string) ($idx['gdp_growth']['value']  ?? '—'), 'status' => 'green', 'subtitle' => (string) ($idx['gdp_growth']['source']  ?? ''),    'threshold' => null],
            ['label' => 'Inflation (CPI)',       'value_fmt' => (string) ($idx['cpi']['value']         ?? '—'), 'status' => 'amber', 'subtitle' => (string) ($idx['cpi']['source']         ?? ''),    'threshold' => null],
            ['label' => 'USD / XAF',             'value_fmt' => (string) ($idx['usd_xaf']['value']     ?? '—'), 'status' => 'na',    'subtitle' => (string) ($idx['usd_xaf']['source']     ?? ''),    'threshold' => null],
            ['label' => 'BEAC policy rate',      'value_fmt' => (string) ($idx['beac_policy']['value'] ?? '—'), 'status' => 'na',    'subtitle' => (string) ($idx['beac_policy']['source'] ?? ''),    'threshold' => null],
        ];
    }

    public static function forPlForecast(array $data): array
    {
        $ebitda = (float) $data['forecast_total_ebitda'];
        $monthly = $data['baseline_monthly'];
        return [
            ['label' => 'Forecast revenue', 'value_fmt' => self::amount((float) $data['forecast_total_revenue']), 'status' => 'na', 'subtitle' => $data['months'] . '-month total',           'threshold' => null],
            ['label' => 'Forecast EBITDA',  'value_fmt' => ($ebitda >= 0 ? '+' : '−') . self::amount(abs($ebitda)), 'status' => $ebitda >= 0 ? 'green' : 'red', 'subtitle' => $data['months'] . '-month total',                                                     'threshold' => 'green > 0, red < 0'],
            ['label' => 'Monthly baseline', 'value_fmt' => self::amount((float) $monthly['revenue']),              'status' => 'na', 'subtitle' => 'revenue / month',                          'threshold' => null],
            ['label' => 'Avg margin',       'value_fmt' => self::pct($monthly['revenue'] > 0 ? ($monthly['ebitda'] / $monthly['revenue']) * 100 : 0), 'status' => self::higherBetter($monthly['revenue'] > 0 ? ($monthly['ebitda'] / $monthly['revenue']) * 100 : 0, 10, 0), 'subtitle' => 'EBITDA / revenue', 'threshold' => 'green > 10%, amber 0-10%, red < 0%'],
        ];
    }

    public static function forCashflowForecast(array $data): array
    {
        $final = (float) $data['final_cash'];
        $opening = (float) $data['opening_cash'];
        $min = (float) $data['min_cash'];
        return [
            ['label' => 'Opening cash',   'value_fmt' => self::amount($opening),                          'status' => $opening > 0 ? 'green' : 'red', 'subtitle' => 'today',                                                                                  'threshold' => null],
            ['label' => 'End cash · wk ' . $data['weeks'], 'value_fmt' => self::amount($final),          'status' => $final >= $opening ? 'green' : ($final >= 0 ? 'amber' : 'red'), 'subtitle' => 'projected',                                                                'threshold' => 'green ≥ opening, amber drawing down, red < 0'],
            ['label' => 'Minimum cash',   'value_fmt' => self::amount($min),                              'status' => $min > 0 ? ($min >= $opening * 0.5 ? 'green' : 'amber') : 'red', 'subtitle' => 'trough in window',                                                       'threshold' => 'green > 50% opening, amber 0-50%, red ≤ 0'],
            ['label' => 'Weekly opex',    'value_fmt' => self::amount((float) $data['weekly_opex']),     'status' => 'na', 'subtitle' => 'baseline run-rate',                                'threshold' => null],
        ];
    }

    public static function forInvestmentAnalysis(array $data): array
    {
        $npv = (float) $data['npv'];
        $irr = (float) $data['irr'];
        $payback = $data['payback_years'];
        $rate = (float) $data['discount_rate'];
        return [
            ['label' => 'NPV',             'value_fmt' => ($npv >= 0 ? '+' : '−') . self::amount(abs($npv)), 'status' => $npv > 0 ? 'green' : 'red', 'subtitle' => '@ ' . self::pct($rate * 100),                                                            'threshold' => 'green > 0, red ≤ 0'],
            ['label' => 'IRR',             'value_fmt' => self::pct($irr * 100),                            'status' => $irr > $rate ? 'green' : 'red', 'subtitle' => 'vs hurdle ' . self::pct($rate * 100),                                                'threshold' => 'green > discount rate'],
            ['label' => 'Payback',         'value_fmt' => $payback !== null ? number_format($payback, 1) . ' yr' : '—', 'status' => $payback !== null && $payback <= 3 ? 'green' : ($payback !== null && $payback <= 5 ? 'amber' : 'red'), 'subtitle' => 'years to recover initial',     'threshold' => 'green ≤ 3y, amber 3-5y, red > 5y'],
            ['label' => 'Decision',        'value_fmt' => $npv > 0 ? 'Invest ✓' : 'Reject ✗',                'status' => $npv > 0 ? 'green' : 'red', 'subtitle' => 'NPV-based recommendation',                                                                'threshold' => null],
        ];
    }

    public static function forBudgetUpload(array $data): array
    {
        $lines = collect($data['sample_budget_lines']);
        $totalBudget = (float) $lines->sum(fn ($l) => $l['monthly_budget'] * 3);
        $totalActual = (float) $lines->sum('ytd_actual');
        $variance = $totalActual - $totalBudget;
        return [
            ['label' => 'Lines tracked',  'value_fmt' => (string) $lines->count(),                        'status' => 'na', 'subtitle' => 'sample budget',                                   'threshold' => null],
            ['label' => 'YTD budget',     'value_fmt' => self::amount($totalBudget),                      'status' => 'na', 'subtitle' => '3-month total',                                   'threshold' => null],
            ['label' => 'YTD actual',     'value_fmt' => self::amount($totalActual),                      'status' => 'na', 'subtitle' => 'across all lines',                                'threshold' => null],
            ['label' => 'Net variance',   'value_fmt' => ($variance >= 0 ? '+' : '−') . self::amount(abs($variance)), 'status' => abs($variance) <= $totalBudget * 0.05 ? 'green' : 'amber', 'subtitle' => self::pct($totalBudget > 0 ? abs($variance) / $totalBudget * 100 : 0) . ' off plan', 'threshold' => 'green within ±5% of plan'],
        ];
    }
}
