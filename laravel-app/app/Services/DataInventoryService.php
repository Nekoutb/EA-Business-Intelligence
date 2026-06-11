<?php
declare(strict_types=1);

namespace App\Services;

use App\Odoo\OdooClient;

/**
 * Data Inventory for a tenant.
 *
 * Walks the tenant's Odoo install and reports, per financial domain,
 * which underlying data is available + what management reports we
 * can credibly generate from it. The output is the table shown on
 *
 *   /admin/business-insights/{tenant}/inventory
 *
 * Why this exists: before designing per-tenant reports, the operator
 * needs to know what each tenant ACTUALLY HAS in their Odoo. A
 * generic "monthly revenue trend" makes no sense for a tenant whose
 * out_invoice table is empty because they only sell through POS.
 *
 * Each domain returns a uniform shape:
 *
 *   [
 *     'key'         => 'revenue',
 *     'label'       => 'Revenue',
 *     'status'      => 'available' | 'sparse' | 'empty' | 'unavailable',
 *     'record_count'=> 1247,
 *     'first_date'  => '2023-01-15',
 *     'last_date'   => '2026-05-18',
 *     'months_span' => 40.3,
 *     'sources'     => ['account.move (out_invoice): 1247', 'pos.order: 832'],
 *     'notes'       => 'Invoice-only flow detected · POS data also available',
 *     'reports'     => [
 *         ['key' => 'monthly_trend',     'label' => 'Monthly revenue trend (12m)'],
 *         ['key' => 'yoy_growth',         'label' => 'YoY growth + cumulative gap'],
 *         ['key' => 'customer_concentration', 'label' => 'Customer concentration (top 10)'],
 *     ],
 *   ]
 *
 * Status semantics:
 *   - available    : enough data to generate the recommended reports
 *   - sparse       : data is present but limited (<3 months or <50 records)
 *   - empty        : Odoo model exists but is empty
 *   - unavailable  : Odoo model not installed / connection failed
 */
final class DataInventoryService
{
    public function __construct(private OdooClient $odoo) {}

    /**
     * Build the full inventory across all financial domains.
     *
     * @return array{
     *   generated_at: string,
     *   domains: array<int, array<string, mixed>>,
     *   summary: array{available:int, sparse:int, empty:int, unavailable:int},
     *   errors: array<int, string>,
     * }
     */
    public function inventory(): array
    {
        $errors = [];
        $domains = [];

        // ── Connectivity probe ─────────────────────────────────────────
        // Without this, a dead Odoo connection makes every safeCount()
        // below swallow its exception and return 0, so the whole
        // inventory reads as "all domains empty" — indistinguishable
        // from a tenant that genuinely has no data. We probe a model
        // that ALWAYS exists (res.company); if that throws, Odoo is
        // unreachable, so we abort with a clear error the controller
        // surfaces as "check the Odoo connection" instead of silently
        // reporting empty data. After a successful probe, a per-model
        // safeCount() failure legitimately means that model isn't
        // installed → 0 / empty is the correct reading.
        try {
            $this->odoo->searchCount('res.company', []);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'Odoo connection failed — cannot build the data inventory: ' . $e->getMessage(),
                0,
                $e
            );
        }

        // Order matters — the page shows domains in this order
        // (revenue first because it's the first management report
        // we're delivering on-demand).
        $audits = [
            ['key' => 'revenue',       'label' => 'Revenue',          'fn' => 'auditRevenue'],
            ['key' => 'receivables',   'label' => 'Receivables (AR)', 'fn' => 'auditReceivables'],
            ['key' => 'payables',      'label' => 'Payables (AP)',    'fn' => 'auditPayables'],
            ['key' => 'cash',          'label' => 'Cash & Treasury',  'fn' => 'auditCash'],
            ['key' => 'customers',     'label' => 'Customers',        'fn' => 'auditCustomers'],
            ['key' => 'suppliers',     'label' => 'Suppliers',        'fn' => 'auditSuppliers'],
            ['key' => 'inventory',     'label' => 'Inventory',        'fn' => 'auditInventory'],
            ['key' => 'fixed_assets',  'label' => 'Fixed Assets',     'fn' => 'auditFixedAssets'],
            ['key' => 'payroll',       'label' => 'Payroll',          'fn' => 'auditPayroll'],
            ['key' => 'tax',           'label' => 'Tax (VAT, IS)',    'fn' => 'auditTax'],
            ['key' => 'general_ledger','label' => 'General Ledger',   'fn' => 'auditGeneralLedger'],
        ];

        foreach ($audits as $a) {
            try {
                $row = $this->{$a['fn']}();
                $row['key']   = $a['key'];
                $row['label'] = $a['label'];
                $domains[]    = $row;
            } catch (\Throwable $e) {
                $errors[] = "{$a['label']}: " . $e->getMessage();
                $domains[] = [
                    'key'          => $a['key'],
                    'label'        => $a['label'],
                    'status'       => 'unavailable',
                    'record_count' => 0,
                    'first_date'   => null,
                    'last_date'    => null,
                    'months_span'  => 0,
                    'sources'      => [],
                    'notes'        => 'Audit failed: ' . substr($e->getMessage(), 0, 120),
                    'reports'      => [],
                ];
            }
        }

        // Summary counts
        $summary = ['available' => 0, 'sparse' => 0, 'empty' => 0, 'unavailable' => 0];
        foreach ($domains as $d) {
            $summary[$d['status']] = ($summary[$d['status']] ?? 0) + 1;
        }

        return [
            'generated_at' => now()->format('Y-m-d H:i'),
            'domains'      => $domains,
            'summary'      => $summary,
            'errors'       => $errors,
        ];
    }

    // ── Domain audits ──────────────────────────────────────────────

    private function auditRevenue(): array
    {
        $sources = [];

        // a) account.move (out_invoice / out_refund)
        $invCount = $this->safeCount('account.move', [
            ['move_type', 'in', ['out_invoice', 'out_refund']],
            ['state',     '=',  'posted'],
        ]);
        if ($invCount > 0) $sources[] = "account.move out_invoice: " . number_format($invCount);

        // b) sale.order (confirmed)
        $saleCount = $this->safeCount('sale.order', [['state', 'in', ['sale', 'done']]]);
        if ($saleCount > 0) $sources[] = "sale.order: " . number_format($saleCount);

        // c) pos.order (paid)
        $posCount = $this->safeCount('pos.order', [['state', 'in', ['paid', 'done', 'invoiced']]]);
        if ($posCount > 0) $sources[] = "pos.order: " . number_format($posCount);

        // d) Class 70 GL (revenue accounts)
        $gl70 = $this->safeCount('account.move.line', [
            ['parent_state', '=', 'posted'],
            ['account_id.code', '=like', '70%'],
        ]);
        if ($gl70 > 0) $sources[] = "class 70 move lines: " . number_format($gl70);

        // Date range from invoices (most common source)
        [$first, $last] = $this->dateRange('account.move',
            [['move_type', 'in', ['out_invoice']], ['state', '=', 'posted']],
            'invoice_date'
        );
        $months = $this->monthsBetween($first, $last);

        $total = $invCount + $saleCount + $posCount;
        $status = $this->classify($total, $months);
        $notes  = $this->revenueNotes($invCount, $saleCount, $posCount, $months);

        $reports = [];
        if ($status === 'available' || $status === 'sparse') {
            $reports[] = ['key' => 'monthly_trend',          'label' => 'Monthly revenue trend (12m)'];
            $reports[] = ['key' => 'yoy_growth',             'label' => 'YoY growth + cumulative gap'];
            $reports[] = ['key' => 'customer_concentration', 'label' => 'Customer concentration (top 10)'];
            if ($posCount > 0) {
                $reports[] = ['key' => 'pos_pattern',        'label' => 'POS day-of-week pattern'];
            }
            if ($saleCount > 0) {
                $reports[] = ['key' => 'pipeline_velocity',  'label' => 'Sales order → invoice velocity'];
            }
        }

        return [
            'status'       => $status,
            'record_count' => $total,
            'first_date'   => $first,
            'last_date'    => $last,
            'months_span'  => $months,
            'sources'      => $sources,
            'notes'        => $notes,
            'reports'      => $reports,
        ];
    }

    private function auditReceivables(): array
    {
        $open = $this->safeCount('account.move', [
            ['move_type',     'in', ['out_invoice', 'out_refund']],
            ['payment_state', 'in', ['not_paid', 'partial']],
            ['state',         '=',  'posted'],
        ]);
        $gl41 = $this->safeCount('account.move.line', [
            ['parent_state', '=', 'posted'],
            ['account_id.code', '=like', '41%'],
        ]);
        $sources = [];
        if ($open > 0) $sources[] = "open invoices: " . number_format($open);
        if ($gl41 > 0) $sources[] = "class 41 move lines: " . number_format($gl41);

        [$first, $last] = $this->dateRange('account.move',
            [['move_type','in',['out_invoice']], ['payment_state','in',['not_paid','partial']]],
            'invoice_date_due'
        );
        $status = $this->classify($open, 0);

        $reports = $status === 'empty' || $status === 'unavailable' ? [] : [
            ['key' => 'ar_aging',           'label' => 'AR aging (30/60/90+ buckets)'],
            ['key' => 'dso_trend',          'label' => 'DSO trend last 12 months'],
            ['key' => 'top_overdue',        'label' => 'Top 20 overdue invoices'],
        ];
        return [
            'status' => $status, 'record_count' => $open,
            'first_date' => $first, 'last_date' => $last, 'months_span' => 0,
            'sources' => $sources,
            'notes' => $open === 0 ? 'No open AR' : "{$open} open invoice(s)",
            'reports' => $reports,
        ];
    }

    private function auditPayables(): array
    {
        $open = $this->safeCount('account.move', [
            ['move_type',     'in', ['in_invoice', 'in_refund']],
            ['payment_state', 'in', ['not_paid', 'partial']],
            ['state',         '=',  'posted'],
        ]);
        $gl40 = $this->safeCount('account.move.line', [
            ['parent_state', '=', 'posted'],
            ['account_id.code', '=like', '40%'],
        ]);
        $sources = [];
        if ($open > 0) $sources[] = "open bills: " . number_format($open);
        if ($gl40 > 0) $sources[] = "class 40 move lines: " . number_format($gl40);

        [$first, $last] = $this->dateRange('account.move',
            [['move_type','in',['in_invoice']]], 'invoice_date_due'
        );
        $status = $this->classify($open, 0);

        $reports = $status === 'empty' || $status === 'unavailable' ? [] : [
            ['key' => 'ap_aging',         'label' => 'AP aging (30/60/90+ buckets)'],
            ['key' => 'dpo_trend',        'label' => 'DPO trend last 12 months'],
            ['key' => 'top_overdue_supp', 'label' => 'Top 20 overdue supplier balances'],
        ];
        return [
            'status' => $status, 'record_count' => $open,
            'first_date' => $first, 'last_date' => $last, 'months_span' => 0,
            'sources' => $sources,
            'notes' => $open === 0 ? 'No open AP' : "{$open} open bill(s)",
            'reports' => $reports,
        ];
    }

    private function auditCash(): array
    {
        $banks  = $this->safeCount('account.account', [['code', '=like', '52%']]);
        $petty  = $this->safeCount('account.account', [['code', '=like', '57%']]);
        $moves  = $this->safeCount('account.move.line', [
            ['parent_state', '=', 'posted'],
            ['account_id.code', '=like', '52%'],
        ]);
        $sources = [];
        if ($banks > 0)  $sources[] = "{$banks} bank account(s)";
        if ($petty > 0)  $sources[] = "{$petty} caisse account(s)";
        if ($moves > 0)  $sources[] = "class 52 movements: " . number_format($moves);

        [$first, $last] = $this->dateRange('account.move.line',
            [['parent_state','=','posted'], ['account_id.code', '=like', '52%']], 'date'
        );
        $months = $this->monthsBetween($first, $last);
        $status = $this->classify($moves, $months);

        $reports = $status === 'empty' || $status === 'unavailable' ? [] : [
            ['key' => 'cash_position',         'label' => 'Cash position by account'],
            ['key' => 'inflow_outflow_30d',    'label' => 'Inflow / outflow last 30 days'],
            ['key' => 'cash_burn_trend',       'label' => 'Cash burn / build trend (12m)'],
        ];
        return [
            'status' => $status, 'record_count' => $moves,
            'first_date' => $first, 'last_date' => $last, 'months_span' => $months,
            'sources' => $sources, 'notes' => "{$banks} bank + {$petty} caisse accounts",
            'reports' => $reports,
        ];
    }

    private function auditCustomers(): array
    {
        $count = $this->safeCount('res.partner', [['customer_rank', '>', 0]]);
        $status = $this->classify($count, 0);
        $reports = $status === 'empty' || $status === 'unavailable' ? [] : [
            ['key' => 'customer_segmentation', 'label' => 'Customer segmentation (revenue tiers)'],
            ['key' => 'new_vs_repeat',         'label' => 'New vs repeat customers (12m)'],
            ['key' => 'churn_risk',            'label' => 'Churn risk (inactive 90d+ AR open)'],
        ];
        return [
            'status' => $status, 'record_count' => $count,
            'first_date' => null, 'last_date' => null, 'months_span' => 0,
            'sources' => $count > 0 ? ["res.partner customer_rank>0: " . number_format($count)] : [],
            'notes' => $count === 0 ? 'No customers' : "{$count} customer record(s)",
            'reports' => $reports,
        ];
    }

    private function auditSuppliers(): array
    {
        $count = $this->safeCount('res.partner', [['supplier_rank', '>', 0]]);
        $status = $this->classify($count, 0);
        $reports = $status === 'empty' || $status === 'unavailable' ? [] : [
            ['key' => 'supplier_concentration', 'label' => 'Supplier concentration (top 10 spend)'],
            ['key' => 'maverick_spend',        'label' => 'Maverick spend (PO-less bills)'],
        ];
        return [
            'status' => $status, 'record_count' => $count,
            'first_date' => null, 'last_date' => null, 'months_span' => 0,
            'sources' => $count > 0 ? ["res.partner supplier_rank>0: " . number_format($count)] : [],
            'notes' => $count === 0 ? 'No suppliers' : "{$count} supplier record(s)",
            'reports' => $reports,
        ];
    }

    private function auditInventory(): array
    {
        $products = $this->safeCount('product.product', [['active', '=', true]]);

        // Stockable count — Odoo 18 renamed the flag: is_storable=true
        // replaces type='product' (the 'product' value was removed from
        // the type selection). Try the new field first, fall back to the
        // legacy domain. safeCount() can't be used for the first probe —
        // it would swallow the "field does not exist" error into a silent
        // 0 and we'd never try the fallback.
        try {
            $stockable = (int) $this->odoo->searchCount('product.product', [['active', '=', true], ['is_storable', '=', true]]);
        } catch (\Throwable $e) {
            $stockable = $this->safeCount('product.product', [['active', '=', true], ['type', '=', 'product']]);
        }

        // Probe actual stock-level data: products alone don't prove the
        // warehouse is used. quants > 0 is what makes the stock_value /
        // slow_movers reports meaningful.
        $quants = $this->safeCount('stock.quant', [['quantity', '!=', 0]]);

        $sources = [];
        if ($products > 0)  $sources[] = "product.product active: " . number_format($products);
        if ($stockable > 0) $sources[] = "stockable: " . number_format($stockable);
        if ($quants > 0)    $sources[] = "stock.quant non-zero: " . number_format($quants);

        $status = $this->classify($stockable, 0);
        $reports = $status === 'empty' || $status === 'unavailable' ? [] : [
            ['key' => 'stock_value',     'label' => 'Stock value snapshot'],
            ['key' => 'slow_movers',     'label' => 'Slow-moving stock (no sale 90d+)'],
        ];
        $notes = "{$stockable} stockable product(s)";
        $notes .= $quants > 0
            ? " · warehouse in use ({$quants} stock line(s))"
            : ' · no stock quantities — Inventory module may not be in use';

        return [
            'status' => $status, 'record_count' => $stockable,
            'first_date' => null, 'last_date' => null, 'months_span' => 0,
            'sources' => $sources, 'notes' => $notes,
            'reports' => $reports,
        ];
    }

    private function auditFixedAssets(): array
    {
        $assets = 0;
        try {
            $assets = $this->odoo->searchCount('account.asset', [['state', '!=', 'draft']]);
        } catch (\Throwable $e) {
            // account.asset module not installed
        }
        $gl2 = $this->safeCount('account.move.line', [
            ['parent_state', '=', 'posted'],
            ['account_id.code', '=like', '2%'],
        ]);
        $sources = [];
        if ($assets > 0) $sources[] = "account.asset: " . number_format($assets);
        if ($gl2 > 0)    $sources[] = "class 2 move lines: " . number_format($gl2);
        $status = $this->classify($assets + ($gl2 > 100 ? 1 : 0), 0);
        $reports = $status === 'empty' || $status === 'unavailable' ? [] : [
            ['key' => 'asset_register', 'label' => 'Asset register (NBV by class)'],
            ['key' => 'depreciation',   'label' => 'Depreciation schedule (12m)'],
        ];
        return [
            'status' => $status, 'record_count' => $assets,
            'first_date' => null, 'last_date' => null, 'months_span' => 0,
            'sources' => $sources,
            'notes' => $assets === 0 && $gl2 > 0 ? 'Asset module not used; class 2 GL has movements' : "{$assets} asset(s)",
            'reports' => $reports,
        ];
    }

    private function auditPayroll(): array
    {
        $payslips = 0; $employees = 0;
        try { $payslips  = $this->odoo->searchCount('hr.payslip',  [['state', '=', 'done']]); } catch (\Throwable $e) {}
        try { $employees = $this->odoo->searchCount('hr.employee', [['active', '=', true]]); } catch (\Throwable $e) {}
        $gl66 = $this->safeCount('account.move.line', [
            ['parent_state', '=', 'posted'],
            ['account_id.code', '=like', '66%'],
        ]);
        $sources = [];
        if ($employees > 0) $sources[] = "hr.employee active: " . number_format($employees);
        if ($payslips > 0)  $sources[] = "hr.payslip done: " . number_format($payslips);
        if ($gl66 > 0)      $sources[] = "class 66 move lines: " . number_format($gl66);
        [$first, $last] = $this->dateRange('account.move.line',
            [['parent_state','=','posted'], ['account_id.code', '=like', '66%']], 'date'
        );
        $months = $this->monthsBetween($first, $last);
        $status = $this->classify($payslips + ($gl66 > 0 ? 1 : 0), $months);
        $reports = $status === 'empty' || $status === 'unavailable' ? [] : [
            ['key' => 'payroll_trend',  'label' => 'Payroll cost trend (12m)'],
            ['key' => 'payroll_kpis',   'label' => 'Headcount + average comp'],
        ];
        return [
            'status' => $status, 'record_count' => $payslips,
            'first_date' => $first, 'last_date' => $last, 'months_span' => $months,
            'sources' => $sources,
            'notes' => $payslips === 0 && $gl66 > 0 ? 'HR module not used; class 66 GL fallback available' : "{$payslips} payslip(s)",
            'reports' => $reports,
        ];
    }

    private function auditTax(): array
    {
        $taxes = $this->safeCount('account.tax', [['active', '=', true]]);
        $gl44 = $this->safeCount('account.move.line', [
            ['parent_state', '=', 'posted'],
            ['account_id.code', '=like', '44%'],
        ]);
        $sources = [];
        if ($taxes > 0) $sources[] = "account.tax active: " . number_format($taxes);
        if ($gl44 > 0)  $sources[] = "class 44 move lines: " . number_format($gl44);
        $status = $this->classify($gl44, 0);
        $reports = $status === 'empty' || $status === 'unavailable' ? [] : [
            ['key' => 'vat_position',  'label' => 'VAT position (collected vs deductible)'],
            ['key' => 'tax_schedule',  'label' => 'Upcoming tax remittance schedule'],
        ];
        return [
            'status' => $status, 'record_count' => $gl44,
            'first_date' => null, 'last_date' => null, 'months_span' => 0,
            'sources' => $sources, 'notes' => "{$taxes} tax def · {$gl44} GL line(s)",
            'reports' => $reports,
        ];
    }

    private function auditGeneralLedger(): array
    {
        $accounts = $this->safeCount('account.account', []);
        $lines    = $this->safeCount('account.move.line', [['parent_state', '=', 'posted']]);
        [$first, $last] = $this->dateRange('account.move.line', [['parent_state','=','posted']], 'date');
        $months = $this->monthsBetween($first, $last);
        $status = $this->classify($lines, $months);
        $sources = ["account.account: {$accounts}", "posted move lines: " . number_format($lines)];
        $reports = $status === 'empty' || $status === 'unavailable' ? [] : [
            ['key' => 'trial_balance', 'label' => 'Trial balance (as of)'],
            ['key' => 'gl_quality',    'label' => 'GL data quality score'],
        ];
        return [
            'status' => $status, 'record_count' => $lines,
            'first_date' => $first, 'last_date' => $last, 'months_span' => $months,
            'sources' => $sources, 'notes' => "{$accounts} accounts · {$months} months of posted activity",
            'reports' => $reports,
        ];
    }

    // ── Helpers ─────────────────────────────────────────────────────

    private function safeCount(string $model, array $domain): int
    {
        try {
            return (int) $this->odoo->searchCount($model, $domain);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Get first + last date for records matching a domain.
     * Returns [null, null] if no records or query fails.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function dateRange(string $model, array $domain, string $dateField): array
    {
        try {
            $earliest = $this->odoo->searchRead($model, $domain, [$dateField],
                ['limit' => 1, 'order' => "{$dateField} asc"]);
            $latest   = $this->odoo->searchRead($model, $domain, [$dateField],
                ['limit' => 1, 'order' => "{$dateField} desc"]);
            return [
                $earliest[0][$dateField] ?? null,
                $latest[0][$dateField]   ?? null,
            ];
        } catch (\Throwable $e) {
            return [null, null];
        }
    }

    private function monthsBetween(?string $first, ?string $last): float
    {
        if (!$first || !$last) return 0.0;
        try {
            $f = \Carbon\Carbon::parse($first);
            $l = \Carbon\Carbon::parse($last);
            // Carbon 3 returns a SIGNED day difference: $l->diffInDays($f)
            // is negative when $f (the earlier date) is passed as the
            // argument. Without abs() this returns a negative span, which
            // makes classify() see `$months < 1.5` as always true and
            // downgrade EVERY date-spanned domain to 'sparse' forever.
            return round(abs($f->diffInDays($l)) / 30.44, 1);
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    /**
     * Classify status based on record count + span.
     */
    private function classify(int $count, float $months): string
    {
        if ($count === 0)                        return 'empty';
        if ($count < 20 || $months < 1.5)        return 'sparse';
        return 'available';
    }

    private function revenueNotes(int $inv, int $sale, int $pos, float $months): string
    {
        $parts = [];
        if ($inv > 0)  $parts[] = "invoice-driven ({$inv} inv)";
        if ($sale > 0) $parts[] = "sale-orders ({$sale} SO)";
        if ($pos > 0)  $parts[] = "POS ({$pos} orders)";
        if (empty($parts)) return 'No revenue activity detected in Odoo';
        $summary = implode(' · ', $parts);
        if ($months > 0) $summary .= " · {$months} months span";
        return $summary;
    }
}
