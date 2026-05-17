@extends('demo.sandbox.layout')
@section('title', 'Income Tax Compliance')

@section('header')
    <div>
        <div class="text-[10px] uppercase tracking-widest text-amber-700 font-semibold mb-1">Demo · Income Tax Compliance (IS)</div>
        <h1 class="text-2xl font-bold text-slate-900">Annual income tax estimate</h1>
        <p class="text-[12px] text-slate-500 mt-1">Cameroon Impôt sur les Sociétés · 33% on taxable profit OR 2% of turnover minimum · YTD-grossed-up estimate · figures in XAF</p>
    </div>
@endsection

@section('content')
@include('demo.sandbox._kpi-strip', ['kpis' => $kpis])

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
    <div class="panel p-5">
        <h2 class="text-sm font-semibold text-slate-900 mb-3">Computation</h2>
        <table class="w-full text-[13px]">
            <tbody class="divide-y divide-stone-100">
                <tr><td class="py-2 pr-3 text-slate-600">YTD revenue ({{ $data['days_ytd'] }}d)</td><td class="py-2 text-right mono text-slate-900">{{ number_format($data['ytd_revenue'], 0) }}</td></tr>
                <tr><td class="py-2 pr-3 text-slate-600">YTD taxable profit</td><td class="py-2 text-right mono text-slate-900">{{ number_format($data['ytd_taxable_profit'], 0) }}</td></tr>
                <tr><td class="py-2 pr-3 text-slate-600">Annualisation factor</td><td class="py-2 text-right mono text-slate-700">× {{ number_format(365 / max(1, $data['days_ytd']), 2) }}</td></tr>
                <tr class="border-t-2 border-stone-300"><td class="py-2 pr-3 font-bold text-slate-900">Annualised taxable</td><td class="py-2 text-right mono font-bold text-slate-900">{{ number_format($data['annualised_taxable'], 0) }}</td></tr>
                <tr><td class="py-2 pr-3 text-slate-600">IS calculation (33%)</td><td class="py-2 text-right mono text-slate-700">{{ number_format($data['is_annual'], 0) }}</td></tr>
                <tr><td class="py-2 pr-3 text-slate-600">Minimum tax (2% × CA)</td><td class="py-2 text-right mono text-slate-700">{{ number_format($data['minimum_tax'], 0) }}</td></tr>
                <tr class="border-t-2 border-stone-300 bg-rose-50/30"><td class="py-2 pr-3 font-bold text-slate-900">IS PAYABLE (max)</td><td class="py-2 text-right mono font-bold text-rose-700">{{ number_format($data['effective_tax'], 0) }}</td></tr>
            </tbody>
        </table>
    </div>

    <div class="panel p-5">
        <h2 class="text-sm font-semibold text-slate-900 mb-3">Method note</h2>
        <p class="text-[12px] text-slate-700 leading-relaxed mb-3">
            Cameroon corporate income tax (IS) is the higher of:
        </p>
        <ul class="text-[12px] text-slate-700 leading-relaxed space-y-1.5 pl-4 list-disc">
            <li><strong>33% of taxable profit</strong> (rate including 10% CAC surcharge)</li>
            <li><strong>2% of annual turnover</strong> as a minimum tax floor</li>
        </ul>
        <p class="text-[12px] text-slate-700 leading-relaxed mt-3">
            In this demo we annualise YTD figures to estimate the full-year payable. The real BMS uses actual closing balances + applies the full add-back / deduction schedule from the CGI.
        </p>
    </div>
</div>
@endsection
