@extends('demo.sandbox.layout')
@section('title', 'Tax Return')

@section('header')
    <div class="flex items-end justify-between gap-4">
        <div>
            <div class="text-[10px] uppercase tracking-widest text-amber-700 font-semibold mb-1">Demo · Monthly Tax Return (I/TVA-IR)</div>
            <h1 class="text-2xl font-bold text-slate-900">Tax declaration · {{ $data['period_label'] }}</h1>
            <p class="text-[12px] text-slate-500 mt-1">VAT (19.25%) collected vs deductible, IRPP retenu on salaries. Drives the monthly DGI filing.</p>
        </div>
        <form method="GET" class="flex items-center gap-2">
            <label class="text-[11px] uppercase tracking-widest text-slate-500 font-semibold">Month:</label>
            <input name="month" type="month" value="{{ $data['month'] }}" onchange="this.form.submit()" class="border border-stone-300 rounded-lg px-3 py-1.5 text-[12px] bg-white mono">
        </form>
    </div>
@endsection

@section('content')
<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
    <div class="panel p-4">
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">CA HT (sales)</div>
        <div class="text-xl font-bold mono text-slate-900 mt-1">{{ number_format($data['ca_ht'], 0) }}</div>
        <div class="text-[10px] text-slate-500 mt-0.5">XAF · {{ $data['invoice_count'] }} invoices</div>
    </div>
    <div class="panel p-4 border-l-4 border-amber-400">
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">VAT collected</div>
        <div class="text-xl font-bold mono text-slate-900 mt-1">{{ number_format($data['vat_collected'], 0) }}</div>
        <div class="text-[10px] text-slate-500 mt-0.5">19.25% on CA HT</div>
    </div>
    <div class="panel p-4 border-l-4 border-emerald-400">
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">VAT deductible</div>
        <div class="text-xl font-bold mono text-slate-900 mt-1">{{ number_format($data['vat_deductible'], 0) }}</div>
        <div class="text-[10px] text-slate-500 mt-0.5">~19.25% on opex</div>
    </div>
    <div class="panel p-4 border-l-4 border-rose-400">
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">VAT payable</div>
        <div class="text-xl font-bold mono text-rose-700 mt-1">{{ number_format($data['vat_payable'], 0) }}</div>
        <div class="text-[10px] text-slate-500 mt-0.5">Collected − Deductible</div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
    <div class="panel p-5">
        <div class="text-[10px] uppercase tracking-widest text-amber-700 font-semibold mb-1">§1-§6 · TVA chain</div>
        <h2 class="text-sm font-semibold text-slate-900 mb-3">VAT computation</h2>
        <table class="w-full text-[13px]">
            <tbody class="divide-y divide-stone-100">
                <tr><td class="py-2 pr-3 text-slate-600">L1 · CA HT — sales</td><td class="py-2 text-right mono text-slate-900">{{ number_format($data['ca_ht'], 0) }}</td></tr>
                <tr><td class="py-2 pr-3 text-slate-600">L2 · TVA collectée (19.25%)</td><td class="py-2 text-right mono text-amber-700">{{ number_format($data['vat_collected'], 0) }}</td></tr>
                <tr><td class="py-2 pr-3 text-slate-600">L4 · TVA déductible</td><td class="py-2 text-right mono text-emerald-700">−{{ number_format($data['vat_deductible'], 0) }}</td></tr>
                <tr class="border-t-2 border-stone-300"><td class="py-2 pr-3 font-bold text-slate-900">L6 · TVA à payer</td><td class="py-2 text-right mono font-bold text-rose-700">{{ number_format($data['vat_payable'], 0) }}</td></tr>
            </tbody>
        </table>
    </div>

    <div class="panel p-5">
        <div class="text-[10px] uppercase tracking-widest text-amber-700 font-semibold mb-1">§12 · Impôts retenus sur salaires</div>
        <h2 class="text-sm font-semibold text-slate-900 mb-3">Payroll withholding</h2>
        <table class="w-full text-[13px]">
            <tbody class="divide-y divide-stone-100">
                <tr><td class="py-2 pr-3 text-slate-600">L67 · IRPP retenu (Principal)</td><td class="py-2 text-right mono text-slate-900">{{ number_format($data['irpp_retenu'] / 1.10, 0) }}</td></tr>
                <tr><td class="py-2 pr-3 text-slate-600">L67 · CAC (10% of Principal)</td><td class="py-2 text-right mono text-slate-700">{{ number_format(($data['irpp_retenu'] / 1.10) * 0.10, 0) }}</td></tr>
                <tr class="border-t-2 border-stone-300"><td class="py-2 pr-3 font-bold text-slate-900">L67 · Total IRPP</td><td class="py-2 text-right mono font-bold text-slate-900">{{ number_format($data['irpp_retenu'], 0) }}</td></tr>
            </tbody>
        </table>
    </div>
</div>

<div class="panel p-5 mt-4 bg-rose-50/40 border border-rose-200">
    <div class="flex items-center justify-between">
        <div>
            <div class="text-[10px] uppercase tracking-widest text-rose-800 font-semibold mb-1">Total declaration</div>
            <div class="text-3xl font-bold text-rose-700 mono">{{ number_format($data['total_due'], 0) }} <span class="text-[14px] text-slate-500">XAF</span></div>
        </div>
        <div class="text-[11px] text-slate-600 text-right">
            Due to DGI by the 15th of the following month.<br>
            VAT {{ number_format($data['vat_payable'], 0) }} + IRPP {{ number_format($data['irpp_retenu'], 0) }}.
        </div>
    </div>
</div>
@endsection
