@extends('admin.layout')
@section('title', 'Stock Value · ' . $tenant->name)

@section('content')
<div class="mb-4">
    <a href="/admin/business-insights/{{ $tenant->id }}/inventory" class="text-[12px] text-slate-500 hover:text-slate-900 inline-flex items-center gap-1">
        ← Data Inventory
    </a>
</div>

<div class="bg-white border border-stone-200 rounded-xl p-5 mb-5">
    <div class="text-[10px] uppercase tracking-widest text-amber-700 font-semibold mb-1">On-demand report · Stock Value Snapshot</div>
    <h1 class="text-xl font-bold text-slate-900">{{ $tenant->brand ?: $tenant->name }}</h1>
    <p class="text-[12px] text-slate-600 mt-2 max-w-3xl">
        Per-product stock on hand, reserved and incoming, valued at cost. Deterministic — read straight
        from the tenant's Odoo warehouse data, no LLM in the loop.
    </p>
</div>

@if ($error)
    <div class="bg-rose-50 border border-rose-200 text-rose-800 rounded-lg p-4 text-sm mb-5">
        <strong>Report failed:</strong> {{ $error }}
    </div>
@elseif (($stock['installed'] ?? null) === false)
    <div class="bg-stone-50 border border-stone-200 text-slate-600 rounded-lg p-4 text-sm mb-5">
        The Odoo <strong>Inventory</strong> module isn't in use on this tenant's database — there is no
        warehouse-level stock data to report. Inventory figures for this tenant are GL-based only
        (see the tenant's Inventory page).
    </div>
@elseif ($stock)
    @php
        $ccy    = $stock['currency'] ?? 'XAF';
        $totals = $stock['totals'] ?? [];
        $rows   = array_slice($stock['skus'] ?? [], 0, 50);
    @endphp

    <div class="grid grid-cols-4 gap-3 mb-5">
        <div class="bg-white border border-stone-200 rounded-lg p-3">
            <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Stock value</div>
            <div class="text-2xl font-bold mono text-slate-900 mt-1">{{ ea_money_short((float)($totals['on_hand_value'] ?? 0), $ccy) }}</div>
            <div class="text-[10px] text-slate-500 mt-1">via {{ $stock['valuation_source'] }}</div>
        </div>
        <div class="bg-white border border-stone-200 rounded-lg p-3">
            <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">SKUs in stock</div>
            <div class="text-2xl font-bold mono text-slate-900 mt-1">{{ number_format($totals['sku_count'] ?? 0) }}</div>
            <div class="text-[10px] text-slate-500 mt-1">of {{ number_format($totals['storable_products'] ?? 0) }} storable products</div>
        </div>
        <div class="bg-white border border-stone-200 rounded-lg p-3">
            <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Incoming units</div>
            <div class="text-2xl font-bold mono text-slate-900 mt-1">{{ number_format($totals['incoming_units'] ?? 0) }}</div>
            <div class="text-[10px] text-slate-500 mt-1">confirmed receipts in transit</div>
        </div>
        <div class="bg-white border {{ ($totals['negative_stock_count'] ?? 0) > 0 ? 'border-rose-200' : 'border-stone-200' }} rounded-lg p-3">
            <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Negative stock</div>
            <div class="text-2xl font-bold mono {{ ($totals['negative_stock_count'] ?? 0) > 0 ? 'text-rose-700' : 'text-slate-900' }} mt-1">{{ number_format($totals['negative_stock_count'] ?? 0) }}</div>
            <div class="text-[10px] text-slate-500 mt-1">SKUs below zero — data errors</div>
        </div>
    </div>

    @if (!empty($stock['errors']))
        <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 text-[11px] text-amber-800 mb-5">
            <strong>{{ count($stock['errors']) }} section warning(s):</strong>
            {{ implode(' · ', $stock['errors']) }}
        </div>
    @endif

    <div class="bg-white border border-stone-200 rounded-xl overflow-hidden mb-5">
        <div class="px-4 py-3 border-b border-stone-100 text-[11px] text-slate-500">
            Top {{ count($rows) }} of {{ count($stock['skus'] ?? []) }} products by value · as of {{ $stock['as_of'] }}
        </div>
        <table class="w-full text-[12px]">
            <thead class="bg-stone-50 text-[10px] uppercase tracking-widest text-slate-500">
                <tr>
                    <th class="text-left  py-2 px-3 font-semibold">Product</th>
                    <th class="text-left  py-2 px-3 font-semibold">Category</th>
                    <th class="text-right py-2 px-3 font-semibold">On hand</th>
                    <th class="text-right py-2 px-3 font-semibold">Reserved</th>
                    <th class="text-right py-2 px-3 font-semibold">Incoming</th>
                    <th class="text-right py-2 px-3 font-semibold">Unit cost</th>
                    <th class="text-right py-2 px-3 font-semibold">Value</th>
                    <th class="text-left  py-2 px-3 font-semibold">Last move</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
                @forelse ($rows as $s)
                    <tr class="hover:bg-stone-50/40 {{ $s['on_hand'] < 0 ? 'bg-rose-50/40' : '' }}">
                        <td class="py-2 px-3">
                            <div class="font-semibold text-slate-900">{{ $s['name'] }}</div>
                            @if ($s['code'])<div class="text-[10px] text-slate-400 mono">{{ $s['code'] }}</div>@endif
                        </td>
                        <td class="py-2 px-3 text-slate-600">{{ $s['category'] }}</td>
                        <td class="py-2 px-3 text-right mono {{ $s['on_hand'] < 0 ? 'text-rose-700 font-bold' : '' }}">{{ number_format($s['on_hand'], 0) }}</td>
                        <td class="py-2 px-3 text-right mono text-slate-500">{{ number_format($s['reserved'], 0) }}</td>
                        <td class="py-2 px-3 text-right mono text-slate-500">{{ number_format($s['incoming'], 0) }}</td>
                        <td class="py-2 px-3 text-right mono">{{ ea_money_short($s['cost'], $ccy) }}</td>
                        <td class="py-2 px-3 text-right mono font-bold">{{ ea_money_short($s['value'], $ccy) }}</td>
                        <td class="py-2 px-3 text-slate-500 mono">{{ $s['last_move'] ?? 'never' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="py-6 text-center text-slate-400">No stock on hand in internal locations.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="text-[11px] text-slate-400 mb-5">
        Companion report: <a href="/admin/business-insights/{{ $tenant->id }}/reports/slow-movers" class="underline text-slate-600">Slow-moving stock →</a>
    </div>
@endif
@endsection
