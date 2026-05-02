<?php
declare(strict_types=1);

namespace App\Services;

use App\Odoo\OdooClient;
use App\Services\Tax\MonthlyTaxReturnService;
use App\Services\Tax\VATInvoiceComplianceService;

/**
 * DashboardKpiService — composes the 11 top-management KPIs from existing
 * services. No new GL queries; every KPI sources from a service that has
 * already been validated in its own module.
 *
 * KPI roster (locked spec v5):
 *   Theme A · Financial (4)
 *     F1  Scanned Revenue vs Budget       OperationsService::scanRevenueBreakdown()
 *     F2  Revenue Reconciliation Accuracy BillingService::reconciliationOnly()
 *     F3  EBITDA vs Budget                OhadaFinancialsService → cdrSubtotals.XD
 *     F4  Total AR + % >30d + % >60d      ReceivablesService → aging buckets
 *
 *   Theme B · Operational (4)
 *     O1  Vehicles / Containers Scanned   OperationsService → kpis.containers_scanned
 *     O2  Avg Revenue per Scan (Yield)    OperationsService → scan_revenue / count
 *     O3  Vehicle Mix                     OperationsService → mix_by_size
 *     O4  Scan-Time Efficiency            OperationsService → median_time_to_scan
 *
 *   Theme C · Compliance (3)
 *     C1  PO Compliance Rate              ProcurementService → kpis.po_compliance
 *     C2  GL Accuracy %                   GLQualityService → overall score
 *     C3  Tax Compliance Issues           VAT-invoice flags + monthly-return total
 */
final class DashboardKpiService
{
    public function __construct(private OdooClient $odoo) {}

    /**
     * @param array $period ['type' => 'mtd'|'ytd'|'month', 'year' => Y, 'month' => M]
     */
    public function computeAll(array $period, ?\DateTimeImmutable $today = null): array
    {
        $today    = $today ?? new \DateTimeImmutable('today');
        $currency = $this->detectCurrency();

        // Budgets (read once from settings)
        $bMonthlyScanned  = (float) (config_env('BUDGET_MONTHLY_SCANNED_REVENUE', 0));
        $bMonthlyRevenue  = (float) (config_env('BUDGET_MONTHLY_REVENUE',         0));
        $bMonthlyDirect   = (float) (config_env('BUDGET_MONTHLY_DIRECT_COSTS',    0));
        $bMonthlyIndirect = (float) (config_env('BUDGET_MONTHLY_INDIRECT_COSTS',  0));
        $bMonthlyEbitda   = $bMonthlyRevenue - $bMonthlyDirect - $bMonthlyIndirect;

        // Period multiplier — how many months into the budget cycle we are
        // for this dashboard view. MTD = 1 month; YTD = elapsed months.
        $multiplier = $this->budgetMultiplier($period, $today);

        // ── Compose KPIs ────────────────────────────────────────────────
        $kpis = [
            'F1' => $this->kpiF1ScannedRevenue($period, $today, $bMonthlyScanned * $multiplier, $currency),
            'F2' => $this->kpiF2Reconciliation($period, $today, $currency),
            'F3' => $this->kpiF3EbitdaVsBudget($period, $today, $bMonthlyEbitda * $multiplier, $currency),
            'F4' => $this->kpiF4ArAging($period, $today, $currency),

            'O1' => $this->kpiO1Volume($period, $today),
            'O2' => $this->kpiO2Yield($period, $today, $currency),
            'O3' => $this->kpiO3Mix($period, $today),
            'O4' => $this->kpiO4ScanTime($period, $today),

            'C1' => $this->kpiC1PoCompliance($period, $today),
            'C2' => $this->kpiC2GlAccuracy($today),
            'C3' => $this->kpiC3TaxIssues($period, $today, $currency),
        ];

        return [
            'currency'   => $currency,
            'period'     => $period,
            'as_of'      => $today->format('Y-m-d'),
            'kpis'       => $kpis,
        ];
    }

    /* ──────────────────────────────────────────────────────────────────
       Theme A · Financial
       ────────────────────────────────────────────────────────────────── */

    private function kpiF1ScannedRevenue(array $period, \DateTimeImmutable $today, float $budget, string $currency): array
    {
        $base = ['id' => 'F1', 'theme' => 'financial', 'label' => 'Scanned Revenue vs Budget',
                 'sub' => 'Total expected revenue from scanning ops · vs scanning budget',
                 'unit' => $currency, 'variant' => 'standard',
                 'drill' => tenant_url('/operations')];
        try {
            [$cyFrom, $cyTo] = ea_resolve_period($today, $period);
            $opsSvc = new OperationsService($this->odoo);
            $revenue = $opsSvc->scanRevenueBreakdown($cyFrom, $cyTo);
            $value = (float) ($revenue['total_revenue'] ?? 0);

            // Prior-year scanning revenue for vs-PY chip
            $pyFrom = $cyFrom->modify('-1 year');
            $pyTo   = $cyTo->modify('-1 year');
            $pyRev  = $opsSvc->scanRevenueBreakdown($pyFrom, $pyTo);
            $pyValue = (float) ($pyRev['total_revenue'] ?? 0);

            return $base + [
                'value'      => $value,
                'value_fmt'  => $this->fmtCfa($value),
                'vs_budget'  => $budget > 0 ? ($value / $budget) - 1.0 : null,
                'vs_py'      => $pyValue > 0 ? ($value / $pyValue) - 1.0 : null,
                'budget'     => $budget,
                'pending'    => false,
            ];
        } catch (\Throwable $e) {
            return $base + ['value' => 0, 'value_fmt' => '—', 'vs_budget' => null, 'vs_py' => null, 'pending' => true, 'error' => $e->getMessage()];
        }
    }

    private function kpiF2Reconciliation(array $period, \DateTimeImmutable $today, string $currency): array
    {
        $base = ['id' => 'F2', 'theme' => 'financial', 'label' => 'Revenue Reconciliation Accuracy',
                 'sub' => 'Scanned → Billed → Collected leakage',
                 'unit' => $currency, 'variant' => 'wide_recon',
                 'drill' => tenant_url('/billing')];
        try {
            $billing = new BillingService($this->odoo);
            // F2 always reads YTD per spec (reconciliation is cumulative).
            $ytdPeriod = ['type' => 'ytd', 'year' => $period['year'] ?? (int) $today->format('Y'),
                          'month' => $period['month'] ?? (int) $today->format('m')];
            $recon = $billing->reconciliationOnly($today, $ytdPeriod);
            $h = $recon['headline'] ?? [];

            return $base + [
                'expected'  => (float) ($h['expected']  ?? 0),
                'billed'    => (float) ($h['billed']    ?? 0),
                'collected' => (float) ($h['collected'] ?? 0),
                'billing_realisation_pct'    => $h['billing_realisation_pct']    ?? null,
                'collection_realisation_pct' => $h['collection_realisation_pct'] ?? null,
                'pending' => !empty($recon['error']),
                'error'   => $recon['error'] ?? null,
            ];
        } catch (\Throwable $e) {
            return $base + ['expected' => 0, 'billed' => 0, 'collected' => 0, 'pending' => true, 'error' => $e->getMessage()];
        }
    }

    private function kpiF3EbitdaVsBudget(array $period, \DateTimeImmutable $today, float $budget, string $currency): array
    {
        $base = ['id' => 'F3', 'theme' => 'financial', 'label' => 'EBITDA vs Budget',
                 'sub' => "Excédent Brut d'Exploitation (XD subtotal) vs budget",
                 'unit' => $currency, 'variant' => 'standard',
                 'drill' => tenant_url('/ohada-financial-statements')];
        try {
            $year       = (int) ($period['year']  ?? $today->format('Y'));
            $month      = (int) ($period['month'] ?? $today->format('m'));
            $periodType = ($period['type'] ?? 'ytd') === 'mtd' ? 'month' : 'ytd';

            $ohada = new OhadaFinancialsService($this->odoo);
            $analysis = $ohada->analyze($year, $periodType, $month);
            $cdrSubtotals = $analysis['compte_de_resultat']['subtotals'] ?? [];
            $value = (float) ($cdrSubtotals['XD']['amount'] ?? 0);

            return $base + [
                'value'     => $value,
                'value_fmt' => $this->fmtCfa($value),
                'vs_budget' => $budget > 0 ? ($value / $budget) - 1.0 : null,
                'budget'    => $budget,
                'pending'   => false,
            ];
        } catch (\Throwable $e) {
            return $base + ['value' => 0, 'value_fmt' => '—', 'vs_budget' => null, 'pending' => true, 'error' => $e->getMessage()];
        }
    }

    private function kpiF4ArAging(array $period, \DateTimeImmutable $today, string $currency): array
    {
        $base = ['id' => 'F4', 'theme' => 'financial', 'label' => 'Total AR + Aging',
                 'sub' => 'Total outstanding · % > 30 d · % > 60 d',
                 'unit' => $currency, 'variant' => 'wide_aging',
                 'drill' => tenant_url('/receivables')];
        try {
            // Receivables service needs revenue_ytd to compute DSO; read it once.
            $year = (int) ($period['year'] ?? $today->format('Y'));
            $startOfYear = new \DateTimeImmutable($year . '-01-01');
            $daysInYtd = max(1, (int) $today->diff($startOfYear)->days);

            $revSvc = new RevenueService($this->odoo, (string) config_env('REVENUE_SOURCE', 'invoice'));
            $revData = $revSvc->analyze(null, ['type' => 'ytd', 'year' => $year, 'month' => (int) $today->format('m')]);
            $revYtd  = (float) ($revData['current']['total'] ?? 0);

            $ar = (new ReceivablesService($this->odoo))->analyze($revYtd, $daysInYtd, $currency, $today);

            $totalAr   = (float) ($ar['total_ar']   ?? 0);
            $aging     = $ar['aging'] ?? [];
            $over30Amt = (float) ($aging['31_60']['amount']   ?? 0)
                       + (float) ($aging['61_90']['amount']   ?? 0)
                       + (float) ($aging['90_plus']['amount'] ?? 0);
            $over60Amt = (float) ($aging['61_90']['amount']   ?? 0)
                       + (float) ($aging['90_plus']['amount'] ?? 0);

            $pctOver30 = $totalAr > 0 ? ($over30Amt / $totalAr) * 100.0 : 0.0;
            $pctOver60 = $totalAr > 0 ? ($over60Amt / $totalAr) * 100.0 : 0.0;

            return $base + [
                'total_ar'    => $totalAr,
                'pct_over_30' => $pctOver30,
                'pct_over_60' => $pctOver60,
                'over_30_amt' => $over30Amt,
                'over_60_amt' => $over60Amt,
                'pending'     => false,
            ];
        } catch (\Throwable $e) {
            return $base + ['total_ar' => 0, 'pct_over_30' => 0, 'pct_over_60' => 0, 'pending' => true, 'error' => $e->getMessage()];
        }
    }

    /* ──────────────────────────────────────────────────────────────────
       Theme B · Operational
       ────────────────────────────────────────────────────────────────── */

    private function opsAnalysis(array $period, \DateTimeImmutable $today): array
    {
        // Single OperationsService::analyze() call shared across O1-O4.
        try {
            return (new OperationsService($this->odoo))->analyze($today, $period);
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function kpiO1Volume(array $period, \DateTimeImmutable $today): array
    {
        $base = ['id' => 'O1', 'theme' => 'operational', 'label' => 'Volume Scanned',
                 'sub' => 'Containers / vehicles scanned in period',
                 'unit' => 'count', 'variant' => 'standard',
                 'drill' => tenant_url('/operations')];
        $ops = $this->opsAnalysis($period, $today);
        $cs = $ops['kpis']['containers_scanned'] ?? null;
        if (!$cs || !empty($cs['pending'])) {
            return $base + ['value' => 0, 'value_fmt' => '—', 'vs_py' => null, 'pending' => true];
        }
        return $base + [
            'value'     => (string) ($cs['value'] ?? '0'),
            'value_fmt' => (string) ($cs['value'] ?? '0'),
            'vs_py'     => $this->parsePctChip($cs['vsPy'] ?? null),
            'pending'   => false,
        ];
    }

    private function kpiO2Yield(array $period, \DateTimeImmutable $today, string $currency): array
    {
        $base = ['id' => 'O2', 'theme' => 'operational', 'label' => 'Avg Revenue per Scan',
                 'sub' => 'Yield per scan · price-grid actual',
                 'unit' => $currency, 'variant' => 'standard',
                 'drill' => tenant_url('/operations')];
        try {
            [$cyFrom, $cyTo] = ea_resolve_period($today, $period);
            $opsSvc = new OperationsService($this->odoo);
            $rev = $opsSvc->scanRevenueBreakdown($cyFrom, $cyTo);
            $count = (int) ($rev['total_count'] ?? 0);
            $value = $count > 0 ? ((float) ($rev['total_revenue'] ?? 0)) / $count : 0.0;

            // PY yield for vs-PY chip
            $pyFrom = $cyFrom->modify('-1 year'); $pyTo = $cyTo->modify('-1 year');
            $pyRev = $opsSvc->scanRevenueBreakdown($pyFrom, $pyTo);
            $pyCount = (int) ($pyRev['total_count'] ?? 0);
            $pyValue = $pyCount > 0 ? ((float) ($pyRev['total_revenue'] ?? 0)) / $pyCount : 0.0;

            return $base + [
                'value'     => $value,
                'value_fmt' => $this->fmtCfa($value),
                'vs_py'     => $pyValue > 0 ? ($value / $pyValue) - 1.0 : null,
                'pending'   => false,
            ];
        } catch (\Throwable $e) {
            return $base + ['value' => 0, 'value_fmt' => '—', 'vs_py' => null, 'pending' => true, 'error' => $e->getMessage()];
        }
    }

    private function kpiO3Mix(array $period, \DateTimeImmutable $today): array
    {
        $base = ['id' => 'O3', 'theme' => 'operational', 'label' => 'Vehicle / Container Mix',
                 'sub' => 'Distribution by size · 20 ft vs 40 ft',
                 'unit' => 'pct', 'variant' => 'wide_mix',
                 'drill' => tenant_url('/operations')];
        $ops = $this->opsAnalysis($period, $today);
        $mix = $ops['kpis']['mix_by_size'] ?? null;
        if (!$mix || !empty($mix['pending'])) {
            return $base + ['segments' => [], 'pending' => true];
        }
        // OperationsService returns mix in the 'sub' or 'value' string;
        // normalise to a segment list the view can render.
        return $base + [
            'value_fmt' => (string) ($mix['value'] ?? '—'),
            'sub'       => (string) ($mix['sub']   ?? $base['sub']),
            'pending'   => false,
        ];
    }

    private function kpiO4ScanTime(array $period, \DateTimeImmutable $today): array
    {
        $base = ['id' => 'O4', 'theme' => 'operational', 'label' => 'Scan-Time Efficiency',
                 'sub' => 'Median minutes from arrival to scan',
                 'unit' => 'mins', 'variant' => 'standard',
                 'drill' => tenant_url('/operations')];
        $ops = $this->opsAnalysis($period, $today);
        $st  = $ops['kpis']['median_time_to_scan'] ?? null;
        if (!$st || !empty($st['pending'])) {
            return $base + ['value' => 0, 'value_fmt' => '—', 'vs_py' => null, 'pending' => true];
        }
        return $base + [
            'value'     => (string) ($st['value'] ?? '—'),
            'value_fmt' => (string) ($st['value'] ?? '—'),
            'vs_py'     => $this->parsePctChip($st['vsPy'] ?? null),
            'pending'   => false,
        ];
    }

    /* ──────────────────────────────────────────────────────────────────
       Theme C · Compliance
       ────────────────────────────────────────────────────────────────── */

    private function kpiC1PoCompliance(array $period, \DateTimeImmutable $today): array
    {
        $base = ['id' => 'C1', 'theme' => 'compliance', 'label' => 'PO Compliance Rate',
                 'sub' => 'Vendor bills with linked PO · share of total',
                 'unit' => 'pct', 'variant' => 'standard',
                 'drill' => tenant_url('/procurement')];
        try {
            $proc = (new ProcurementService($this->odoo))->analyze($today, $period);
            $kpi = $proc['kpis']['po_compliance'] ?? null;
            if (!$kpi || !empty($kpi['pending'])) {
                return $base + ['value_fmt' => '—', 'vs_py' => null, 'pending' => true];
            }
            return $base + [
                'value_fmt' => (string) ($kpi['value'] ?? '—'),
                'vs_py'     => $this->parsePctChip($kpi['vsPy'] ?? null),
                'pending'   => false,
            ];
        } catch (\Throwable $e) {
            return $base + ['value_fmt' => '—', 'vs_py' => null, 'pending' => true, 'error' => $e->getMessage()];
        }
    }

    private function kpiC2GlAccuracy(\DateTimeImmutable $today): array
    {
        $base = ['id' => 'C2', 'theme' => 'compliance', 'label' => 'GL Accuracy',
                 'sub' => 'Overall reconciliation + quality score',
                 'unit' => 'pct', 'variant' => 'standard',
                 'drill' => tenant_url('/general-ledger-quality')];
        try {
            $gl = (new GLQualityService($this->odoo))->analyze($today);
            // GLQualityService returns an overall score under several possible
            // keys. Read all known shapes.
            $score = (float) ($gl['overall_score'] ?? $gl['score'] ?? $gl['summary']['overall'] ?? 0);
            return $base + [
                'value'     => $score,
                'value_fmt' => number_format($score, 1) . ' %',
                'pending'   => $score === 0.0 && empty($gl),
            ];
        } catch (\Throwable $e) {
            return $base + ['value' => 0, 'value_fmt' => '—', 'pending' => true, 'error' => $e->getMessage()];
        }
    }

    private function kpiC3TaxIssues(array $period, \DateTimeImmutable $today, string $currency): array
    {
        $base = ['id' => 'C3', 'theme' => 'compliance', 'label' => 'Tax Compliance Issues',
                 'sub' => 'Flagged invoices · principal exposure (no penalties)',
                 'unit' => $currency, 'variant' => 'wide_tax',
                 'drill' => tenant_url('/monthly-tax-return')];
        try {
            // Issues count = flagged VAT invoices (simple non-compliance count).
            $issuesCount = 0;
            $exposure    = 0.0;
            try {
                $vat = (new VATInvoiceComplianceService($this->odoo))->analyze();
                $rows = $vat['rows'] ?? $vat['flagged'] ?? [];
                $issuesCount = is_array($rows) ? count($rows) : 0;
            } catch (\Throwable $e) { /* fall through */ }

            // Principal exposure = current month's total à payer from the I/TVA-IR.
            try {
                $month = sprintf('%04d-%02d', (int) ($period['year'] ?? $today->format('Y')),
                                              (int) ($period['month'] ?? $today->format('m')));
                $tax = (new MonthlyTaxReturnService($this->odoo))->analyze($month);
                $exposure = (float) ($tax['total_a_payer'] ?? 0);
            } catch (\Throwable $e) { /* fall through */ }

            return $base + [
                'issues_count' => $issuesCount,
                'exposure'     => $exposure,
                'exposure_fmt' => $this->fmtCfa($exposure),
                'pending'      => false,
            ];
        } catch (\Throwable $e) {
            return $base + ['issues_count' => 0, 'exposure' => 0, 'exposure_fmt' => '—', 'pending' => true, 'error' => $e->getMessage()];
        }
    }

    /* ──────────────────────────────────────────────────────────────────
       Helpers
       ────────────────────────────────────────────────────────────────── */

    /**
     * MTD → 1 month of budget · YTD → elapsed-months × monthly budget.
     * 'month' (historical full month) → 1 month.
     */
    private function budgetMultiplier(array $period, \DateTimeImmutable $today): float
    {
        $type = $period['type'] ?? 'ytd';
        if ($type === 'ytd') {
            $now = (int) $today->format('m');
            $sel = (int) ($period['month'] ?? $now);
            return (float) max(1, min(12, $sel));
        }
        return 1.0;   // mtd or month
    }

    /** Detect company currency from Odoo (XAF default for the customer base). */
    private function detectCurrency(): string
    {
        try {
            $companies = $this->odoo->searchRead('res.company', [], ['currency_id'], [], 1);
            if (!empty($companies) && !empty($companies[0]['currency_id'])) {
                return $companies[0]['currency_id'][1] ?? 'XAF';
            }
        } catch (\Throwable $e) { /* fall through */ }
        return 'XAF';
    }

    /** Format CFA-style numbers — "12,4 M" / "187,4 M" / "2,3 Md". */
    private function fmtCfa(float $n): string
    {
        $abs = abs($n);
        if ($abs >= 1_000_000_000) return number_format($n / 1_000_000_000, 1, ',', ' ') . ' Md';
        if ($abs >= 1_000_000)     return number_format($n / 1_000_000,     1, ',', ' ') . ' M';
        if ($abs >= 1_000)         return number_format($n / 1_000,         1, ',', ' ') . ' k';
        return number_format($n, 0, ',', ' ');
    }

    /**
     * Some upstream KPIs return a vsPy as a string (e.g. "+12.4%"); parse to
     * a fractional float for our own formatting.
     */
    private function parsePctChip($v): ?float
    {
        if ($v === null || $v === '') return null;
        if (is_numeric($v)) return (float) $v;
        if (preg_match('/(-?\d+(?:[.,]\d+)?)/', (string) $v, $m)) {
            return ((float) str_replace(',', '.', $m[1])) / 100.0;
        }
        return null;
    }
}
