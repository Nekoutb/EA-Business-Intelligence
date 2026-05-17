@extends('demo.sandbox.layout')
@section('title', 'P&L Forecast')

@section('header')
    <div>
        <div class="text-[10px] uppercase tracking-widest text-amber-700 font-semibold mb-1">Demo · P&L Forecast</div>
        <h1 class="text-2xl font-bold text-slate-900">{{ $data['months'] }}-month P&L projection</h1>
        <p class="text-[12px] text-slate-500 mt-1">Trailing-90-day run-rate extrapolated forward with mild seasonal flex (±5%). XAF.</p>
    </div>
@endsection

@section('content')
<div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-5">
    <div class="panel p-4">
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Forecast revenue ({{ $data['months'] }}m)</div>
        <div class="text-xl font-bold mono text-slate-900 mt-1">{{ number_format($data['forecast_total_revenue'], 0) }}</div>
    </div>
    <div class="panel p-4 border-l-4 {{ $data['forecast_total_ebitda'] >= 0 ? 'border-emerald-400' : 'border-rose-400' }}">
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Forecast EBITDA ({{ $data['months'] }}m)</div>
        <div class="text-xl font-bold mono mt-1 {{ $data['forecast_total_ebitda'] >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">{{ ($data['forecast_total_ebitda'] >= 0 ? '+' : '') }}{{ number_format($data['forecast_total_ebitda'], 0) }}</div>
    </div>
    <div class="panel p-4">
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Monthly baseline (90d)</div>
        <div class="text-xl font-bold mono text-slate-900 mt-1">{{ number_format($data['baseline_monthly']['revenue'], 0) }}</div>
        <div class="text-[10px] text-slate-500 mt-0.5">revenue per month</div>
    </div>
</div>

<div class="panel overflow-hidden">
    <table class="w-full text-[12px]">
        <thead class="bg-stone-50 text-[10px] uppercase tracking-widest text-slate-500">
            <tr>
                <th class="text-left py-2 px-3 font-semibold">Month</th>
                <th class="text-right py-2 px-3 font-semibold">Revenue</th>
                <th class="text-right py-2 px-3 font-semibold">COGS</th>
                <th class="text-right py-2 px-3 font-semibold">Gross profit</th>
                <th class="text-right py-2 px-3 font-semibold">Opex</th>
                <th class="text-right py-2 px-3 font-semibold">EBITDA</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-stone-100">
        @foreach ($data['forecast'] as $m)
            <tr class="hover:bg-stone-50">
                <td class="py-2 px-3 mono font-semibold text-slate-700">{{ $m['month_label'] }}</td>
                <td class="py-2 px-3 text-right mono text-slate-900">{{ number_format($m['revenue'], 0) }}</td>
                <td class="py-2 px-3 text-right mono text-rose-700">−{{ number_format($m['cogs'], 0) }}</td>
                <td class="py-2 px-3 text-right mono text-emerald-700 font-semibold">{{ number_format($m['gross_profit'], 0) }}</td>
                <td class="py-2 px-3 text-right mono text-rose-700">−{{ number_format($m['opex'], 0) }}</td>
                <td class="py-2 px-3 text-right mono font-bold {{ $m['ebitda'] >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">{{ ($m['ebitda'] >= 0 ? '+' : '') }}{{ number_format($m['ebitda'], 0) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endsection
