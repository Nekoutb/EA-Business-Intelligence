<?php
declare(strict_types=1);

namespace App\Services\Demo;

use App\Models\Demo\DemoInvoice;
use App\Models\Demo\DemoInvoiceLine;
use App\Models\Demo\DemoJournalLine;
use App\Models\Demo\DemoPayment;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Turns the per-tenant demo_* tables into the KPI payloads the demo
 * views consume.
 *
 * Same shape (loosely) as the real RevenueService / ReceivablesService /
 * ProfitLossService produce against Odoo, so the visitor sees the same
 * card chrome, the same metric labels, the same colour coding. We don't
 * reuse those services verbatim because they're tightly coupled to
 * the OdooClient JSON-RPC interface — re-implementing the maths against
 * Eloquent is faster than building a fake OdooClient that quacks like
 * the real one.
 *
 * Every method takes a Tenant + a period (default last 90 days, last
 * 30 days for the "current month" KPIs) and returns a plain array
 * that the views can render directly.
 */
final class DemoFactsService
{
    /**
     * Headline dashboard payload — what the demo /dashboard renders.
     *
     * Mirrors the same 5-KPI structure the real dashboard produces:
     *   - Revenue (current period)
     *   - Gross margin %
     *   - EBITDA margin % (approximated for demo: revenue − COGS − opex from journals)
     *   - DSO (days sales outstanding)
     *   - Overdue rate %
     */
    public function dashboard(Tenant $tenant): array
    {
        $now      = now();
        $thisStart = $now->copy()->subDays(30)->startOfDay();
        $prevStart = $now->copy()->subDays(60)->startOfDay();
        $prevEnd   = $thisStart->copy()->subSecond();

        // Revenue + COGS in current 30-day window (from invoice lines)
        $cur = $this->revenueWindow($tenant, $thisStart, $now);
        $prv = $this->revenueWindow($tenant, $prevStart, $prevEnd);

        // P&L approximation: revenue − COGS − opex booked via journals
        $opex30 = $this->opexJournalSum($tenant, $thisStart, $now);
        $grossProfit = $cur['revenue_ht'] - $cur['cogs'];
        $ebitda      = $grossProfit - $opex30;

        $grossMarginPct  = $cur['revenue_ht'] > 0 ? ($grossProfit / $cur['revenue_ht']) * 100 : 0;
        $ebitdaMarginPct = $cur['revenue_ht'] > 0 ? ($ebitda      / $cur['revenue_ht']) * 100 : 0;

        // YoY proxy: this 30d vs prior 30d
        $yoyPct = $prv['revenue_ht'] > 0
            ? (($cur['revenue_ht'] - $prv['revenue_ht']) / $prv['revenue_ht']) * 100
            : 0;

        // AR figures
        $ar = $this->receivables($tenant);

        return [
            'currency'        => 'XAF',
            'period_label'    => $thisStart->format('d M') . ' → ' . $now->format('d M Y'),
            'revenue'         => $cur['revenue_ht'],
            'revenue_prev'    => $prv['revenue_ht'],
            'yoy_pct'         => $yoyPct,
            'gross_margin_pct'=> $grossMarginPct,
            'ebitda_margin_pct'=> $ebitdaMarginPct,
            'dso'             => $ar['dso'],
            'overdue_rate'    => $ar['overdue_rate'],
            'total_ar'        => $ar['total_ar'],
            'overdue_ar'      => $ar['overdue_ar'],
            'invoice_count'   => $cur['invoice_count'],
            'customers_active'=> $cur['customers_active'],
            'cogs'            => $cur['cogs'],
            'gross_profit'    => $grossProfit,
            'opex'            => $opex30,
            'ebitda'          => $ebitda,
            // GL quality signal — share of invoices without a matching PO
            'no_po_count'     => $cur['no_po_count'],
            'no_po_pct'       => $cur['invoice_count'] > 0 ? ($cur['no_po_count'] / $cur['invoice_count']) * 100 : 0,
        ];
    }

    /**
     * Revenue analysis — current window vs prior window, by customer
     * + by product category. Drives the demo /revenue page.
     */
    public function revenueDetail(Tenant $tenant, int $days = 30): array
    {
        $now       = now();
        $start     = $now->copy()->subDays($days)->startOfDay();
        $prevStart = $now->copy()->subDays($days * 2)->startOfDay();
        $prevEnd   = $start->copy()->subSecond();

        $cur = $this->revenueWindow($tenant, $start, $now);
        $prv = $this->revenueWindow($tenant, $prevStart, $prevEnd);

        // By customer
        $byCustomer = DemoInvoice::query()
            ->where('tenant_id', $tenant->id)
            ->whereBetween('invoice_date', [$start, $now])
            ->where('status', '!=', 'cancelled')
            ->join('demo_customers', 'demo_customers.id', '=', 'demo_invoices.customer_id')
            ->groupBy('demo_customers.id', 'demo_customers.name', 'demo_customers.category')
            ->orderByDesc(\DB::raw('SUM(demo_invoices.amount_ht)'))
            ->limit(10)
            ->get([
                'demo_customers.name as customer_name',
                'demo_customers.category as customer_category',
                \DB::raw('SUM(demo_invoices.amount_ht) as revenue'),
                \DB::raw('COUNT(demo_invoices.id) as invoice_count'),
            ])
            ->map(fn ($row) => [
                'customer'      => $row->customer_name,
                'category'      => $row->customer_category,
                'revenue'       => (float) $row->revenue,
                'invoice_count' => (int) $row->invoice_count,
                'share_pct'     => $cur['revenue_ht'] > 0 ? ((float) $row->revenue / $cur['revenue_ht']) * 100 : 0,
            ])
            ->toArray();

        // By product category
        $byCategory = DemoInvoiceLine::query()
            ->join('demo_invoices', 'demo_invoices.id', '=', 'demo_invoice_lines.invoice_id')
            ->leftJoin('demo_products', 'demo_products.id', '=', 'demo_invoice_lines.product_id')
            ->where('demo_invoices.tenant_id', $tenant->id)
            ->whereBetween('demo_invoices.invoice_date', [$start, $now])
            ->where('demo_invoices.status', '!=', 'cancelled')
            ->groupBy('demo_products.category')
            ->orderByDesc(\DB::raw('SUM(demo_invoice_lines.subtotal)'))
            ->get([
                'demo_products.category',
                \DB::raw('SUM(demo_invoice_lines.subtotal) as revenue'),
                \DB::raw('SUM(demo_invoice_lines.cogs_amount) as cogs'),
            ])
            ->map(fn ($row) => [
                'category'   => $row->category ?? '—',
                'revenue'    => (float) $row->revenue,
                'cogs'       => (float) $row->cogs,
                'gross'      => (float) $row->revenue - (float) $row->cogs,
                'margin_pct' => $row->revenue > 0 ? ((((float) $row->revenue - (float) $row->cogs) / (float) $row->revenue) * 100) : 0,
            ])
            ->toArray();

        return [
            'currency'      => 'XAF',
            'days'          => $days,
            'period_label'  => $start->format('d M') . ' → ' . $now->format('d M Y'),
            'current'       => $cur,
            'previous'      => $prv,
            'yoy_pct'       => $prv['revenue_ht'] > 0 ? (($cur['revenue_ht'] - $prv['revenue_ht']) / $prv['revenue_ht']) * 100 : 0,
            'by_customer'   => $byCustomer,
            'by_category'   => $byCategory,
        ];
    }

    /**
     * Receivables analysis — AR aging buckets + DSO + overdue rate.
     * Drives the demo /receivables page.
     */
    public function receivables(Tenant $tenant): array
    {
        $today = now()->startOfDay();

        // All posted invoices with an outstanding balance (total − payments).
        $rows = DemoInvoice::query()
            ->with(['customer', 'payments'])
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', ['posted', 'paid'])
            ->get()
            ->map(function ($inv) use ($today) {
                $paid     = (float) $inv->payments->sum('amount');
                $balance  = (float) $inv->total - $paid;
                $dueDate  = Carbon::parse($inv->due_date);
                $daysOverdue = $balance > 0.01 ? max(0, $today->diffInDays($dueDate, false) * -1) : 0;
                return [
                    'invoice'        => $inv,
                    'balance'        => $balance,
                    'days_overdue'   => $daysOverdue,
                    'is_open'        => $balance > 0.01,
                ];
            })
            ->filter(fn ($r) => $r['is_open']);

        $totalAr      = (float) $rows->sum('balance');
        $overdue      = $rows->filter(fn ($r) => $r['days_overdue'] > 0);
        $overdueAr    = (float) $overdue->sum('balance');
        $overdueRate  = $totalAr > 0 ? ($overdueAr / $totalAr) * 100 : 0;

        // Aging buckets
        $buckets = ['current' => 0, '1-30' => 0, '31-60' => 0, '61-90' => 0, '90+' => 0];
        foreach ($rows as $r) {
            $d = $r['days_overdue'];
            $b = (float) $r['balance'];
            if      ($d <= 0)  $buckets['current'] += $b;
            elseif  ($d <= 30) $buckets['1-30']    += $b;
            elseif  ($d <= 60) $buckets['31-60']   += $b;
            elseif  ($d <= 90) $buckets['61-90']   += $b;
            else               $buckets['90+']     += $b;
        }

        // DSO — accountant's classic: (AR / revenue last 90d) × 90
        $rev90 = $this->revenueWindow($tenant, $today->copy()->subDays(90), $today);
        $dso   = $rev90['revenue_ht'] > 0
            ? ($totalAr / $rev90['revenue_ht']) * 90
            : 0;

        // Top 5 customers by outstanding balance — concentration risk
        $byCustomer = $rows
            ->groupBy(fn ($r) => $r['invoice']->customer->name ?? '—')
            ->map(fn ($g, $name) => [
                'customer' => $name,
                'balance'  => (float) $g->sum('balance'),
                'invoice_count' => $g->count(),
            ])
            ->sortByDesc('balance')
            ->values()
            ->toArray();
        $top5Pct = $totalAr > 0
            ? (collect($byCustomer)->take(5)->sum('balance') / $totalAr) * 100
            : 0;

        return [
            'currency'           => 'XAF',
            'as_of'              => $today->format('Y-m-d'),
            'total_ar'           => $totalAr,
            'overdue_ar'         => $overdueAr,
            'overdue_rate'       => $overdueRate,
            'dso'                => round($dso, 1),
            'aging'              => $buckets,
            'aging_pct'          => array_map(fn ($v) => $totalAr > 0 ? ($v / $totalAr) * 100 : 0, $buckets),
            'open_invoice_count' => $rows->count(),
            'by_customer'        => $byCustomer,
            'top5_pct'           => $top5Pct,
            'pct_gt_60'          => $totalAr > 0 ? (($buckets['61-90'] + $buckets['90+']) / $totalAr) * 100 : 0,
            'pct_gt_90'          => $totalAr > 0 ? ($buckets['90+'] / $totalAr) * 100 : 0,
        ];
    }

    /**
     * P&L view — revenue / COGS / gross / opex (from journals) / EBITDA.
     */
    public function profitLoss(Tenant $tenant, int $days = 90): array
    {
        $now   = now();
        $start = $now->copy()->subDays($days)->startOfDay();

        $rev = $this->revenueWindow($tenant, $start, $now);
        $opex = $this->opexJournalSum($tenant, $start, $now);

        $grossProfit = $rev['revenue_ht'] - $rev['cogs'];
        $ebitda      = $grossProfit - $opex;
        $netProfit   = $ebitda - $this->depreciationSum($tenant, $start, $now);

        return [
            'currency'         => 'XAF',
            'days'             => $days,
            'period_label'     => $start->format('d M') . ' → ' . $now->format('d M Y'),
            'revenue'          => $rev['revenue_ht'],
            'cogs'             => $rev['cogs'],
            'gross_profit'     => $grossProfit,
            'gross_margin_pct' => $rev['revenue_ht'] > 0 ? ($grossProfit / $rev['revenue_ht']) * 100 : 0,
            'opex'             => $opex,
            'ebitda'           => $ebitda,
            'ebitda_margin_pct'=> $rev['revenue_ht'] > 0 ? ($ebitda / $rev['revenue_ht']) * 100 : 0,
            'depreciation'     => $this->depreciationSum($tenant, $start, $now),
            'net_profit'       => $netProfit,
            'net_margin_pct'   => $rev['revenue_ht'] > 0 ? ($netProfit / $rev['revenue_ht']) * 100 : 0,
            'opex_breakdown'   => $this->opexBreakdown($tenant, $start, $now),
        ];
    }

    // ── helpers ────────────────────────────────────────────────────

    /**
     * Revenue + COGS aggregates in a window from posted/paid invoices.
     */
    private function revenueWindow(Tenant $tenant, Carbon $from, Carbon $to): array
    {
        $invoices = DemoInvoice::query()
            ->where('tenant_id', $tenant->id)
            ->whereBetween('invoice_date', [$from, $to])
            ->whereIn('status', ['posted', 'paid'])
            ->get();

        $lines = DemoInvoiceLine::query()
            ->whereIn('invoice_id', $invoices->pluck('id'))
            ->get();

        return [
            'revenue_ht'       => (float) $invoices->sum('amount_ht'),
            'vat'              => (float) $invoices->sum('vat_amount'),
            'revenue_ttc'      => (float) $invoices->sum('total'),
            'cogs'             => (float) $lines->sum('cogs_amount'),
            'invoice_count'    => $invoices->count(),
            'customers_active' => $invoices->pluck('customer_id')->unique()->count(),
            'no_po_count'      => $invoices->where('has_po', false)->count(),
        ];
    }

    /** Sum of opex-side journal lines (class 6 debit) in window, excluding COGS (601*) and depreciation (681*). */
    private function opexJournalSum(Tenant $tenant, Carbon $from, Carbon $to): float
    {
        return (float) DemoJournalLine::query()
            ->join('demo_journal_entries', 'demo_journal_entries.id', '=', 'demo_journal_lines.entry_id')
            ->where('demo_journal_entries.tenant_id', $tenant->id)
            ->where('demo_journal_entries.posted', true)
            ->whereBetween('demo_journal_entries.entry_date', [$from, $to])
            ->where('demo_journal_lines.account_code', 'like', '6%')
            ->where('demo_journal_lines.account_code', 'not like', '601%')   // COGS — already inside invoice lines
            ->where('demo_journal_lines.account_code', 'not like', '681%')   // depreciation — broken out separately
            ->sum('demo_journal_lines.debit');
    }

    private function depreciationSum(Tenant $tenant, Carbon $from, Carbon $to): float
    {
        return (float) DemoJournalLine::query()
            ->join('demo_journal_entries', 'demo_journal_entries.id', '=', 'demo_journal_lines.entry_id')
            ->where('demo_journal_entries.tenant_id', $tenant->id)
            ->where('demo_journal_entries.posted', true)
            ->whereBetween('demo_journal_entries.entry_date', [$from, $to])
            ->where('demo_journal_lines.account_code', 'like', '681%')
            ->sum('demo_journal_lines.debit');
    }

    private function opexBreakdown(Tenant $tenant, Carbon $from, Carbon $to): array
    {
        return DemoJournalLine::query()
            ->join('demo_journal_entries', 'demo_journal_entries.id', '=', 'demo_journal_lines.entry_id')
            ->where('demo_journal_entries.tenant_id', $tenant->id)
            ->where('demo_journal_entries.posted', true)
            ->whereBetween('demo_journal_entries.entry_date', [$from, $to])
            ->where('demo_journal_lines.account_code', 'like', '6%')
            ->where('demo_journal_lines.account_code', 'not like', '601%')
            ->groupBy('demo_journal_lines.account_code', 'demo_journal_lines.account_name')
            ->orderByDesc(\DB::raw('SUM(demo_journal_lines.debit)'))
            ->get([
                'demo_journal_lines.account_code',
                'demo_journal_lines.account_name',
                \DB::raw('SUM(demo_journal_lines.debit) as total_debit'),
            ])
            ->map(fn ($row) => [
                'code'   => $row->account_code,
                'name'   => $row->account_name,
                'amount' => (float) $row->total_debit,
            ])
            ->toArray();
    }
}
