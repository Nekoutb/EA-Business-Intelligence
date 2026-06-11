@extends('admin.layout')
@section('title', 'Slow Movers · ' . $tenant->name)

@section('content')
<div class="mb-4">
    <a href="/admin/business-insights/{{ $tenant->id }}/inventory" class="text-[12px] text-slate-500 hover:text-slate-900 inline-flex items-center gap-1">
        ← Data Inventory
    </a>
</div>

<div class="bg-white border border-stone-200 rounded-xl p-5 mb-5">
    <div class="text-[10px] uppercase tracking-widest text-amber-700 font-semibold mb-1">On-demand report · Slow-Moving Stock</div>
    <h1 class="text-xl font-bold text-slate-900">{{ $tenant->brand ?: $tenant->name }}</h1>
    <p class="text-[12px] text-slate-600 mt-2 max-w-3xl">
        Stock with no warehouse movement for 90+ days, aged by last validated move and valued at cost.
        The indicative provision column is a <strong>starting point</strong> for the OHADA 39X impairment
        discussion — the actual rate belongs in the tenant's accounting policy.
    </p>
</div>

@if ($error)
    <div class="bg-rose-50 border border-rose-200 text-rose-800 rounded-lg p-4 text-sm mb-5">
        <strong>Report failed:</strong> {{ $error }}
    </div>
@elseif (($stock['installed'] ?? null) === false)
    <div class="bg-stone-50 border border-stone-200 text-slate-600 rounded-lg p-4 text-sm mb-5">
        The Odoo <strong>Inventory</strong> module isn't in use on this tenant's database — slow-mover
        analysis needs warehouse movement history, which doesn't exist here.
    </div>
@elseif ($stock)
    @php
        $ccy    = $stock['currency'] ?? 'XAF';
        $ageing = $stock['ageing'] ?? null;
        $movers = array_slice($stock['slow_movers'] ?? [], 0, 50);
        $totalSlow = $stock['totals']['slow_mover_value'] ?? null;   // null = movement history unavailable
        $totalAll  = (float) ($stock['totals']['on_hand_value'] ?? 0);
    @endphp

    @if ($ageing === null)
        <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-lg p-4 text-sm mb-5">
            <strong>Movement history unavailable.</strong> The warehouse movement data needed to age this
            tenant's stock could not be read, so slow movers and the indicative provision cannot be
            computed on this run. Retry once the Odoo connection settles.
        </div>
    @endif

    <div class="grid grid-cols-3 gap-3 mb-5">
        <div class="bg-white border border-amber-200 rounded-lg p-3">
            <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Slow-mover value</div>
            <div class="text-2xl font-bold mono text-amber-700 mt-1">{{ $totalSlow !== null ? ea_money_short((float) $totalSlow, $ccy) : '—' }}</div>
            <div class="text-[10px] text-slate-500 mt-1">
                {{ $totalSlow !== null && $totalAll > 0 ? number_format((float) $totalSlow / $totalAll * 100, 1) . '% of stock value' : '—' }}
            </div>
        </div>
        <div class="bg-white border border-stone-200 rounded-lg p-3">
            <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Slow SKUs</div>
            <div class="text-2xl font-bold mono text-slate-900 mt-1">{{ number_format(count($stock['slow_movers'] ?? [])) }}</div>
            <div class="text-[10px] text-slate-500 mt-1">no movement &gt; 90 days</div>
        </div>
        <div class="bg-white border border-rose-200 rounded-lg p-3">
            <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Indicative 39X provision</div>
            <div class="text-2xl font-bold mono text-rose-700 mt-1">{{ $ageing ? ea_money_short($ageing['indicative_provision'], $ccy) : '—' }}</div>
            <div class="text-[10px] text-slate-500 mt-1">25% on 181–365d · 50% beyond</div>
        </div>
    </div>

    @if ($ageing)
    <div class="bg-white border border-stone-200 rounded-xl overflow-hidden mb-5">
        <div class="px-4 py-3 border-b border-stone-100 text-[11px] text-slate-500">Ageing by last warehouse movement</div>
        <table class="w-full text-[12px]">
            <thead class="bg-stone-50 text-[10px] uppercase tracking-widest text-slate-500">
                <tr>
                    <th class="text-left  py-2 px-3 font-semibold">Bucket</th>
                    <th class="text-right py-2 px-3 font-semibold">SKUs</th>
                    <th class="text-right py-2 px-3 font-semibold">Value</th>
                    <th class="text-right py-2 px-3 font-semibold">Indicative provision</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
                @foreach ($ageing['buckets'] as $b)
                    <tr class="hover:bg-stone-50/40">
                        <td class="py-2 px-3 text-slate-700">{{ $b['label'] }}</td>
                        <td class="py-2 px-3 text-right mono">{{ $b['sku_count'] }}</td>
                        <td class="py-2 px-3 text-right mono">{{ ea_money_short($b['value'], $ccy) }}</td>
                        <td class="py-2 px-3 text-right mono {{ $b['provision'] > 0 ? 'text-amber-700 font-bold' : 'text-slate-400' }}">
                            {{ $b['tier'] > 0 ? ea_money_short($b['provision'], $ccy) . ' (' . (int)($b['tier'] * 100) . '%)' : '—' }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    <div class="bg-white border border-stone-200 rounded-xl overflow-hidden mb-5">
        <div class="px-4 py-3 border-b border-stone-100 text-[11px] text-slate-500">
            Top {{ count($movers) }} slow movers by value · as of {{ $stock['as_of'] }}
        </div>
        <table class="w-full text-[12px]">
            <thead class="bg-stone-50 text-[10px] uppercase tracking-widest text-slate-500">
                <tr>
                    <th class="text-left  py-2 px-3 font-semibold">Product</th>
                    <th class="text-left  py-2 px-3 font-semibold">Category</th>
                    <th class="text-right py-2 px-3 font-semibold">On hand</th>
                    <th class="text-right py-2 px-3 font-semibold">Value</th>
                    <th class="text-left  py-2 px-3 font-semibold">Last move</th>
                    <th class="text-right py-2 px-3 font-semibold">Age (days)</th>
                    <th class="text-right py-2 px-3 font-semibold">Sold 90d</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
                @forelse ($movers as $s)
                    <tr class="hover:bg-stone-50/40">
                        <td class="py-2 px-3">
                            <div class="font-semibold text-slate-900">{{ $s['name'] }}</div>
                            @if ($s['code'])<div class="text-[10px] text-slate-400 mono">{{ $s['code'] }}</div>@endif
                        </td>
                        <td class="py-2 px-3 text-slate-600">{{ $s['category'] }}</td>
                        <td class="py-2 px-3 text-right mono">{{ number_format($s['on_hand'], 0) }}</td>
                        <td class="py-2 px-3 text-right mono font-bold">{{ ea_money_short($s['value'], $ccy) }}</td>
                        <td class="py-2 px-3 text-slate-500 mono">{{ $s['last_move'] ?? 'never' }}</td>
                        <td class="py-2 px-3 text-right mono {{ ($s['age_days'] ?? 9999) > 365 ? 'text-rose-700 font-bold' : 'text-amber-700' }}">{{ $s['age_days'] ?? '∞' }}</td>
                        <td class="py-2 px-3 text-right mono text-slate-500">{{ number_format($s['sold_90d'], 0) }}</td>
                    </tr>
                @empty
                    @if ($ageing === null)
                        <tr><td colspan="7" class="py-6 text-center text-slate-400">Movement history unavailable — slow movers could not be determined on this run.</td></tr>
                    @else
                        <tr><td colspan="7" class="py-6 text-center text-emerald-700">No slow-moving stock — everything on hand moved within the last 90 days. 🎉</td></tr>
                    @endif
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="text-[11px] text-slate-400 mb-5">
        Companion report: <a href="/admin/business-insights/{{ $tenant->id }}/reports/stock-value" class="underline text-slate-600">Stock value snapshot →</a>
    </div>
@endif
@endsection
