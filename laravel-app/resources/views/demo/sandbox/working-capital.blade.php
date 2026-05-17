@extends('demo.sandbox.layout')
@section('title', 'Working Capital')

@section('header')
    <div>
        <div class="text-[10px] uppercase tracking-widest text-amber-700 font-semibold mb-1">Demo · Working Capital</div>
        <h1 class="text-2xl font-bold text-slate-900">Liquidity & cash conversion</h1>
        <p class="text-[12px] text-slate-500 mt-1">Snapshot of working capital position as of {{ $data['as_of'] }} · all figures in XAF</p>
    </div>
@endsection

@section('content')
@include('demo.sandbox._kpi-strip', ['kpis' => $kpis])

@php
    $cccStatus          = $data['cash_conversion_cycle'] <= 30 ? 'green' : ($data['cash_conversion_cycle'] <= 60 ? 'amber' : 'red');
    $currentRatioStatus = $data['current_ratio'] >= 1.5 ? 'green' : ($data['current_ratio'] >= 1.0 ? 'amber' : 'red');
@endphp

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
    <div class="panel p-5">
        <h2 class="text-sm font-semibold text-slate-900 mb-3">Balance sheet — current</h2>
        <table class="w-full text-[12px]">
            <tbody class="divide-y divide-stone-100">
                <tr><td class="py-2 pr-3 text-slate-600">Cash + bank (521 / 571)</td><td class="py-2 text-right mono text-slate-900">{{ number_format($data['cash_balance'], 0) }}</td></tr>
                <tr><td class="py-2 pr-3 text-slate-600">Accounts receivable (411)</td><td class="py-2 text-right mono text-slate-900">{{ number_format($data['ar_balance'], 0) }}</td></tr>
                <tr><td class="py-2 pr-3 text-slate-600">VAT receivable (445)</td><td class="py-2 text-right mono text-slate-900">{{ number_format($data['vat_receivable'], 0) }}</td></tr>
                <tr><td class="py-2 pr-3 text-slate-600">Inventory (31/37)</td><td class="py-2 text-right mono text-slate-900">{{ number_format($data['inventory_balance'], 0) }}</td></tr>
                <tr class="border-t-2 border-stone-300"><td class="py-2 pr-3 font-bold text-slate-900">Current assets</td><td class="py-2 text-right mono font-bold text-emerald-700">{{ number_format($data['current_assets'], 0) }}</td></tr>
                <tr><td class="py-3 pr-3"></td><td></td></tr>
                <tr><td class="py-2 pr-3 text-slate-600">Accounts payable (401)</td><td class="py-2 text-right mono text-slate-900">{{ number_format($data['ap_balance'], 0) }}</td></tr>
                <tr><td class="py-2 pr-3 text-slate-600">VAT payable (443)</td><td class="py-2 text-right mono text-slate-900">{{ number_format($data['vat_payable'], 0) }}</td></tr>
                <tr class="border-t-2 border-stone-300"><td class="py-2 pr-3 font-bold text-slate-900">Current liabilities</td><td class="py-2 text-right mono font-bold text-rose-700">{{ number_format($data['current_liabilities'], 0) }}</td></tr>
                <tr><td class="py-3 pr-3"></td><td></td></tr>
                <tr class="border-t-4 border-stone-700"><td class="py-2 pr-3 font-bold text-slate-900">NET WORKING CAPITAL</td><td class="py-2 text-right mono font-bold text-slate-900">{{ number_format($data['working_capital'], 0) }}</td></tr>
            </tbody>
        </table>
    </div>

    <div class="panel p-5">
        <h2 class="text-sm font-semibold text-slate-900 mb-3">Interpretation</h2>
        <div class="space-y-3 text-[12px] text-slate-700">
            <div class="p-3 rounded {{ $currentRatioStatus === 'green' ? 'bg-emerald-50' : ($currentRatioStatus === 'amber' ? 'bg-amber-50' : 'bg-rose-50') }}">
                <strong>Current ratio {{ number_format($data['current_ratio'], 2) }}x:</strong>
                @if ($currentRatioStatus === 'green')
                    Healthy — short-term obligations comfortably covered by short-term assets.
                @elseif ($currentRatioStatus === 'amber')
                    Adequate but thin — a single overdue large invoice could create a cash crunch.
                @else
                    Below 1.0x — current liabilities exceed current assets. Immediate cashflow risk.
                @endif
            </div>
            <div class="p-3 rounded {{ $cccStatus === 'green' ? 'bg-emerald-50' : ($cccStatus === 'amber' ? 'bg-amber-50' : 'bg-rose-50') }}">
                <strong>Cash conversion {{ number_format($data['cash_conversion_cycle'], 0) }}d:</strong>
                Time between paying suppliers and collecting from customers.
                @if ($cccStatus === 'green')
                    Tight — capital cycles back to you quickly.
                @elseif ($cccStatus === 'amber')
                    Stretched — collection slippage is tying up working capital.
                @else
                    Too long — collections are dragging well behind payments.
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
