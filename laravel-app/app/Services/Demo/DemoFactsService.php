<?php
declare(strict_types=1);

namespace App\Services\Demo;

use App\Models\Demo\DemoAccount;
use App\Models\Demo\DemoCustomer;
use App\Models\Demo\DemoFixedAsset;
use App\Models\Demo\DemoInvoice;
use App\Models\Demo\DemoInvoiceLine;
use App\Models\Demo\DemoJournalEntry;
use App\Models\Demo\DemoJournalLine;
use App\Models\Demo\DemoPayment;
use App\Models\Demo\DemoProduct;
use App\Models\Demo\DemoSupplier;
use App\Models\Demo\DemoSupplierBill;
use App\Models\Demo\DemoSupplierPayment;
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

        // By customer.
        // CAREFUL: both demo_invoices and demo_customers have a tenant_id
        // column. After the join below, every column reference in WHERE /
        // GROUP BY / ORDER BY must be fully qualified or MySQL will throw
        // "Column 'tenant_id' in where clause is ambiguous" (production-only
        // bug — SQLite was happily picking one).
        $byCustomer = DemoInvoice::query()
            ->join('demo_customers', 'demo_customers.id', '=', 'demo_invoices.customer_id')
            ->where('demo_invoices.tenant_id', $tenant->id)
            ->whereBetween('demo_invoices.invoice_date', [$start, $now])
            ->where('demo_invoices.status', '!=', 'cancelled')
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

    // ── Additional BMS modules ─────────────────────────────────────

    /**
     * Trial balance — every account with non-zero movement in the
     * window, debit + credit columns, plus opening balance and
     * computed period balance. Drives the demo /trial-balance page.
     */
    public function trialBalance(Tenant $tenant, ?Carbon $asOf = null): array
    {
        $asOf = $asOf ?? now()->startOfDay();
        $accounts = DemoAccount::where('tenant_id', $tenant->id)->orderBy('code')->get();

        // Aggregate posted journal lines per account, up to as-of date.
        $movement = DemoJournalLine::query()
            ->join('demo_journal_entries', 'demo_journal_entries.id', '=', 'demo_journal_lines.entry_id')
            ->where('demo_journal_entries.tenant_id', $tenant->id)
            ->where('demo_journal_entries.posted', true)
            ->where('demo_journal_entries.entry_date', '<=', $asOf)
            ->groupBy('demo_journal_lines.account_code')
            ->get([
                'demo_journal_lines.account_code',
                \DB::raw('SUM(demo_journal_lines.debit) as total_debit'),
                \DB::raw('SUM(demo_journal_lines.credit) as total_credit'),
            ])
            ->keyBy('account_code');

        // Invoice/AR side: posted invoices contribute to 411000 (clients)
        // and 70110 (revenue) and 443100 (VAT collected). Payments
        // contribute to 521000 (bank) and reduce 411000.
        $invoiceTotals = DemoInvoice::query()
            ->where('tenant_id', $tenant->id)
            ->where('invoice_date', '<=', $asOf)
            ->whereIn('status', ['posted', 'paid'])
            ->get(['amount_ht', 'vat_amount', 'total']);
        $paymentTotals = DemoPayment::query()
            ->where('tenant_id', $tenant->id)
            ->where('payment_date', '<=', $asOf)
            ->get(['amount']);

        $sumInvoiceHt   = (float) $invoiceTotals->sum('amount_ht');
        $sumInvoiceVat  = (float) $invoiceTotals->sum('vat_amount');
        $sumInvoiceTtc  = (float) $invoiceTotals->sum('total');
        $sumPayments    = (float) $paymentTotals->sum('amount');
        // Implicit double-entry from the operational side:
        //   411000 Dr by total (invoiced), Cr by payments collected
        //   701100 Cr by amount_ht
        //   443100 Cr by vat
        //   521000 Dr by payments collected
        $implicit = [
            '411000' => ['debit' => $sumInvoiceTtc, 'credit' => $sumPayments],
            '701100' => ['debit' => 0,              'credit' => $sumInvoiceHt],
            '443100' => ['debit' => 0,              'credit' => $sumInvoiceVat],
            '521000' => ['debit' => $sumPayments,   'credit' => 0],
        ];

        $rows = [];
        $totalDebit = 0.0; $totalCredit = 0.0;
        foreach ($accounts as $a) {
            $jlDebit  = (float) ($movement[$a->code]->total_debit ?? 0);
            $jlCredit = (float) ($movement[$a->code]->total_credit ?? 0);
            $impDebit = (float) ($implicit[$a->code]['debit'] ?? 0);
            $impCredit= (float) ($implicit[$a->code]['credit']?? 0);
            $debit    = $jlDebit  + $impDebit;
            $credit   = $jlCredit + $impCredit;
            $opening  = (float) $a->opening_balance;
            $balance  = $opening + $debit - $credit;
            if ($debit < 0.01 && $credit < 0.01 && abs($opening) < 0.01) continue;
            $rows[] = [
                'code'    => $a->code,
                'name'    => $a->name,
                'class'   => (int) $a->class,
                'type'    => $a->type,
                'opening' => $opening,
                'debit'   => $debit,
                'credit'  => $credit,
                'balance' => $balance,
            ];
            $totalDebit  += $debit;
            $totalCredit += $credit;
        }

        return [
            'currency'   => 'XAF',
            'as_of'      => $asOf->format('Y-m-d'),
            'rows'       => $rows,
            'total_debit'=> $totalDebit,
            'total_credit'=>$totalCredit,
            'balanced'   => abs($totalDebit - $totalCredit) < 0.5,
        ];
    }

    /**
     * GL quality findings — duplicate of the dashboard's
     * "invoices without PO" flag plus more detail:
     *   - invoices missing a PO number
     *   - unbalanced journal entries (shouldn't exist but the
     *     check is the value)
     *   - draft invoices not yet posted
     *   - posted invoices with no lines (data-quality issue)
     */
    public function glQuality(Tenant $tenant): array
    {
        $noPoInvoices = DemoInvoice::query()
            ->with('customer')
            ->where('tenant_id', $tenant->id)
            ->where('has_po', false)
            ->orderByDesc('invoice_date')
            ->limit(50)
            ->get();

        $unbalancedEntries = DemoJournalEntry::query()
            ->where('tenant_id', $tenant->id)
            ->where('posted', true)
            ->get()
            ->filter(fn ($e) => !$e->isBalanced());

        $draftInvoices = DemoInvoice::query()
            ->with('customer')
            ->where('tenant_id', $tenant->id)
            ->where('status', 'draft')
            ->get();

        $totalInvoices  = DemoInvoice::where('tenant_id', $tenant->id)->count();
        $noPoCount      = $noPoInvoices->count();
        $unbalancedCount= $unbalancedEntries->count();
        $draftCount     = $draftInvoices->count();

        return [
            'as_of'              => now()->format('Y-m-d'),
            'currency'           => 'XAF',
            'total_invoices'     => $totalInvoices,
            'no_po_count'        => $noPoCount,
            'no_po_pct'          => $totalInvoices > 0 ? ($noPoCount / $totalInvoices) * 100 : 0,
            'no_po_invoices'     => $noPoInvoices,
            'unbalanced_count'   => $unbalancedCount,
            'unbalanced_entries' => $unbalancedEntries,
            'draft_count'        => $draftCount,
            'draft_invoices'     => $draftInvoices,
            'overall_score'      => max(0, 100 - ($noPoCount * 5) - ($unbalancedCount * 10) - ($draftCount * 3)),
        ];
    }

    /**
     * Working capital — class-4 + class-5 balances at the as-of date
     * give current assets vs current liabilities, current ratio, cash
     * conversion cycle approximation.
     */
    public function workingCapital(Tenant $tenant): array
    {
        $tb = $this->trialBalance($tenant);
        // Bucket by account type/code prefix.
        $cashBalance        = $this->balanceForPrefixes($tb, ['521', '571']);
        $arBalance          = $this->balanceForPrefixes($tb, ['411']);
        $apBalance          = $this->balanceForPrefixes($tb, ['401']);   // negative-sign liability — flip
        $vatPayable         = $this->balanceForPrefixes($tb, ['443']);   // VAT collected (liability)
        $vatReceivable      = $this->balanceForPrefixes($tb, ['445']);
        $inventoryBalance   = $this->balanceForPrefixes($tb, ['31', '37']);

        $currentAssets      = $cashBalance + $arBalance + $vatReceivable + $inventoryBalance;
        $currentLiabilities = abs($apBalance) + abs($vatPayable);

        $currentRatio = $currentLiabilities > 0 ? $currentAssets / $currentLiabilities : 0;
        $quickRatio   = $currentLiabilities > 0 ? ($cashBalance + $arBalance) / $currentLiabilities : 0;

        // DSO + DPO approximation from facts already computed
        $rec = $this->receivables($tenant);
        $dso = (float) $rec['dso'];
        // DPO needs purchase data; we synthesise from opex journals (~30d default)
        $dpo = 30.0;
        $ccc = $dso - $dpo;

        return [
            'currency'            => 'XAF',
            'as_of'               => now()->format('Y-m-d'),
            'cash_balance'        => $cashBalance,
            'ar_balance'          => $arBalance,
            'ap_balance'          => abs($apBalance),
            'inventory_balance'   => $inventoryBalance,
            'vat_receivable'      => $vatReceivable,
            'vat_payable'         => abs($vatPayable),
            'current_assets'      => $currentAssets,
            'current_liabilities' => $currentLiabilities,
            'working_capital'     => $currentAssets - $currentLiabilities,
            'current_ratio'       => $currentRatio,
            'quick_ratio'         => $quickRatio,
            'dso'                 => $dso,
            'dpo'                 => $dpo,
            'cash_conversion_cycle' => $ccc,
        ];
    }

    /**
     * Billing analytics — invoice volume, time-to-bill,
     * average invoice value, payment-method mix.
     */
    public function billing(Tenant $tenant, int $days = 90): array
    {
        $now   = now();
        $start = $now->copy()->subDays($days)->startOfDay();

        $invoices = DemoInvoice::query()
            ->where('tenant_id', $tenant->id)
            ->whereBetween('invoice_date', [$start, $now])
            ->whereIn('status', ['posted', 'paid'])
            ->get();
        $payments = DemoPayment::query()
            ->where('tenant_id', $tenant->id)
            ->whereBetween('payment_date', [$start, $now])
            ->get();

        // Volume series — count of invoices per week
        $byWeek = $invoices->groupBy(fn ($i) => $i->invoice_date->startOfWeek()->format('Y-m-d'))
            ->map(fn ($g, $week) => [
                'week'         => $week,
                'count'        => $g->count(),
                'total_ht'     => (float) $g->sum('amount_ht'),
                'total_ttc'    => (float) $g->sum('total'),
            ])->values()->toArray();

        // Payment method mix
        $byMethod = $payments->groupBy('method')->map(fn ($g, $m) => [
            'method' => $m,
            'count'  => $g->count(),
            'amount' => (float) $g->sum('amount'),
        ])->values()->toArray();

        $avgInvoice = $invoices->count() > 0
            ? (float) $invoices->sum('amount_ht') / $invoices->count()
            : 0;

        return [
            'currency'           => 'XAF',
            'days'               => $days,
            'period_label'       => $start->format('d M') . ' → ' . $now->format('d M Y'),
            'total_invoices'     => $invoices->count(),
            'total_billed_ht'    => (float) $invoices->sum('amount_ht'),
            'total_billed_ttc'   => (float) $invoices->sum('total'),
            'total_collected'    => (float) $payments->sum('amount'),
            'avg_invoice'        => $avgInvoice,
            'by_week'            => $byWeek,
            'by_method'          => $byMethod,
        ];
    }

    /**
     * Customer insights — segment customers, identify top-N + at-risk.
     */
    public function customerInsights(Tenant $tenant): array
    {
        $customers = DemoCustomer::query()
            ->where('tenant_id', $tenant->id)
            ->withCount('invoices')
            ->get();

        $today = now()->startOfDay();
        $cards = $customers->map(function ($c) use ($today) {
            $invoices = DemoInvoice::with('payments')
                ->where('customer_id', $c->id)
                ->whereIn('status', ['posted', 'paid'])
                ->get();
            $totalBilled = (float) $invoices->sum('amount_ht');
            $totalPaid   = (float) $invoices->flatMap->payments->sum('amount');
            $totalOutstanding = $invoices->sum(fn ($i) => max(0, (float) $i->total - (float) $i->payments->sum('amount')));
            $overdueOutstanding = $invoices->sum(function ($i) use ($today) {
                $bal = (float) $i->total - (float) $i->payments->sum('amount');
                if ($bal <= 0.01) return 0;
                if (!$i->due_date->isPast()) return 0;
                return $bal;
            });
            return [
                'id'                 => $c->id,
                'name'               => $c->name,
                'category'           => $c->category,
                'payment_terms_days' => $c->payment_terms_days,
                'invoice_count'      => $c->invoices_count,
                'total_billed'       => $totalBilled,
                'total_paid'         => $totalPaid,
                'outstanding'        => (float) $totalOutstanding,
                'overdue'            => (float) $overdueOutstanding,
                'risk'               => $totalOutstanding > 0
                    ? ($overdueOutstanding > 0 ? 'at_risk' : 'healthy')
                    : 'paid_up',
            ];
        })->sortByDesc('total_billed')->values();

        // Category aggregation
        $byCategory = $cards->groupBy('category')->map(fn ($g, $cat) => [
            'category'         => $cat ?? '—',
            'customer_count'   => $g->count(),
            'total_billed'     => (float) $g->sum('total_billed'),
            'total_outstanding'=> (float) $g->sum('outstanding'),
            'total_overdue'    => (float) $g->sum('overdue'),
        ])->values()->toArray();

        return [
            'currency'        => 'XAF',
            'as_of'           => $today->format('Y-m-d'),
            'customer_count'  => $cards->count(),
            'at_risk_count'   => $cards->where('risk', 'at_risk')->count(),
            'healthy_count'   => $cards->where('risk', 'healthy')->count(),
            'paid_up_count'   => $cards->where('risk', 'paid_up')->count(),
            'customers'       => $cards->toArray(),
            'by_category'     => $byCategory,
        ];
    }

    /**
     * Tax return summary — sales VAT collected (443XXX) vs deductible
     * VAT (445XXX) for a chosen period. Drives a simple demo of the
     * monthly tax-return module.
     */
    public function taxReturn(Tenant $tenant, ?Carbon $monthStart = null): array
    {
        $monthStart = $monthStart ?? now()->startOfMonth();
        $monthEnd   = $monthStart->copy()->endOfMonth();

        $invoicesInMonth = DemoInvoice::where('tenant_id', $tenant->id)
            ->whereBetween('invoice_date', [$monthStart, $monthEnd])
            ->whereIn('status', ['posted', 'paid'])
            ->get(['amount_ht', 'vat_amount', 'total']);

        $vatCollected      = (float) $invoicesInMonth->sum('vat_amount');   // §1 — VAT collected on sales
        $caHt              = (float) $invoicesInMonth->sum('amount_ht');    // §15 — CA global HT
        // Deductible VAT: synthesise as ~12% of opex booked in the month
        $opex = $this->opexJournalSum($tenant, $monthStart, $monthEnd);
        $vatDeductible = round($opex * 0.1925, 0);
        $vatPayable    = max(0, $vatCollected - $vatDeductible);

        // §12 IRPP retenu — synthesise as ~20% of payroll lines in month
        $payrollMonth = DemoJournalLine::query()
            ->join('demo_journal_entries', 'demo_journal_entries.id', '=', 'demo_journal_lines.entry_id')
            ->where('demo_journal_entries.tenant_id', $tenant->id)
            ->whereBetween('demo_journal_entries.entry_date', [$monthStart, $monthEnd])
            ->whereIn('demo_journal_lines.account_code', ['641000', '661000'])
            ->sum('demo_journal_lines.debit');
        $irppRetenu = round($payrollMonth * 0.20, 0);

        $totalDue = $vatPayable + $irppRetenu;

        return [
            'currency'         => 'XAF',
            'month'            => $monthStart->format('Y-m'),
            'period_label'     => $monthStart->format('F Y'),
            'ca_ht'            => $caHt,
            'vat_collected'    => $vatCollected,
            'vat_deductible'   => $vatDeductible,
            'vat_payable'      => $vatPayable,
            'irpp_retenu'      => $irppRetenu,
            'total_due'        => $totalDue,
            'invoice_count'    => $invoicesInMonth->count(),
        ];
    }

    // ── Treasury / Payables / Procurement / Fixed Assets / Inventory ──

    /**
     * Treasury view — cash + bank balances, weekly cash trend (inflows
     * from customer payments minus outflows from supplier payments +
     * opex journal lines that hit 521/571).
     */
    public function treasury(Tenant $tenant, int $days = 90): array
    {
        $now   = now();
        $start = $now->copy()->subDays($days)->startOfDay();

        // Current cash position from trial balance
        $tb = $this->trialBalance($tenant);
        $cashTotal = 0.0; $cashByAccount = [];
        foreach ($tb['rows'] as $r) {
            if (str_starts_with((string) $r['code'], '52') || str_starts_with((string) $r['code'], '57')) {
                $cashTotal += (float) $r['balance'];
                $cashByAccount[] = [
                    'code'    => $r['code'],
                    'name'    => $r['name'],
                    'balance' => (float) $r['balance'],
                ];
            }
        }

        // Inflows in window (customer payments)
        $inflows = DemoPayment::query()
            ->where('tenant_id', $tenant->id)
            ->whereBetween('payment_date', [$start, $now])
            ->get();
        $inflowTotal = (float) $inflows->sum('amount');

        // Outflows in window (supplier payments + opex journal lines on 521/571)
        $outflowSupplier = (float) DemoSupplierPayment::query()
            ->where('tenant_id', $tenant->id)
            ->whereBetween('payment_date', [$start, $now])
            ->sum('amount');
        $outflowJournal = (float) DemoJournalLine::query()
            ->join('demo_journal_entries', 'demo_journal_entries.id', '=', 'demo_journal_lines.entry_id')
            ->where('demo_journal_entries.tenant_id', $tenant->id)
            ->where('demo_journal_entries.posted', true)
            ->whereBetween('demo_journal_entries.entry_date', [$start, $now])
            ->where(function ($q) {
                $q->where('demo_journal_lines.account_code', 'like', '521%')
                  ->orWhere('demo_journal_lines.account_code', 'like', '571%');
            })
            ->sum('demo_journal_lines.credit');
        $outflowTotal = $outflowSupplier + $outflowJournal;

        // Weekly trend — net cash movement per week
        $weeks = [];
        for ($w = 0; $w < (int) ceil($days / 7); $w++) {
            $wkStart = $start->copy()->addDays($w * 7);
            $wkEnd   = $wkStart->copy()->addDays(6);
            if ($wkStart->gt($now)) break;
            $inflowsW = (float) $inflows->filter(fn ($p) =>
                $p->payment_date->between($wkStart, $wkEnd)
            )->sum('amount');
            $outflowsW = (float) DemoSupplierPayment::query()
                ->where('tenant_id', $tenant->id)
                ->whereBetween('payment_date', [$wkStart, $wkEnd])
                ->sum('amount');
            $weeks[] = [
                'week_start' => $wkStart->format('Y-m-d'),
                'inflows'    => $inflowsW,
                'outflows'   => $outflowsW,
                'net'        => $inflowsW - $outflowsW,
            ];
        }

        return [
            'currency'         => 'XAF',
            'as_of'            => $now->format('Y-m-d'),
            'period_label'     => $start->format('d M') . ' → ' . $now->format('d M Y'),
            'days'             => $days,
            'cash_total'       => $cashTotal,
            'cash_by_account'  => $cashByAccount,
            'inflow_total'     => $inflowTotal,
            'outflow_total'    => $outflowTotal,
            'outflow_supplier' => $outflowSupplier,
            'outflow_journal'  => $outflowJournal,
            'net_movement'     => $inflowTotal - $outflowTotal,
            'weekly_trend'     => $weeks,
        ];
    }

    /**
     * Payables — open bills, aging buckets, DPO, top suppliers by balance.
     * Mirror of receivables() on the buy side.
     */
    public function payables(Tenant $tenant): array
    {
        $today = now()->startOfDay();
        $rows = DemoSupplierBill::with(['supplier', 'payments'])
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', ['posted', 'paid'])
            ->get()
            ->map(function ($bill) use ($today) {
                $balance = (float) $bill->total - (float) $bill->payments->sum('amount');
                $daysOverdue = $balance > 0.01 ? max(0, $today->diffInDays($bill->due_date, false) * -1) : 0;
                return [
                    'bill'         => $bill,
                    'balance'      => $balance,
                    'days_overdue' => $daysOverdue,
                    'is_open'      => $balance > 0.01,
                ];
            })
            ->filter(fn ($r) => $r['is_open']);

        $totalAp     = (float) $rows->sum('balance');
        $overdueAp   = (float) $rows->filter(fn ($r) => $r['days_overdue'] > 0)->sum('balance');
        $overdueRate = $totalAp > 0 ? ($overdueAp / $totalAp) * 100 : 0;

        $buckets = ['current' => 0, '1-30' => 0, '31-60' => 0, '61-90' => 0, '90+' => 0];
        foreach ($rows as $r) {
            $d = $r['days_overdue']; $b = (float) $r['balance'];
            if      ($d <= 0)  $buckets['current'] += $b;
            elseif  ($d <= 30) $buckets['1-30']    += $b;
            elseif  ($d <= 60) $buckets['31-60']   += $b;
            elseif  ($d <= 90) $buckets['61-90']   += $b;
            else               $buckets['90+']     += $b;
        }

        // DPO = (AP / total purchases last 90d) × 90
        $now = now();
        $purchases90 = (float) DemoSupplierBill::query()
            ->where('tenant_id', $tenant->id)
            ->whereBetween('bill_date', [$now->copy()->subDays(90), $now])
            ->whereIn('status', ['posted', 'paid'])
            ->sum('amount_ht');
        $dpo = $purchases90 > 0 ? ($totalAp / $purchases90) * 90 : 0;

        $bySupplier = $rows->groupBy(fn ($r) => $r['bill']->supplier->name ?? '—')
            ->map(fn ($g, $name) => [
                'supplier' => $name,
                'balance'  => (float) $g->sum('balance'),
                'bill_count' => $g->count(),
            ])
            ->sortByDesc('balance')
            ->values()
            ->toArray();

        return [
            'currency'       => 'XAF',
            'as_of'          => $today->format('Y-m-d'),
            'total_ap'       => $totalAp,
            'overdue_ap'     => $overdueAp,
            'overdue_rate'   => $overdueRate,
            'dpo'            => round($dpo, 1),
            'aging'          => $buckets,
            'aging_pct'      => array_map(fn ($v) => $totalAp > 0 ? ($v / $totalAp) * 100 : 0, $buckets),
            'open_bill_count'=> $rows->count(),
            'by_supplier'    => $bySupplier,
        ];
    }

    /**
     * Procurement — purchase volume, supplier mix by spend, PO compliance.
     */
    public function procurement(Tenant $tenant, int $days = 90): array
    {
        $now = now(); $start = $now->copy()->subDays($days)->startOfDay();

        $bills = DemoSupplierBill::with('supplier')
            ->where('tenant_id', $tenant->id)
            ->whereBetween('bill_date', [$start, $now])
            ->whereIn('status', ['posted', 'paid'])
            ->get();

        $totalSpend = (float) $bills->sum('amount_ht');
        $noPo       = $bills->where('has_po', false);

        $bySupplier = $bills->groupBy(fn ($b) => $b->supplier->name ?? '—')
            ->map(fn ($g, $name) => [
                'supplier' => $name,
                'spend'    => (float) $g->sum('amount_ht'),
                'bills'    => $g->count(),
                'share_pct'=> $totalSpend > 0 ? ((float) $g->sum('amount_ht') / $totalSpend) * 100 : 0,
            ])
            ->sortByDesc('spend')
            ->values()
            ->toArray();

        $byCategory = $bills->groupBy('category')
            ->map(fn ($g, $cat) => [
                'category' => $cat ?? '—',
                'spend'    => (float) $g->sum('amount_ht'),
                'bills'    => $g->count(),
            ])
            ->sortByDesc('spend')
            ->values()
            ->toArray();

        return [
            'currency'         => 'XAF',
            'days'             => $days,
            'period_label'     => $start->format('d M') . ' → ' . $now->format('d M Y'),
            'total_spend'      => $totalSpend,
            'bill_count'       => $bills->count(),
            'avg_bill'         => $bills->count() > 0 ? $totalSpend / $bills->count() : 0,
            'no_po_count'      => $noPo->count(),
            'no_po_spend'      => (float) $noPo->sum('amount_ht'),
            'no_po_pct'        => $bills->count() > 0 ? ($noPo->count() / $bills->count()) * 100 : 0,
            'by_supplier'      => $bySupplier,
            'by_category'      => $byCategory,
            'top5_supplier_pct'=> $totalSpend > 0 ? (collect($bySupplier)->take(5)->sum('spend') / $totalSpend) * 100 : 0,
        ];
    }

    /** Fixed Assets register + accumulated depreciation + NBV at the as-of date. */
    public function fixedAssets(Tenant $tenant): array
    {
        $today = now()->startOfDay();
        $assets = DemoFixedAsset::where('tenant_id', $tenant->id)->orderBy('asset_code')->get();

        $rows = $assets->map(function ($a) use ($today) {
            return [
                'asset_code'           => $a->asset_code,
                'name'                 => $a->name,
                'category'             => $a->category,
                'account_code'         => $a->account_code,
                'acquisition_date'     => $a->acquisition_date->format('Y-m-d'),
                'acquisition_cost'     => (float) $a->acquisition_cost,
                'useful_life_months'   => (int) $a->useful_life_months,
                'monthly_dep'          => $a->monthlyDepreciation(),
                'months_depreciated'   => $a->monthsDepreciated($today),
                'accumulated_dep'      => $a->accumulatedDepreciation($today),
                'net_book_value'       => $a->netBookValue($today),
                'pct_depreciated'      => $a->useful_life_months > 0
                    ? ($a->monthsDepreciated($today) / $a->useful_life_months) * 100
                    : 0,
            ];
        })->toArray();

        $totalCost   = array_sum(array_column($rows, 'acquisition_cost'));
        $totalAccDep = array_sum(array_column($rows, 'accumulated_dep'));
        $totalNbv    = array_sum(array_column($rows, 'net_book_value'));

        $byCategory = collect($rows)->groupBy('category')->map(fn ($g, $cat) => [
            'category'        => $cat,
            'count'           => $g->count(),
            'acquisition_cost'=> (float) $g->sum('acquisition_cost'),
            'accumulated_dep' => (float) $g->sum('accumulated_dep'),
            'net_book_value'  => (float) $g->sum('net_book_value'),
        ])->values()->toArray();

        return [
            'currency'        => 'XAF',
            'as_of'           => $today->format('Y-m-d'),
            'rows'            => $rows,
            'asset_count'     => count($rows),
            'total_cost'      => $totalCost,
            'total_acc_dep'   => $totalAccDep,
            'total_nbv'       => $totalNbv,
            'monthly_dep_run' => (float) collect($rows)->sum('monthly_dep'),
            'by_category'     => $byCategory,
        ];
    }

    /**
     * Inventory — derived from product master + invoice lines (units sold)
     * + an assumed opening stock per product. Synthesises: units sold, est.
     * current stock, turnover (units sold / avg stock), stock value at cost.
     */
    public function inventory(Tenant $tenant): array
    {
        $now = now();
        $start90 = $now->copy()->subDays(90);

        $products = DemoProduct::where('tenant_id', $tenant->id)->orderBy('name')->get();

        // Units sold per product in trailing 90 days
        $unitsSold = DemoInvoiceLine::query()
            ->join('demo_invoices', 'demo_invoices.id', '=', 'demo_invoice_lines.invoice_id')
            ->where('demo_invoices.tenant_id', $tenant->id)
            ->whereBetween('demo_invoices.invoice_date', [$start90, $now])
            ->whereIn('demo_invoices.status', ['posted', 'paid'])
            ->whereNotNull('demo_invoice_lines.product_id')
            ->groupBy('demo_invoice_lines.product_id')
            ->get([
                'demo_invoice_lines.product_id',
                \DB::raw('SUM(demo_invoice_lines.qty) as units_sold'),
                \DB::raw('SUM(demo_invoice_lines.subtotal) as revenue'),
                \DB::raw('SUM(demo_invoice_lines.cogs_amount) as cogs'),
            ])
            ->keyBy('product_id');

        // Assume opening stock = 2.5× monthly sell-through (covers ~75 days)
        $rows = $products->map(function ($p) use ($unitsSold) {
            $sold = (float) ($unitsSold[$p->id]->units_sold ?? 0);
            $monthly = $sold / 3.0;
            $assumedOpening = $monthly * 2.5;
            $currentStock = max(0, $assumedOpening - ($sold * 0.5));  // half consumed
            $avgStock = ($assumedOpening + $currentStock) / 2 ?: 1;
            $turnover = $avgStock > 0 ? $sold / $avgStock : 0;
            return [
                'product'        => $p->name,
                'category'       => $p->category,
                'unit_price'     => (float) $p->unit_price,
                'default_cogs'   => (float) $p->default_cogs,
                'units_sold_90d' => $sold,
                'units_in_stock' => round($currentStock, 0),
                'stock_value'    => round($currentStock * (float) $p->default_cogs, 0),
                'turnover_ratio' => round($turnover, 2),
                'days_of_cover'  => $sold > 0 ? round(($currentStock / $sold) * 90, 0) : 0,
            ];
        })->toArray();

        $totalStockValue = array_sum(array_column($rows, 'stock_value'));
        $totalUnitsInStock = array_sum(array_column($rows, 'units_in_stock'));
        $totalUnitsSold = array_sum(array_column($rows, 'units_sold_90d'));

        return [
            'currency'             => 'XAF',
            'as_of'                => $now->format('Y-m-d'),
            'rows'                 => $rows,
            'product_count'        => count($rows),
            'total_units_in_stock' => $totalUnitsInStock,
            'total_stock_value'    => $totalStockValue,
            'total_units_sold_90d' => $totalUnitsSold,
            'avg_turnover'         => count($rows) > 0
                ? (float) collect($rows)->avg('turnover_ratio')
                : 0,
        ];
    }

    /** Sum trial-balance row balances for accounts whose code starts with any of $prefixes. */
    private function balanceForPrefixes(array $tb, array $prefixes): float
    {
        $sum = 0.0;
        foreach ($tb['rows'] as $r) {
            foreach ($prefixes as $p) {
                if (str_starts_with((string) $r['code'], $p)) {
                    $sum += (float) $r['balance'];
                    break;
                }
            }
        }
        return $sum;
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
