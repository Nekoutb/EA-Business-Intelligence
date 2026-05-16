@extends('demo.sandbox.layout')
@section('title', 'Data · Payments')

@section('header')
    <div>
        <div class="text-[10px] uppercase tracking-widest text-amber-700 font-semibold mb-1">Demo · Sandbox data</div>
        <h1 class="text-2xl font-bold text-slate-900">Customer payments</h1>
        <p class="text-[12px] text-slate-500 mt-1">Record payments against open invoices — overdue rate + DSO recompute on next refresh.</p>
    </div>
@endsection

@section('content')
@include('demo.sandbox.data._tabs', ['tab' => 'payments'])

<details class="panel mb-4 p-0 overflow-hidden" open>
    <summary class="px-4 py-3 cursor-pointer flex items-center gap-2 bg-amber-50 border-b border-amber-200">
        <svg class="w-3.5 h-3.5 text-amber-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
        <span class="text-[12px] font-semibold text-amber-900">Record a payment</span>
    </summary>
    @if ($errors->any())
        <div class="mx-4 mt-3 px-3 py-2 rounded bg-rose-50 border border-rose-200 text-rose-800 text-[12px]">
            @foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach
        </div>
    @endif
    <form method="POST" action="/demo/sandbox/data/payments/add" class="p-4 grid grid-cols-1 md:grid-cols-3 gap-3">
        @csrf
        <div class="md:col-span-2">
            <label class="block text-[10px] uppercase tracking-widest text-slate-600 font-semibold mb-1">Open invoice *</label>
            <select name="invoice_id" required class="w-full border border-stone-300 rounded-lg px-3 py-2 text-sm bg-white">
                @foreach ($open_invoices as $i)
                    <option value="{{ $i->id }}">{{ $i->number }} · {{ $i->customer?->name }} · {{ number_format($i->total, 0) }} XAF</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-[10px] uppercase tracking-widest text-slate-600 font-semibold mb-1">Payment date *</label>
            <input name="payment_date" type="date" required value="{{ now()->format('Y-m-d') }}" class="w-full border border-stone-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-[10px] uppercase tracking-widest text-slate-600 font-semibold mb-1">Amount (XAF) *</label>
            <input name="amount" type="number" step="1" min="1" required class="w-full border border-stone-300 rounded-lg px-3 py-2 text-sm mono">
        </div>
        <div>
            <label class="block text-[10px] uppercase tracking-widest text-slate-600 font-semibold mb-1">Method *</label>
            <select name="method" required class="w-full border border-stone-300 rounded-lg px-3 py-2 text-sm bg-white">
                <option value="bank_transfer">Bank transfer</option>
                <option value="cheque">Cheque</option>
                <option value="cash">Cash</option>
                <option value="mobile_money">Mobile money</option>
            </select>
        </div>
        <div>
            <label class="block text-[10px] uppercase tracking-widest text-slate-600 font-semibold mb-1">Reference</label>
            <input name="reference" type="text" placeholder="PAY-12345" class="w-full border border-stone-300 rounded-lg px-3 py-2 text-sm mono">
        </div>
        <div class="md:col-span-3 flex justify-end">
            <button type="submit" class="bg-stone-900 hover:bg-stone-800 text-white text-[12px] font-semibold rounded-lg px-4 py-2">Record payment</button>
        </div>
    </form>
</details>

<div class="panel overflow-hidden">
    <table class="w-full text-[12px]">
        <thead class="bg-stone-50 text-[10px] uppercase tracking-widest text-slate-500">
            <tr>
                <th class="text-left py-2 px-3 font-semibold">Date</th>
                <th class="text-left py-2 px-3 font-semibold">Invoice</th>
                <th class="text-left py-2 px-3 font-semibold">Customer</th>
                <th class="text-right py-2 px-3 font-semibold">Amount</th>
                <th class="text-left py-2 px-3 font-semibold">Method</th>
                <th class="text-left py-2 px-3 font-semibold">Reference</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-stone-100">
        @forelse ($payments as $p)
            <tr class="hover:bg-stone-50">
                <td class="py-2 px-3 mono text-slate-600">{{ $p->payment_date->format('Y-m-d') }}</td>
                <td class="py-2 px-3 mono font-semibold text-slate-700">{{ $p->invoice?->number ?? '—' }}</td>
                <td class="py-2 px-3 text-slate-800">{{ $p->invoice?->customer?->name ?? '—' }}</td>
                <td class="py-2 px-3 text-right mono font-semibold text-slate-900">{{ number_format($p->amount, 0) }}</td>
                <td class="py-2 px-3 mono text-slate-500">{{ str_replace('_', ' ', $p->method) }}</td>
                <td class="py-2 px-3 mono text-slate-500">{{ $p->reference ?? '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="py-6 text-center text-slate-500 italic">No payments yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
