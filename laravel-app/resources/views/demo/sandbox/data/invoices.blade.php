@extends('demo.sandbox.layout')
@section('title', 'Data · Invoices')

@section('header')
    <div>
        <div class="text-[10px] uppercase tracking-widest text-amber-700 font-semibold mb-1">Demo · Sandbox data</div>
        <h1 class="text-2xl font-bold text-slate-900">Invoices</h1>
        <p class="text-[12px] text-slate-500 mt-1">Edit invoice data directly — every change flows through to Dashboard / Revenue / Receivables on next page load.</p>
    </div>
@endsection

@section('content')
@include('demo.sandbox.data._tabs', ['tab' => 'invoices'])

{{-- Add invoice form (always-visible canned form) --}}
<details class="panel mb-4 p-0 overflow-hidden" open>
    <summary class="px-4 py-3 cursor-pointer flex items-center justify-between bg-amber-50 border-b border-amber-200">
        <div class="flex items-center gap-2">
            <svg class="w-3.5 h-3.5 text-amber-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
            <span class="text-[12px] font-semibold text-amber-900">Add an invoice</span>
        </div>
        <span class="text-[10px] text-amber-700 italic">Tip: toggle "Has PO" off to trigger the GL-quality flag on the dashboard</span>
    </summary>
    @if ($errors->any())
        <div class="mx-4 mt-3 px-3 py-2 rounded bg-rose-50 border border-rose-200 text-rose-800 text-[12px]">
            @foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach
        </div>
    @endif
    <form method="POST" action="/demo/sandbox/data/invoices/add" class="p-4 grid grid-cols-1 md:grid-cols-3 gap-3">
        @csrf
        <div>
            <label class="block text-[10px] uppercase tracking-widest text-slate-600 font-semibold mb-1">Customer *</label>
            <select name="customer_id" required class="w-full border border-stone-300 rounded-lg px-3 py-2 text-sm bg-white">
                @foreach ($customers as $c)
                    <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->payment_terms_days }}d)</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-[10px] uppercase tracking-widest text-slate-600 font-semibold mb-1">Invoice date *</label>
            <input name="invoice_date" type="date" required value="{{ now()->format('Y-m-d') }}" class="w-full border border-stone-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-[10px] uppercase tracking-widest text-slate-600 font-semibold mb-1">Amount (HT, XAF) *</label>
            <input name="amount_ht" type="number" step="1" min="0" required value="500000" class="w-full border border-stone-300 rounded-lg px-3 py-2 text-sm mono">
        </div>
        <div>
            <label class="block text-[10px] uppercase tracking-widest text-slate-600 font-semibold mb-1">Product (optional)</label>
            <select name="product_id" class="w-full border border-stone-300 rounded-lg px-3 py-2 text-sm bg-white">
                <option value="">— None —</option>
                @foreach ($products as $p)
                    <option value="{{ $p->id }}">{{ $p->name }} ({{ number_format($p->unit_price, 0) }} XAF)</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-[10px] uppercase tracking-widest text-slate-600 font-semibold mb-1">Qty</label>
            <input name="qty" type="number" step="1" min="1" value="1" class="w-full border border-stone-300 rounded-lg px-3 py-2 text-sm mono">
        </div>
        <div>
            <label class="block text-[10px] uppercase tracking-widest text-slate-600 font-semibold mb-1">PO number (if any)</label>
            <input name="po_number" type="text" placeholder="PO-12345" class="w-full border border-stone-300 rounded-lg px-3 py-2 text-sm mono">
        </div>
        <div class="md:col-span-2 flex items-center gap-2 mt-1">
            <input type="checkbox" name="has_po" value="1" id="has_po" class="rounded">
            <label for="has_po" class="text-[12px] text-slate-700">This invoice has a matching purchase order</label>
        </div>
        <div class="md:col-span-1 flex justify-end items-end">
            <button type="submit" class="bg-stone-900 hover:bg-stone-800 text-white text-[12px] font-semibold rounded-lg px-4 py-2">Add invoice</button>
        </div>
    </form>
</details>

<div class="panel overflow-hidden">
    <table class="w-full text-[12px]">
        <thead class="bg-stone-50 text-[10px] uppercase tracking-widest text-slate-500">
            <tr>
                <th class="text-left py-2 px-3 font-semibold">Number</th>
                <th class="text-left py-2 px-3 font-semibold">Date</th>
                <th class="text-left py-2 px-3 font-semibold">Customer</th>
                <th class="text-right py-2 px-3 font-semibold">Amount HT</th>
                <th class="text-right py-2 px-3 font-semibold">VAT</th>
                <th class="text-right py-2 px-3 font-semibold">Total</th>
                <th class="text-center py-2 px-3 font-semibold">PO</th>
                <th class="text-center py-2 px-3 font-semibold">Status</th>
                <th class="text-right py-2 px-3 font-semibold">Outstanding</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-stone-100">
        @forelse ($invoices as $inv)
            @php
                $outstanding = $inv->outstandingBalance();
                $isOverdue = $outstanding > 0.01 && $inv->due_date->isPast();
            @endphp
            <tr class="hover:bg-stone-50">
                <td class="py-2 px-3 mono font-semibold text-slate-900">{{ $inv->number }}</td>
                <td class="py-2 px-3 mono text-slate-600">{{ $inv->invoice_date->format('Y-m-d') }}</td>
                <td class="py-2 px-3 text-slate-800">{{ $inv->customer?->name ?? '—' }}</td>
                <td class="py-2 px-3 text-right mono text-slate-900">{{ number_format($inv->amount_ht, 0) }}</td>
                <td class="py-2 px-3 text-right mono text-slate-500">{{ number_format($inv->vat_amount, 0) }}</td>
                <td class="py-2 px-3 text-right mono font-semibold text-slate-900">{{ number_format($inv->total, 0) }}</td>
                <td class="py-2 px-3 text-center">
                    @if ($inv->has_po)
                        <span class="text-emerald-700 text-[10px] font-semibold uppercase">Yes</span>
                    @else
                        <span class="text-rose-700 text-[10px] font-semibold uppercase">No</span>
                    @endif
                </td>
                <td class="py-2 px-3 text-center">
                    @php
                        $statusCls = match($inv->status) {
                            'paid'      => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                            'posted'    => $isOverdue ? 'bg-rose-50 text-rose-700 border-rose-200' : 'bg-amber-50 text-amber-700 border-amber-200',
                            'draft'     => 'bg-slate-50 text-slate-600 border-slate-200',
                            default     => 'bg-slate-50 text-slate-500 border-slate-200',
                        };
                    @endphp
                    <span class="inline-block px-2 py-0.5 rounded-full border text-[9px] font-semibold uppercase {{ $statusCls }}">{{ $isOverdue && $inv->status === 'posted' ? 'overdue' : $inv->status }}</span>
                </td>
                <td class="py-2 px-3 text-right mono {{ $outstanding > 0.01 ? 'text-rose-700 font-semibold' : 'text-slate-400' }}">{{ number_format($outstanding, 0) }}</td>
            </tr>
        @empty
            <tr><td colspan="9" class="py-6 text-center text-slate-500 italic">No invoices. Use the form above to add one.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<p class="mt-3 text-[11px] text-slate-500">Showing {{ $invoices->count() }} invoice(s). Edit-in-place + delete will land in a follow-up — for now use the canned form or the natural-language console.</p>
@endsection
