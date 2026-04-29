@extends('admin.layout')
@section('title', 'Methodology')

@section('header')
    <h1 class="text-2xl font-bold text-slate-900">Module Methodology</h1>
    <p class="text-xs text-slate-500 mt-1">
        Per-module data sources, formulas and design rationale.
        <span class="ml-1 inline-flex items-center text-[10px] font-semibold uppercase tracking-wider px-1.5 py-0.5 rounded bg-rose-100 text-rose-700 border border-rose-200">Internal — not shown to clients</span>
    </p>
@endsection

@section('content')

<div class="panel p-4 mb-5 border-l-4 border-amber-400">
    <div class="flex items-start gap-3">
        <svg class="w-5 h-5 text-amber-600 shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
        <div class="text-xs text-slate-700 leading-relaxed">
            <strong>Confidential — for internal eyes only.</strong> The notes below describe the proprietary methodology behind each KPI: which Odoo models we query, which fields we anchor on, the joins and aggregations we apply, and the trade-offs we made along the way. This is platform IP. It used to be visible at the bottom of each client-facing module page; that exposure has been removed and the content lives here exclusively. If a client needs an explanation of a metric, hand-craft a sanitised summary — do not paste from this page.
        </div>
    </div>
</div>

{{-- Quick anchors --}}
<div class="panel p-3 mb-5 flex gap-2 flex-wrap">
    <span class="text-[10px] uppercase tracking-wider text-slate-500 font-medium px-2 py-1">Jump to</span>
    <a href="#operations"  class="text-xs px-3 py-1 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-700 transition">Operations</a>
    <a href="#billing"     class="text-xs px-3 py-1 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-700 transition">Billing</a>
    <a href="#procurement" class="text-xs px-3 py-1 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-700 transition">Procurement</a>
</div>

{{-- ────────────────────────────────────────────────── Operations ── --}}
<section id="operations" class="panel p-6 mb-5 scroll-mt-6">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h2 class="text-base font-bold text-slate-900">Operations</h2>
            <p class="text-[11px] text-slate-500 mt-0.5">Container &amp; logistics analytics — sourced from the customer's Odoo Logistique module.</p>
        </div>
        <span class="text-[10px] uppercase tracking-wider text-slate-400 font-medium">/operations</span>
    </div>
    <div class="text-xs text-slate-700 leading-relaxed space-y-3">
        <div>
            <span class="font-semibold text-slate-900">Models in use</span> —
            <code class="text-[11px] bg-slate-50 border border-slate-200 rounded px-1.5 py-0.5">aw_cargo.stopover</code> (vessel calls),
            <code class="text-[11px] bg-slate-50 border border-slate-200 rounded px-1.5 py-0.5">aw_cargo.cargo</code> (bills of lading),
            <code class="text-[11px] bg-slate-50 border border-slate-200 rounded px-1.5 py-0.5">aw_cargo.equipment</code> (containers),
            <code class="text-[11px] bg-slate-50 border border-slate-200 rounded px-1.5 py-0.5">aw_vehicule.vehicule</code> (vehicles, classified via <code>cylinders</code>),
            <code class="text-[11px] bg-slate-50 border border-slate-200 rounded px-1.5 py-0.5">aw_bois.bois</code> (wood line items),
            <code class="text-[11px] bg-slate-50 border border-slate-200 rounded px-1.5 py-0.5">aw_cargo_invoice.product</code> (size↔product billing legend).
        </div>
        <div>
            <span class="font-semibold text-slate-900">Period anchor</span> — every KPI filters by
            <code class="text-[11px] bg-slate-50 border border-slate-200 rounded px-1.5 py-0.5">create_date</code>
            because <code class="text-[11px] bg-slate-50 border border-slate-200 rounded px-1.5 py-0.5">eta</code> is sparsely populated. Containers Scanned uses
            <code class="text-[11px] bg-slate-50 border border-slate-200 rounded px-1.5 py-0.5">scanned_date</code>.
        </div>
        <div>
            <span class="font-semibold text-slate-900">Working-day basis</span> — Mon–Sat (consistent with the Dashboard's RpD &amp; Run-Rate).
        </div>
        <div>
            <span class="font-semibold text-slate-900">Vehicle classification</span> —
            <code class="text-[11px] bg-slate-50 border border-slate-200 rounded px-1.5 py-0.5">aw_vehicule.vehicule.cylinders</code> mirrors the
            <code class="text-[11px] bg-slate-50 border border-slate-200 rounded px-1.5 py-0.5">size</code> codes in
            <code class="text-[11px] bg-slate-50 border border-slate-200 rounded px-1.5 py-0.5">aw_cargo_invoice.product</code>:
            cylinders ∈ {4, 6} → Voiture, 8 → Camion/Truck, 12 → Bulldoze. Other values fall into a non-vehicle residual bucket (the table also stores cartons, sacks, cookware, even loose 40' HC containers).
        </div>
        <div>
            <span class="font-semibold text-slate-900">Revenue model</span> — <em>Expected Revenue</em> applies the customer's official Grille Tarifaire to the scanned-container mix (per-row pricing in
            <code class="text-[11px] bg-slate-50 border border-slate-200 rounded px-1.5 py-0.5">App\Services\OperationsService::PRICE_HT_*</code> constants). To switch to actual invoiced revenue, traverse <code class="text-[11px] bg-slate-50 border border-slate-200 rounded px-1.5 py-0.5">cargo.invoice_ids</code> → <code class="text-[11px] bg-slate-50 border border-slate-200 rounded px-1.5 py-0.5">account.move</code> in a v2 — the expected vs actual delta then becomes a leakage metric.
        </div>
        <div>
            <span class="font-semibold text-slate-900">Deferred to v2</span> —
            <em>Top Vessels by Volume</em> (drill-down list page),
            <em>Single-container detail page</em>,
            <em>Customer / Destination breakdowns</em> (the <code class="text-[11px] bg-slate-50 border border-slate-200 rounded px-1.5 py-0.5">partner_id</code> field is mostly empty in the source data — needs upstream data quality work).
        </div>
    </div>
</section>

{{-- ────────────────────────────────────────────────────── Billing ── --}}
<section id="billing" class="panel p-6 mb-5 scroll-mt-6">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h2 class="text-base font-bold text-slate-900">Billing</h2>
            <p class="text-[11px] text-slate-500 mt-0.5">Invoice-issuance lens — volume, time-to-invoice (shipment basis), accuracy.</p>
        </div>
        <span class="text-[10px] uppercase tracking-wider text-slate-400 font-medium">/billing</span>
    </div>
    <div class="text-xs text-slate-700 leading-relaxed space-y-3">
        <div>
            <span class="font-semibold text-slate-900">Models in use</span> —
            <code class="text-[11px] bg-slate-50 border border-slate-200 rounded px-1.5 py-0.5">account.move</code> (invoices &amp; credit notes),
            <code class="text-[11px] bg-slate-50 border border-slate-200 rounded px-1.5 py-0.5">aw_cargo.cargo</code> (shipment ↔ invoice link via <code>invoice_ids</code>),
            <code class="text-[11px] bg-slate-50 border border-slate-200 rounded px-1.5 py-0.5">aw_cargo.equipment / aw_vehicule.vehicule / aw_bois.bois</code> (line items, source of <code>scanned_date</code>).
        </div>
        <div>
            <span class="font-semibold text-slate-900">Period anchor</span> — invoice volume and credit-note rate filter on <code class="text-[11px] bg-slate-50 border border-slate-200 rounded px-1.5 py-0.5">invoice_date</code> (the actual billing event). Time-to-Invoice computes <code>invoice_date − MAX(scanned_date)</code> per shipment.
        </div>
        <div>
            <span class="font-semibold text-slate-900">Why MAX(scanned_date)</span> — the moment the operation is complete on the last container/vehicle/wood line in the cargo. Earliest defensible point at which billing can be issued. Negative latency = advance billing; we surface it rather than mask it.
        </div>
        <div>
            <span class="font-semibold text-slate-900">Distinct from Receivables</span> — Receivables answers "what's outstanding and how aged". Billing answers "are we issuing invoices on time, in volume, accurately?". The two together cover the full O2C cycle without overlap.
        </div>
    </div>
</section>

{{-- ───────────────────────────────────────────────────── Procurement ── --}}
<section id="procurement" class="panel p-6 mb-5 scroll-mt-6">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h2 class="text-base font-bold text-slate-900">Procurement</h2>
            <p class="text-[11px] text-slate-500 mt-0.5">PO-lifecycle lens — volume, cycle time, compliance, total spend.</p>
        </div>
        <span class="text-[10px] uppercase tracking-wider text-slate-400 font-medium">/procurement</span>
    </div>
    <div class="text-xs text-slate-700 leading-relaxed space-y-3">
        <div>
            <span class="font-semibold text-slate-900">Models in use</span> —
            <code class="text-[11px] bg-slate-50 border border-slate-200 rounded px-1.5 py-0.5">purchase.order</code> (PO header, source for volume &amp; cycle time),
            <code class="text-[11px] bg-slate-50 border border-slate-200 rounded px-1.5 py-0.5">account.move</code> (vendor bills, <code>move_type='in_invoice'</code>),
            <code class="text-[11px] bg-slate-50 border border-slate-200 rounded px-1.5 py-0.5">account.move.line.purchase_line_id</code> (primary PO link), with <code>purchase.order.invoice_ids</code> as fallback.
        </div>
        <div>
            <span class="font-semibold text-slate-900">Period anchors</span> — PO Volume &amp; Cycle Time anchor on <code class="text-[11px] bg-slate-50 border border-slate-200 rounded px-1.5 py-0.5">date_order</code>; PO Compliance and Total Spend anchor on <code class="text-[11px] bg-slate-50 border border-slate-200 rounded px-1.5 py-0.5">invoice_date</code> (the moment the "did it follow process?" / "what did it cost?" questions crystallise). The Open POs sandbox is anchored on <em>today</em> — it asks "what's open right now?", not "what was open in the period?".
        </div>
        <div>
            <span class="font-semibold text-slate-900">Cycle-time scope</span> — POs with <code class="text-[11px] bg-slate-50 border border-slate-200 rounded px-1.5 py-0.5">date_order</code> are included if either (a) <code class="text-[11px] bg-slate-50 border border-slate-200 rounded px-1.5 py-0.5">effective_date</code> is populated (Odoo's computed first-receipt anchor) or (b) at least one linked invoice has been posted (we use the earliest <code class="text-[11px] bg-slate-50 border border-slate-200 rounded px-1.5 py-0.5">invoice_date</code>). POs with neither are still in flight and are excluded from the average.
        </div>
        <div>
            <span class="font-semibold text-slate-900">PO Compliance — sign convention</span> — headline is the compliance rate (% of bills WITH a PO), not its inverse. Detection: line-level
            <code class="text-[11px] bg-slate-50 border border-slate-200 rounded px-1.5 py-0.5">account.move.line.purchase_line_id</code>
            with reverse-lookup via
            <code class="text-[11px] bg-slate-50 border border-slate-200 rounded px-1.5 py-0.5">purchase.order.invoice_ids</code>
            as fallback.
        </div>
        <div>
            <span class="font-semibold text-slate-900">PO Volume vs Total Spend</span> — P1 measures spend you've <em>committed</em> via POs (whether received yet or not); Total Spend measures what you've actually been <em>billed</em> for (whether through a PO or maverick). Total Spend uses
            <code class="text-[11px] bg-slate-50 border border-slate-200 rounded px-1.5 py-0.5">amount_untaxed_signed</code>
            (net of taxes), with abs() applied because vendor bills are P&amp;L-direction signed (negative). Top-suppliers ranking sorts in PHP by abs() to be robust against the sign convention.
        </div>
        <div>
            <span class="font-semibold text-slate-900">Top supplier "Recorded in" column</span> — for each top-N supplier, the dominant expense account is computed via
            <code class="text-[11px] bg-slate-50 border border-slate-200 rounded px-1.5 py-0.5">account.move.line</code>
            <code class="text-[11px] bg-slate-50 border border-slate-200 rounded px-1.5 py-0.5">read_group</code>
            on (move_id IN supplier_bills, debit &gt; 0) grouped by
            <code class="text-[11px] bg-slate-50 border border-slate-200 rounded px-1.5 py-0.5">account_id</code>, picking the top 1 by debit:sum.
        </div>
        <div>
            <span class="font-semibold text-slate-900">Distinct from Payables</span> — Payables answers "what we owe vendors and how aged". Procurement answers "are purchases controlled, fast, and properly authorised?". Together they cover the full P2P cycle without overlap.
        </div>
    </div>
</section>

<div class="panel p-4 text-[11px] text-slate-500 leading-relaxed">
    <strong class="text-slate-700">Maintenance:</strong> when a KPI's data source, period anchor or formula changes, update the corresponding section in
    <code class="text-[10px] bg-slate-50 border border-slate-200 rounded px-1.5 py-0.5">resources/views/admin/methodology.blade.php</code>
    so this page stays the single source of truth for "how the platform works".
</div>

@endsection
