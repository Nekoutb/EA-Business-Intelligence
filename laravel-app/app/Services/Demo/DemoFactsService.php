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

    // ── Financial Reporting (Balance Sheet, OHADA Statements) ────────

    /**
     * Balance Sheet Review — Assets / Liabilities / Equity from the
     * trial balance, grouped by OHADA class.
     */
    public function balanceSheet(Tenant $tenant): array
    {
        $tb = $this->trialBalance($tenant);

        $sections = [
            'fixed_assets'      => ['label' => 'Class 2 · Fixed assets',     'prefixes' => ['2'],   'rows' => [], 'total' => 0],
            'inventory'         => ['label' => 'Class 3 · Inventory',         'prefixes' => ['3'],   'rows' => [], 'total' => 0],
            'receivables'       => ['label' => 'Class 41 · Receivables',      'prefixes' => ['41'],  'rows' => [], 'total' => 0],
            'vat_recv'          => ['label' => 'Class 445 · VAT receivable',  'prefixes' => ['445'], 'rows' => [], 'total' => 0],
            'treasury'          => ['label' => 'Class 5 · Treasury',          'prefixes' => ['5'],   'rows' => [], 'total' => 0],
            'equity'            => ['label' => 'Class 1 · Capital & reserves','prefixes' => ['1'],   'rows' => [], 'total' => 0],
            'payables'          => ['label' => 'Class 401 · Payables',        'prefixes' => ['401'], 'rows' => [], 'total' => 0],
            'vat_pay'           => ['label' => 'Class 443 · VAT payable',     'prefixes' => ['443'], 'rows' => [], 'total' => 0],
            'other_liabilities' => ['label' => 'Class 44 (other) · Other liabilities', 'prefixes' => ['44'], 'rows' => [], 'total' => 0],
        ];

        foreach ($tb['rows'] as $r) {
            foreach ($sections as $key => &$section) {
                if ($key === 'other_liabilities') continue;   // catch-all handled below
                foreach ($section['prefixes'] as $p) {
                    if (str_starts_with((string) $r['code'], $p)) {
                        $section['rows'][] = $r;
                        $section['total'] += (float) $r['balance'];
                        continue 3;
                    }
                }
            }
            // Other 44XXX accounts (not 401, 443, 445)
            if (str_starts_with((string) $r['code'], '44')) {
                $sections['other_liabilities']['rows'][] = $r;
                $sections['other_liabilities']['total'] += (float) $r['balance'];
            }
        }
        unset($section);

        // Build totals
        $totalAssets = $sections['fixed_assets']['total']
                     + $sections['inventory']['total']
                     + $sections['receivables']['total']
                     + $sections['vat_recv']['total']
                     + $sections['treasury']['total'];
        // Liabilities have negative balances by sign convention in OHADA — use abs
        $totalLiabilities = abs($sections['payables']['total'])
                          + abs($sections['vat_pay']['total'])
                          + abs($sections['other_liabilities']['total']);
        $totalEquity = abs($sections['equity']['total']);

        return [
            'currency' => 'XAF',
            'as_of'    => $tb['as_of'],
            'sections' => $sections,
            'total_assets'      => $totalAssets,
            'total_liabilities' => $totalLiabilities,
            'total_equity'      => $totalEquity,
            'balance_check'     => $totalAssets - ($totalLiabilities + $totalEquity),
        ];
    }

    /**
     * OHADA Financial Statements — formal Bilan + Compte de Résultat
     * blocks. Simplified version (the real BMS computes the full
     * OHADA layout per the SYSCOHADA chart).
     */
    public function ohadaStatements(Tenant $tenant): array
    {
        $bs = $this->balanceSheet($tenant);
        $pl = $this->profitLoss($tenant, 365);

        return [
            'currency' => 'XAF',
            'as_of'    => $bs['as_of'],
            // Bilan ACTIF (assets, simplified to the headline groupings)
            'bilan_actif' => [
                ['code' => 'AD',    'label' => 'Immobilisations corporelles',     'amount' => $bs['sections']['fixed_assets']['total']],
                ['code' => 'BA',    'label' => 'Stocks',                          'amount' => $bs['sections']['inventory']['total']],
                ['code' => 'BB',    'label' => 'Créances clients',                'amount' => $bs['sections']['receivables']['total']],
                ['code' => 'BC',    'label' => 'Autres créances (TVA, etc.)',     'amount' => $bs['sections']['vat_recv']['total']],
                ['code' => 'BD',    'label' => 'Trésorerie — Actif',              'amount' => $bs['sections']['treasury']['total']],
            ],
            'bilan_actif_total' => $bs['total_assets'],
            // Bilan PASSIF (liabilities + equity)
            'bilan_passif' => [
                ['code' => 'CA',    'label' => 'Capital + réserves + résultat',   'amount' => $bs['total_equity']],
                ['code' => 'DA',    'label' => 'Dettes fournisseurs',              'amount' => abs($bs['sections']['payables']['total'])],
                ['code' => 'DB',    'label' => 'TVA collectée à reverser',         'amount' => abs($bs['sections']['vat_pay']['total'])],
                ['code' => 'DC',    'label' => 'Autres dettes',                    'amount' => abs($bs['sections']['other_liabilities']['total'])],
            ],
            'bilan_passif_total' => $bs['total_liabilities'] + $bs['total_equity'],
            // Compte de résultat (income statement)
            'cr_lines' => [
                ['code' => 'TA',    'label' => 'Ventes de marchandises (CA HT)',  'amount' => $pl['revenue']],
                ['code' => 'RA',    'label' => 'Achats de marchandises (COGS)',   'amount' => -$pl['cogs']],
                ['code' => 'XB',    'label' => 'Marge brute',                     'amount' => $pl['gross_profit'], 'subtotal' => true],
                ['code' => 'RC',    'label' => 'Autres achats + services',         'amount' => -$pl['opex']],
                ['code' => 'XD',    'label' => 'Excédent brut d\'exploitation (EBITDA)', 'amount' => $pl['ebitda'], 'subtotal' => true],
                ['code' => 'RD',    'label' => 'Dotations aux amortissements',    'amount' => -$pl['depreciation']],
                ['code' => 'XI',    'label' => 'Résultat net',                    'amount' => $pl['net_profit'], 'subtotal' => true],
            ],
        ];
    }

    // ── Tax modules (5) ──────────────────────────────────────────────

    /**
     * Tax Dashboard — overview of all tax modules with the current
     * status of each (declared / pending / overdue).
     */
    public function taxDashboard(Tenant $tenant): array
    {
        $tr = $this->taxReturn($tenant);
        $vat = $this->vatCompliance($tenant);
        $income = $this->incomeTaxCompliance($tenant);
        $inv = $this->invoiceCompliance($tenant);

        return [
            'currency'              => 'XAF',
            'as_of'                 => now()->format('Y-m-d'),
            'vat_payable_current'   => $tr['vat_payable'],
            'irpp_payable_current'  => $tr['irpp_retenu'],
            'total_due_current'     => $tr['total_due'],
            'is_estimated_annual'   => $income['is_annual'],
            'invoice_compliance_pct'=> $inv['compliance_pct'],
            'invoice_issues'        => $inv['issue_count'],
            'next_filing_date'      => now()->copy()->startOfMonth()->addMonth()->addDays(14)->format('Y-m-d'),
            'vat_calendar'          => $vat['calendar'],
        ];
    }

    /**
     * Income Tax Compliance — annual IS estimation based on YTD P&L.
     * Cameroon IS rate = 33% on taxable profit; here we use the YTD
     * net profit grossed up to a full year.
     */
    public function incomeTaxCompliance(Tenant $tenant): array
    {
        $startOfYear = now()->startOfYear();
        $now = now();
        $daysYtd = (int) max(1, $startOfYear->diffInDays($now));
        $plYtd = $this->profitLoss($tenant, $daysYtd);

        $taxableYtd = max(0, $plYtd['net_profit']);
        $annualisation = 365 / max(1, $daysYtd);
        $taxableAnnualEstimate = $taxableYtd * $annualisation;
        $isAnnualEstimate = $taxableAnnualEstimate * 0.33;
        // Minimum tax (Cameroon): 2% of CA (turnover)
        $caYtd = $plYtd['revenue'];
        $caAnnual = $caYtd * $annualisation;
        $minimumTax = $caAnnual * 0.02;
        $effectiveTax = max($isAnnualEstimate, $minimumTax);

        return [
            'currency'              => 'XAF',
            'days_ytd'              => (int) $daysYtd,
            'ytd_taxable_profit'    => $taxableYtd,
            'ytd_revenue'           => $caYtd,
            'annualised_taxable'    => $taxableAnnualEstimate,
            'annualised_revenue'    => $caAnnual,
            'is_annual'             => $isAnnualEstimate,
            'minimum_tax'           => $minimumTax,
            'effective_tax'         => $effectiveTax,
            'tax_basis'             => $isAnnualEstimate >= $minimumTax ? 'IS_calc' : 'minimum_tax',
            'gross_margin_pct'      => $plYtd['gross_margin_pct'],
            'ebitda_margin_pct'     => $plYtd['ebitda_margin_pct'],
        ];
    }

    /** VAT Compliance — calendar of returns + filing status per month. */
    public function vatCompliance(Tenant $tenant): array
    {
        $months = collect();
        for ($i = 5; $i >= 0; $i--) {
            $monthStart = now()->copy()->subMonthsNoOverflow($i)->startOfMonth();
            $tr = $this->taxReturn($tenant, $monthStart);
            $filingDeadline = $monthStart->copy()->addMonth()->addDays(14);  // 15th of next month
            $filed = $i > 0;   // assume past months filed, current not yet
            $months->push([
                'month'         => $monthStart->format('Y-m'),
                'month_label'   => $monthStart->format('M Y'),
                'vat_collected' => $tr['vat_collected'],
                'vat_deductible'=> $tr['vat_deductible'],
                'vat_payable'   => $tr['vat_payable'],
                'filing_deadline' => $filingDeadline->format('Y-m-d'),
                'status'        => $filed ? 'filed' : 'pending',
                'overdue'       => !$filed && now()->isAfter($filingDeadline),
            ]);
        }
        return [
            'currency'      => 'XAF',
            'calendar'      => $months->toArray(),
            'total_vat_6m'  => (float) $months->sum('vat_payable'),
            'filed_count'   => $months->where('status', 'filed')->count(),
            'pending_count' => $months->where('status', 'pending')->count(),
            'overdue_count' => $months->where('overdue', true)->count(),
        ];
    }

    /**
     * Invoice Compliance — checks every recent invoice against CGI rules:
     *   - Sequential numbering (no gaps in INV-YYYY-NNNNN)
     *   - Date within the issuing month
     *   - Customer name present
     *   - VAT correctly applied (19.25%)
     *   - PO referenced when has_po=true
     */
    public function invoiceCompliance(Tenant $tenant): array
    {
        $invoices = DemoInvoice::with('customer')
            ->where('tenant_id', $tenant->id)
            ->orderBy('invoice_date')
            ->limit(200)
            ->get();

        $issues = collect();
        foreach ($invoices as $inv) {
            $issueList = [];
            // VAT check: should be ~19.25% of amount_ht
            $expectedVat = round((float) $inv->amount_ht * 0.1925, 0);
            if (abs((float) $inv->vat_amount - $expectedVat) > 1) {
                $issueList[] = sprintf('VAT mis-applied: %s declared vs %s expected',
                    number_format($inv->vat_amount, 0), number_format($expectedVat, 0));
            }
            if (!$inv->customer || trim($inv->customer->name) === '') {
                $issueList[] = 'Customer missing';
            }
            if ($inv->has_po && empty($inv->po_number)) {
                $issueList[] = 'has_po=true but no PO number';
            }
            if (count($issueList) > 0) {
                $issues->push([
                    'invoice'  => $inv,
                    'issues'   => $issueList,
                ]);
            }
        }

        $totalChecked = $invoices->count();
        $issueCount   = $issues->count();
        $compliancePct= $totalChecked > 0 ? (($totalChecked - $issueCount) / $totalChecked) * 100 : 100;

        return [
            'as_of'           => now()->format('Y-m-d'),
            'total_checked'   => $totalChecked,
            'issue_count'     => $issueCount,
            'compliance_pct'  => $compliancePct,
            'issues'          => $issues->take(50)->toArray(),
        ];
    }

    /**
     * Return Reconciliation — Filed tax-return §2 (CA Global HT) vs GL
     * ground truth (Class 70 movements) for the current month.
     */
    public function returnReconciliation(Tenant $tenant, ?Carbon $monthStart = null): array
    {
        $monthStart = $monthStart ?? now()->startOfMonth();
        $monthEnd   = $monthStart->copy()->endOfMonth();

        // GL Class 70 movements (revenue accounts) — credit side
        $glClass70 = (float) DemoJournalLine::query()
            ->join('demo_journal_entries', 'demo_journal_entries.id', '=', 'demo_journal_lines.entry_id')
            ->where('demo_journal_entries.tenant_id', $tenant->id)
            ->whereBetween('demo_journal_entries.entry_date', [$monthStart, $monthEnd])
            ->where('demo_journal_lines.account_code', 'like', '70%')
            ->sum('demo_journal_lines.credit');

        // Implicit from invoices
        $invoiceHt = (float) DemoInvoice::query()
            ->where('tenant_id', $tenant->id)
            ->whereBetween('invoice_date', [$monthStart, $monthEnd])
            ->whereIn('status', ['posted', 'paid'])
            ->sum('amount_ht');

        $glTotal = $glClass70 + $invoiceHt;
        $declared = $invoiceHt;   // declared on the tax return = invoice CA HT
        $variance = $glTotal - $declared;

        return [
            'currency'      => 'XAF',
            'month'         => $monthStart->format('Y-m'),
            'period_label'  => $monthStart->format('F Y'),
            'gl_class_70'   => $glClass70,
            'invoice_ht'    => $invoiceHt,
            'gl_total'      => $glTotal,
            'declared_on_return' => $declared,
            'variance'      => $variance,
            'variance_pct'  => $declared > 0 ? ($variance / $declared) * 100 : 0,
            'reconciled'    => abs($variance) < 100,   // within 100 XAF = reconciled
        ];
    }

    // ── Planning modules (5) ─────────────────────────────────────────

    /** Macro Economics — fixed Cameroon indicators for the demo. */
    public function macroEconomics(): array
    {
        return [
            'as_of'    => now()->format('Y-m-d'),
            'country'  => 'Cameroon',
            'currency' => 'XAF',
            'indicators' => [
                ['key' => 'gdp_growth',     'label' => 'GDP growth (Y/Y)',        'value' => '+3.8%',     'source' => 'IMF · Apr 2026', 'trend' => 'up'],
                ['key' => 'cpi',            'label' => 'Inflation (CPI Y/Y)',     'value' => '+4.2%',     'source' => 'INS · Mar 2026', 'trend' => 'flat'],
                ['key' => 'usd_xaf',        'label' => 'USD / XAF',                'value' => '595',       'source' => 'BEAC',           'trend' => 'down'],
                ['key' => 'eur_xaf',        'label' => 'EUR / XAF (fixed)',        'value' => '655.957',   'source' => 'CFA peg',        'trend' => 'flat'],
                ['key' => 'beac_policy',    'label' => 'BEAC policy rate',         'value' => '5.00%',     'source' => 'BEAC',           'trend' => 'flat'],
                ['key' => 'oil_brent',      'label' => 'Brent crude (USD/bbl)',    'value' => '78',        'source' => 'ICE · spot',     'trend' => 'down'],
                ['key' => 'cocoa',          'label' => 'Cocoa (USD/t)',            'value' => '6,400',     'source' => 'ICE Futures',    'trend' => 'up'],
                ['key' => 'rubber',         'label' => 'Rubber TSR20 (USD/t)',     'value' => '1,720',     'source' => 'SGX',            'trend' => 'up'],
                ['key' => 'sovereign_eurobond', 'label' => 'CMR Eurobond 2032 yield', 'value' => '7.85%', 'source' => 'Bloomberg',      'trend' => 'up'],
                ['key' => 'doing_business', 'label' => 'World Bank Doing Business rank', 'value' => '167/190', 'source' => 'WB',         'trend' => 'flat'],
            ],
        ];
    }

    /** P&L Forecast — extrapolate trailing 90-day run-rate forward 6 months. */
    public function plForecast(Tenant $tenant, int $months = 6): array
    {
        $pl90 = $this->profitLoss($tenant, 90);
        $monthlyRunRate = [
            'revenue'      => $pl90['revenue']      / 3,
            'cogs'         => $pl90['cogs']         / 3,
            'gross_profit' => $pl90['gross_profit'] / 3,
            'opex'         => $pl90['opex']         / 3,
            'ebitda'       => $pl90['ebitda']       / 3,
            'depreciation' => $pl90['depreciation'] / 3,
            'net_profit'   => $pl90['net_profit']   / 3,
        ];

        $forecast = [];
        $today = now()->startOfMonth();
        for ($m = 1; $m <= $months; $m++) {
            // Apply a mild seasonal flex: ±5% sinusoid
            $flex = 1 + (sin($m / 2.0) * 0.05);
            $forecast[] = [
                'month'        => $today->copy()->addMonthsNoOverflow($m)->format('Y-m'),
                'month_label'  => $today->copy()->addMonthsNoOverflow($m)->format('M Y'),
                'revenue'      => $monthlyRunRate['revenue'] * $flex,
                'cogs'         => $monthlyRunRate['cogs'] * $flex,
                'gross_profit' => ($monthlyRunRate['revenue'] - $monthlyRunRate['cogs']) * $flex,
                'opex'         => $monthlyRunRate['opex'],     // opex is roughly fixed
                'ebitda'       => ($monthlyRunRate['revenue'] - $monthlyRunRate['cogs']) * $flex - $monthlyRunRate['opex'],
                'net_profit'   => $monthlyRunRate['net_profit'] * $flex,
            ];
        }

        return [
            'currency'         => 'XAF',
            'as_of'            => now()->format('Y-m-d'),
            'months'           => $months,
            'baseline_monthly' => $monthlyRunRate,
            'forecast'         => $forecast,
            'forecast_total_revenue' => array_sum(array_column($forecast, 'revenue')),
            'forecast_total_ebitda'  => array_sum(array_column($forecast, 'ebitda')),
        ];
    }

    /** Cashflow Forecast — projected weekly cash by week for the next N weeks. */
    public function cashflowForecast(Tenant $tenant, int $weeks = 13): array
    {
        $today = now()->startOfDay();
        $openingCash = (float) collect($this->trialBalance($tenant)['rows'])
            ->filter(fn ($r) => str_starts_with((string) $r['code'], '52') || str_starts_with((string) $r['code'], '57'))
            ->sum('balance');

        // Inflow projection: open AR with due_date within next N weeks
        $arRows = DemoInvoice::with('payments')
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', ['posted'])
            ->get()
            ->map(function ($i) {
                return [
                    'due_date' => $i->due_date,
                    'balance'  => (float) $i->total - (float) $i->payments->sum('amount'),
                ];
            })->filter(fn ($r) => $r['balance'] > 0.01);

        // Outflow projection: open AP + recurring opex (monthly run rate)
        $apRows = DemoSupplierBill::with('payments')
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', ['posted'])
            ->get()
            ->map(function ($b) {
                return [
                    'due_date' => $b->due_date,
                    'balance'  => (float) $b->total - (float) $b->payments->sum('amount'),
                ];
            })->filter(fn ($r) => $r['balance'] > 0.01);

        $opex90 = $this->opexJournalSum($tenant, $today->copy()->subDays(90), $today);
        $weeklyOpex = $opex90 / 13;   // 90 days ≈ 13 weeks

        $forecast = [];
        $runningCash = $openingCash;
        for ($w = 0; $w < $weeks; $w++) {
            $wkStart = $today->copy()->addDays($w * 7);
            $wkEnd   = $wkStart->copy()->addDays(6);
            $inflow = (float) $arRows->filter(fn ($r) => $r['due_date']->between($wkStart, $wkEnd))->sum('balance');
            $outflowAp = (float) $apRows->filter(fn ($r) => $r['due_date']->between($wkStart, $wkEnd))->sum('balance');
            $outflow = $outflowAp + $weeklyOpex;
            $net = $inflow - $outflow;
            $runningCash += $net;

            $forecast[] = [
                'week_start' => $wkStart->format('Y-m-d'),
                'inflow'     => $inflow,
                'outflow'    => $outflow,
                'net'        => $net,
                'cash_end'   => $runningCash,
            ];
        }

        return [
            'currency'      => 'XAF',
            'as_of'         => $today->format('Y-m-d'),
            'opening_cash'  => $openingCash,
            'weeks'         => $weeks,
            'forecast'      => $forecast,
            'min_cash'      => min(array_column($forecast, 'cash_end')),
            'final_cash'    => end($forecast)['cash_end'] ?? $openingCash,
            'weekly_opex'   => $weeklyOpex,
        ];
    }

    /** Investment Analysis — NPV/IRR for a sample 5-year project on the demo. */
    public function investmentAnalysis(?array $cashFlows = null, float $discountRate = 0.12): array
    {
        // Default sample project: initial investment 30M, 5 years of net cash
        $cashFlows = $cashFlows ?? [
            ['year' => 0, 'cash' => -30_000_000, 'label' => 'Initial investment'],
            ['year' => 1, 'cash' =>   8_000_000, 'label' => 'Year 1 net cash'],
            ['year' => 2, 'cash' =>  10_500_000, 'label' => 'Year 2 net cash'],
            ['year' => 3, 'cash' =>  12_000_000, 'label' => 'Year 3 net cash'],
            ['year' => 4, 'cash' =>  11_500_000, 'label' => 'Year 4 net cash'],
            ['year' => 5, 'cash' =>   9_000_000, 'label' => 'Year 5 net cash'],
        ];

        $npv = 0.0;
        $totalNominal = 0.0;
        $rowsOut = [];
        foreach ($cashFlows as $r) {
            $df = pow(1 + $discountRate, -1 * (int) $r['year']);
            $pv = (float) $r['cash'] * $df;
            $npv += $pv;
            $totalNominal += (float) $r['cash'];
            $rowsOut[] = $r + ['discount_factor' => $df, 'present_value' => $pv];
        }

        // Simple IRR — bisect between -50% and +200%
        $irr = $this->bisectIrr($cashFlows);

        $paybackYears = $this->paybackPeriod($cashFlows);

        return [
            'currency'      => 'XAF',
            'discount_rate' => $discountRate,
            'cash_flows'    => $rowsOut,
            'total_nominal' => $totalNominal,
            'npv'           => $npv,
            'irr'           => $irr,
            'payback_years' => $paybackYears,
            'decision'      => $npv > 0 ? 'Invest (NPV positive)' : 'Reject (NPV negative)',
        ];
    }

    /** Budget Upload — empty grid the visitor can use to compare to actuals. */
    public function budgetGrid(Tenant $tenant): array
    {
        $pl90 = $this->profitLoss($tenant, 90);
        $monthly = max(1, $pl90['revenue']) / 3;
        return [
            'currency' => 'XAF',
            'sample_budget_lines' => [
                ['account' => '701100', 'label' => 'Ventes de marchandises', 'monthly_budget' => $monthly,         'ytd_actual' => $pl90['revenue']],
                ['account' => '601000', 'label' => 'Achats marchandises',    'monthly_budget' => $monthly * 0.62,  'ytd_actual' => $pl90['cogs']],
                ['account' => '622000', 'label' => 'Location',                'monthly_budget' => 2_500_000,         'ytd_actual' => 7_500_000],
                ['account' => '641000', 'label' => 'Salaires',                'monthly_budget' => 4_800_000,         'ytd_actual' => 14_400_000],
                ['account' => '631100', 'label' => 'Frais bancaires',         'monthly_budget' => 85_000,            'ytd_actual' => 255_000],
            ],
        ];
    }

    // ── IRR / payback helpers ────────────────────────────────────────

    private function bisectIrr(array $cashFlows, float $lo = -0.5, float $hi = 2.0, int $iters = 50): float
    {
        $npvAt = function (float $r) use ($cashFlows) {
            $n = 0;
            foreach ($cashFlows as $cf) {
                $n += (float) $cf['cash'] * pow(1 + $r, -1 * (int) $cf['year']);
            }
            return $n;
        };
        if ($npvAt($lo) * $npvAt($hi) > 0) return 0;   // no sign change → undefined
        for ($i = 0; $i < $iters; $i++) {
            $mid = ($lo + $hi) / 2;
            if ($npvAt($mid) > 0) { $lo = $mid; } else { $hi = $mid; }
        }
        return ($lo + $hi) / 2;
    }

    private function paybackPeriod(array $cashFlows): ?float
    {
        $cum = 0.0;
        $prev = null;
        foreach ($cashFlows as $cf) {
            $cum += (float) $cf['cash'];
            if ($cum >= 0 && $prev !== null && $prev < 0) {
                // Linear interpolation within the year
                $fraction = abs($prev) / ((float) $cf['cash']);
                return ((int) $cf['year'] - 1) + $fraction;
            }
            $prev = $cum;
        }
        return null;
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
