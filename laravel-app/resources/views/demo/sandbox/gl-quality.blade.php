@extends('demo.sandbox.layout')
@section('title', 'GL Quality')

@section('header')
    <div>
        <div class="text-[10px] uppercase tracking-widest text-amber-700 font-semibold mb-1">Demo · GL Quality</div>
        <h1 class="text-2xl font-bold text-slate-900">Ledger hygiene findings</h1>
        <p class="text-[12px] text-slate-500 mt-1">Audit-ready review of bookkeeping quality — invoices without PO, unbalanced journals, draft documents. The pre-audit finding list.</p>
    </div>
@endsection

@section('content')
@include('demo.sandbox._kpi-strip', ['kpis' => $kpis])

<div class="panel mb-4 overflow-hidden">
    <div class="px-4 py-3 border-b border-stone-200 bg-rose-50/40">
        <h2 class="text-sm font-semibold text-slate-900">Finding 1 — Invoices issued without matching purchase order</h2>
        <p class="text-[11px] text-slate-600 mt-0.5">Procurement-control flag. These invoices skipped the PO step and may indicate weak internal control.</p>
    </div>
    <table class="w-full text-[12px]">
        <thead class="bg-stone-50 text-[10px] uppercase tracking-widest text-slate-500">
            <tr>
                <th class="text-left py-2 px-3 font-semibold">Number</th>
                <th class="text-left py-2 px-3 font-semibold">Date</th>
                <th class="text-left py-2 px-3 font-semibold">Customer</th>
                <th class="text-right py-2 px-3 font-semibold">Amount HT</th>
                <th class="text-center py-2 px-3 font-semibold">Status</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-stone-100">
        @forelse ($data['no_po_invoices'] as $inv)
            <tr class="hover:bg-stone-50">
                <td class="py-2 px-3 mono font-semibold text-slate-700">{{ $inv->number }}</td>
                <td class="py-2 px-3 mono text-slate-600">{{ $inv->invoice_date->format('Y-m-d') }}</td>
                <td class="py-2 px-3 text-slate-800">{{ $inv->customer?->name }}</td>
                <td class="py-2 px-3 text-right mono text-slate-900">{{ number_format($inv->amount_ht, 0) }}</td>
                <td class="py-2 px-3 text-center">
                    <span class="inline-block px-2 py-0.5 rounded-full bg-rose-50 border border-rose-200 text-rose-700 text-[9px] font-semibold uppercase">no PO</span>
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="py-4 text-center text-emerald-700 italic">No invoices without PO — clean.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

@if ($data['unbalanced_count'] > 0)
    <div class="panel mb-4 overflow-hidden">
        <div class="px-4 py-3 border-b border-stone-200 bg-rose-50/40">
            <h2 class="text-sm font-semibold text-slate-900">Finding 2 — Unbalanced journal entries</h2>
            <p class="text-[11px] text-slate-600 mt-0.5">Posted journal entries where total debits ≠ total credits. Should be impossible — investigate immediately.</p>
        </div>
        <table class="w-full text-[12px]">
            <thead class="bg-stone-50 text-[10px] uppercase tracking-widest text-slate-500">
                <tr>
                    <th class="text-left py-2 px-3 font-semibold">Date</th>
                    <th class="text-left py-2 px-3 font-semibold">Reference</th>
                    <th class="text-left py-2 px-3 font-semibold">Description</th>
                    <th class="text-right py-2 px-3 font-semibold">Total Dr</th>
                    <th class="text-right py-2 px-3 font-semibold">Total Cr</th>
                    <th class="text-right py-2 px-3 font-semibold">Δ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
            @foreach ($data['unbalanced_entries'] as $e)
                <tr class="hover:bg-stone-50">
                    <td class="py-2 px-3 mono text-slate-600">{{ $e->entry_date->format('Y-m-d') }}</td>
                    <td class="py-2 px-3 mono text-slate-600">{{ $e->reference ?? '—' }}</td>
                    <td class="py-2 px-3 text-slate-800">{{ $e->description }}</td>
                    <td class="py-2 px-3 text-right mono text-slate-900">{{ number_format($e->totalDebit(), 0) }}</td>
                    <td class="py-2 px-3 text-right mono text-slate-900">{{ number_format($e->totalCredit(), 0) }}</td>
                    <td class="py-2 px-3 text-right mono text-rose-700 font-semibold">{{ number_format($e->totalDebit() - $e->totalCredit(), 0) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif

<div class="panel p-4 bg-amber-50/40 border border-amber-200">
    <div class="text-[10px] uppercase tracking-widest text-amber-800 font-semibold mb-1">Try this</div>
    <p class="text-[12px] text-slate-700">
        Use the <a href="/demo/sandbox/data/invoices" class="text-amber-700 underline">Invoices tab</a> to add a new invoice with the "Has PO" toggle OFF — refresh this page to see the count tick up.
    </p>
</div>
@endsection
