@extends('demo.sandbox.layout')
@section('title', 'Dashboard')

@section('header')
    <div class="flex items-end justify-between gap-4">
        <div>
            <div class="text-[10px] uppercase tracking-widest text-amber-700 font-semibold mb-1">Demo · Dashboard</div>
            <h1 class="text-2xl font-bold text-slate-900">Business Management Suite</h1>
            <p class="text-[12px] text-slate-500 mt-1">
                Period <span class="font-semibold mono">{{ $kpi['period_label'] }}</span>
                · Currency <span class="font-semibold mono">{{ $kpi['currency'] }}</span>
                · Refreshed live from your sandbox tables
            </p>
        </div>
        <div class="flex gap-2">
            <a href="/demo/sandbox/data" class="text-[12px] font-medium text-slate-600 border border-stone-300 hover:border-amber-500 hover:text-amber-700 rounded-lg px-3 py-2">Edit data →</a>
            <a href="/demo/sandbox/console" class="text-[12px] font-medium text-white bg-stone-900 hover:bg-stone-800 rounded-lg px-3 py-2">NL console →</a>
        </div>
    </div>
@endsection

@section('content')
@php
    function bmsKpiStatus($value, array $green, array $red, string $direction = 'higher_better'): string {
        if ($value === null) return 'na';
        if ($direction === 'higher_better') {
            if ($value >= $green[0]) return 'green';
            if ($value >= $red[0])   return 'amber';
            return 'red';
        }
        if ($value <= $green[0]) return 'green';
        if ($value <= $red[0])   return 'amber';
        return 'red';
    }
    $yoyStatus      = bmsKpiStatus($kpi['yoy_pct'],          [5, null],  [-5, null], 'higher_better');
    $gmStatus       = bmsKpiStatus($kpi['gross_margin_pct'], [35, null], [20, null], 'higher_better');
    $ebitdaStatus   = bmsKpiStatus($kpi['ebitda_margin_pct'],[10, null], [0,  null], 'higher_better');
    $dsoStatus      = bmsKpiStatus($kpi['dso'],              [30, null], [60, null], 'lower_better');
    $overdueStatus  = bmsKpiStatus($kpi['overdue_rate'],     [10, null], [30, null], 'lower_better');
    $statusBg = fn ($s) => [
        'green' => 'bg-emerald-50/60 border-emerald-200',
        'amber' => 'bg-amber-50/60 border-amber-200',
        'red'   => 'bg-rose-50/60 border-rose-200',
    ][$s] ?? 'bg-slate-50 border-slate-200';
    $statusDot = fn ($s) => [
        'green' => 'bg-emerald-500',
        'amber' => 'bg-amber-500',
        'red'   => 'bg-rose-500',
    ][$s] ?? 'bg-slate-300';
@endphp

{{-- ── KPI strip ── --}}
<div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-5">
    <div class="rounded-xl border {{ $statusBg($yoyStatus) }} p-4">
        <div class="flex items-center justify-between mb-1">
            <span class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Revenue YoY</span>
            <span class="w-2 h-2 rounded-full {{ $statusDot($yoyStatus) }}"></span>
        </div>
        <div class="text-xl font-bold text-slate-900 mono">{{ ($kpi['yoy_pct'] >= 0 ? '+' : '') }}{{ number_format($kpi['yoy_pct'], 1) }}%</div>
        <div class="text-[10px] text-slate-500 mt-0.5 mono">prev {{ number_format($kpi['revenue_prev'], 0) }}</div>
    </div>
    <div class="rounded-xl border {{ $statusBg($gmStatus) }} p-4">
        <div class="flex items-center justify-between mb-1">
            <span class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Gross Margin</span>
            <span class="w-2 h-2 rounded-full {{ $statusDot($gmStatus) }}"></span>
        </div>
        <div class="text-xl font-bold text-slate-900 mono">{{ number_format($kpi['gross_margin_pct'], 1) }}%</div>
        <div class="text-[10px] text-slate-500 mt-0.5 mono">gross {{ number_format($kpi['gross_profit'], 0) }}</div>
    </div>
    <div class="rounded-xl border {{ $statusBg($ebitdaStatus) }} p-4">
        <div class="flex items-center justify-between mb-1">
            <span class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">EBITDA Margin</span>
            <span class="w-2 h-2 rounded-full {{ $statusDot($ebitdaStatus) }}"></span>
        </div>
        <div class="text-xl font-bold text-slate-900 mono">{{ ($kpi['ebitda_margin_pct'] >= 0 ? '+' : '') }}{{ number_format($kpi['ebitda_margin_pct'], 1) }}%</div>
        <div class="text-[10px] text-slate-500 mt-0.5 mono">ebitda {{ number_format($kpi['ebitda'], 0) }}</div>
    </div>
    <div class="rounded-xl border {{ $statusBg($dsoStatus) }} p-4">
        <div class="flex items-center justify-between mb-1">
            <span class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">DSO</span>
            <span class="w-2 h-2 rounded-full {{ $statusDot($dsoStatus) }}"></span>
        </div>
        <div class="text-xl font-bold text-slate-900 mono">{{ number_format($kpi['dso'], 0) }}d</div>
        <div class="text-[10px] text-slate-500 mt-0.5 mono">AR {{ number_format($kpi['total_ar'], 0) }}</div>
    </div>
    <div class="rounded-xl border {{ $statusBg($overdueStatus) }} p-4">
        <div class="flex items-center justify-between mb-1">
            <span class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Overdue Rate</span>
            <span class="w-2 h-2 rounded-full {{ $statusDot($overdueStatus) }}"></span>
        </div>
        <div class="text-xl font-bold text-slate-900 mono">{{ number_format($kpi['overdue_rate'], 1) }}%</div>
        <div class="text-[10px] text-slate-500 mt-0.5 mono">{{ number_format($kpi['overdue_ar'], 0) }} overdue</div>
    </div>
</div>

{{-- ── P&L summary + revenue card ── --}}
<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-5">
    <div class="panel p-5">
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-sm font-semibold text-slate-900">Profitability snapshot · last 30 days</h2>
            <a href="/demo/sandbox/profit-loss" class="text-[11px] text-amber-700 hover:text-amber-900 font-semibold">Full P&L →</a>
        </div>
        <table class="w-full text-[13px]">
            @foreach ([
                ['Revenue (HT)',    $kpi['revenue'],       'text-slate-900 font-semibold'],
                ['COGS',            -$kpi['cogs'],         'text-rose-700'],
                ['Gross profit',    $kpi['gross_profit'],  'text-emerald-700 font-semibold'],
                ['Operating expense',-$kpi['opex'],        'text-rose-700'],
                ['EBITDA',          $kpi['ebitda'],        $kpi['ebitda'] >= 0 ? 'text-emerald-700 font-semibold' : 'text-rose-700 font-semibold'],
            ] as [$lbl, $val, $cls])
                <tr class="border-b border-stone-100 last:border-0">
                    <td class="py-2 text-slate-600">{{ $lbl }}</td>
                    <td class="py-2 text-right mono {{ $cls }}">{{ number_format($val, 0) }} <span class="text-[10px] text-slate-400">XAF</span></td>
                </tr>
            @endforeach
        </table>
    </div>

    <div class="panel p-5">
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-sm font-semibold text-slate-900">Operations snapshot</h2>
            <a href="/demo/sandbox/revenue" class="text-[11px] text-amber-700 hover:text-amber-900 font-semibold">Revenue detail →</a>
        </div>
        <div class="grid grid-cols-2 gap-3 text-[13px]">
            <div class="bg-stone-50 rounded-lg p-3">
                <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Invoices issued</div>
                <div class="text-xl font-bold mono text-slate-900 mt-1">{{ number_format($kpi['invoice_count']) }}</div>
            </div>
            <div class="bg-stone-50 rounded-lg p-3">
                <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Active customers</div>
                <div class="text-xl font-bold mono text-slate-900 mt-1">{{ number_format($kpi['customers_active']) }}</div>
            </div>
            <div class="bg-rose-50/60 rounded-lg p-3 border border-rose-100">
                <div class="text-[10px] uppercase tracking-widest text-rose-700 font-semibold">Invoices without PO</div>
                <div class="text-xl font-bold mono text-rose-700 mt-1">{{ number_format($kpi['no_po_count']) }} <span class="text-[11px] font-normal">({{ number_format($kpi['no_po_pct'], 1) }}%)</span></div>
                <div class="text-[10px] text-rose-700/80 mt-1">GL quality flag</div>
            </div>
            <div class="bg-stone-50 rounded-lg p-3">
                <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Total receivables</div>
                <div class="text-xl font-bold mono text-slate-900 mt-1">{{ number_format($kpi['total_ar'], 0) }} <span class="text-[11px] font-normal text-slate-500">XAF</span></div>
            </div>
        </div>
    </div>
</div>

{{-- ── Try-the-demo prompt card ── --}}
<div class="panel p-5 mb-5 bg-gradient-to-r from-amber-50 to-orange-50 border border-amber-200">
    <div class="flex items-start gap-4">
        <div class="flex-1">
            <h3 class="text-sm font-semibold text-slate-900 mb-1">Try changing the data → watch the KPIs move</h3>
            <p class="text-[12px] text-slate-700 leading-relaxed mb-3">
                The numbers above are computed live from your sandbox tables. Add an invoice, book a journal entry,
                or record a payment — every KPI on this dashboard recomputes the next time the page loads.
            </p>
            <div class="flex flex-wrap gap-2">
                <a href="/demo/sandbox/data/invoices" class="inline-flex items-center gap-1.5 text-[11px] font-semibold text-amber-900 bg-white border border-amber-300 hover:bg-amber-50 rounded-full px-3 py-1.5 transition">+ Add invoice (no PO)</a>
                <a href="/demo/sandbox/data/journals" class="inline-flex items-center gap-1.5 text-[11px] font-semibold text-amber-900 bg-white border border-amber-300 hover:bg-amber-50 rounded-full px-3 py-1.5 transition">+ Book journal entry</a>
                <a href="/demo/sandbox/data/payments" class="inline-flex items-center gap-1.5 text-[11px] font-semibold text-amber-900 bg-white border border-amber-300 hover:bg-amber-50 rounded-full px-3 py-1.5 transition">+ Record payment</a>
                <a href="/demo/sandbox/console" class="inline-flex items-center gap-1.5 text-[11px] font-semibold text-white bg-stone-900 hover:bg-stone-800 rounded-full px-3 py-1.5 transition">Open NL console →</a>
            </div>
        </div>
    </div>
</div>
@endsection
