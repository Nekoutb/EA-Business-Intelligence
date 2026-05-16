@extends('demo.sandbox.layout')
@section('title', 'Billing')

@section('header')
    <div class="flex items-end justify-between gap-4">
        <div>
            <div class="text-[10px] uppercase tracking-widest text-amber-700 font-semibold mb-1">Demo · Billing</div>
            <h1 class="text-2xl font-bold text-slate-900">Billing analytics · last {{ $days }} days</h1>
            <p class="text-[12px] text-slate-500 mt-1">{{ $data['period_label'] }} · {{ $data['total_invoices'] }} invoices · {{ number_format($data['total_billed_ht'], 0) }} XAF billed</p>
        </div>
        <form method="GET" class="flex items-center gap-2">
            <label class="text-[11px] uppercase tracking-widest text-slate-500 font-semibold">Window:</label>
            <select name="days" onchange="this.form.submit()" class="border border-stone-300 rounded-lg px-3 py-1.5 text-[12px] bg-white">
                @foreach ([7, 30, 60, 90, 180, 365] as $d)
                    <option value="{{ $d }}" @selected($days === $d)>last {{ $d }} days</option>
                @endforeach
            </select>
        </form>
    </div>
@endsection

@section('content')
<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
    <div class="panel p-4">
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Invoices issued</div>
        <div class="text-xl font-bold mono text-slate-900 mt-1">{{ $data['total_invoices'] }}</div>
    </div>
    <div class="panel p-4">
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Total billed (HT)</div>
        <div class="text-xl font-bold mono text-slate-900 mt-1">{{ number_format($data['total_billed_ht'], 0) }}</div>
    </div>
    <div class="panel p-4">
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Average invoice</div>
        <div class="text-xl font-bold mono text-slate-900 mt-1">{{ number_format($data['avg_invoice'], 0) }}</div>
    </div>
    <div class="panel p-4 {{ $data['total_collected'] / max(1, $data['total_billed_ttc']) >= 0.85 ? 'border-l-4 border-emerald-400' : 'border-l-4 border-amber-400' }}">
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Collected</div>
        <div class="text-xl font-bold mono text-slate-900 mt-1">{{ number_format($data['total_collected'], 0) }}</div>
        <div class="text-[10px] text-slate-500 mt-0.5">{{ number_format($data['total_billed_ttc'] > 0 ? ($data['total_collected'] / $data['total_billed_ttc']) * 100 : 0, 1) }}% of billed TTC</div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
    <div class="lg:col-span-2 panel p-5">
        <h2 class="text-sm font-semibold text-slate-900 mb-3">Volume by week</h2>
        <table class="w-full text-[12px]">
            <thead class="text-[10px] uppercase tracking-widest text-slate-500">
                <tr class="border-b border-stone-200">
                    <th class="text-left py-2 pr-3 font-semibold">Week of</th>
                    <th class="text-right py-2 px-3 font-semibold">Invoices</th>
                    <th class="text-right py-2 px-3 font-semibold">Total HT</th>
                    <th class="text-right py-2 px-3 font-semibold">Avg / invoice</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
            @foreach ($data['by_week'] as $w)
                <tr>
                    <td class="py-2 pr-3 mono text-slate-600">{{ $w['week'] }}</td>
                    <td class="py-2 px-3 text-right mono text-slate-900">{{ $w['count'] }}</td>
                    <td class="py-2 px-3 text-right mono text-slate-900">{{ number_format($w['total_ht'], 0) }}</td>
                    <td class="py-2 px-3 text-right mono text-slate-500">{{ number_format($w['count'] > 0 ? $w['total_ht'] / $w['count'] : 0, 0) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    <div class="panel p-5">
        <h2 class="text-sm font-semibold text-slate-900 mb-3">Payment method mix</h2>
        <table class="w-full text-[12px]">
            <thead class="text-[10px] uppercase tracking-widest text-slate-500">
                <tr class="border-b border-stone-200">
                    <th class="text-left py-2 pr-3 font-semibold">Method</th>
                    <th class="text-right py-2 px-3 font-semibold">Count</th>
                    <th class="text-right py-2 px-3 font-semibold">Amount</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
            @foreach ($data['by_method'] as $m)
                <tr>
                    <td class="py-2 pr-3 text-slate-800">{{ str_replace('_', ' ', $m['method']) }}</td>
                    <td class="py-2 px-3 text-right mono text-slate-500">{{ $m['count'] }}</td>
                    <td class="py-2 px-3 text-right mono font-semibold text-slate-900">{{ number_format($m['amount'], 0) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
