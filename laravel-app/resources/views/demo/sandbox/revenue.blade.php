@extends('demo.sandbox.layout')
@section('title', 'Revenue')

@section('header')
    <div class="flex items-end justify-between gap-4">
        <div>
            <div class="text-[10px] uppercase tracking-widest text-amber-700 font-semibold mb-1">Demo · Revenue analysis</div>
            <h1 class="text-2xl font-bold text-slate-900">Revenue · last {{ $days }} days</h1>
            <p class="text-[12px] text-slate-500 mt-1">Period <span class="font-semibold mono">{{ $data['period_label'] }}</span></p>
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
@php
    $cur = $data['current'];
    $prv = $data['previous'];
    $yoy = $data['yoy_pct'];
    $rowsVariance = [
        ['Revenue (HT)',     $cur['revenue_ht'],   $prv['revenue_ht'],   '$'],
        ['Revenue (TTC)',    $cur['revenue_ttc'],  $prv['revenue_ttc'],  '$'],
        ['VAT collected',    $cur['vat'],          $prv['vat'],          '$'],
        ['Invoices issued',  $cur['invoice_count'],$prv['invoice_count'],'count'],
        ['Active customers', $cur['customers_active'],$prv['customers_active'],'count'],
        ['COGS',             $cur['cogs'],         $prv['cogs'],         '$'],
    ];
    $varStatus = function ($cur, $prv) {
        if ($prv == 0) return 'na';
        $pct = (($cur - $prv) / $prv) * 100;
        if ($pct >= 5)   return 'green';
        if ($pct >= -5)  return 'amber';
        return 'red';
    };
    $varDot = fn ($s) => ['green'=>'bg-emerald-500','amber'=>'bg-amber-500','red'=>'bg-rose-500'][$s] ?? 'bg-slate-300';
@endphp

<div class="grid grid-cols-1 md:grid-cols-4 gap-3 mb-4">
    <div class="panel p-4">
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Revenue total</div>
        <div class="text-xl font-bold text-slate-900 mt-1 mono">{{ number_format($cur['revenue_ht'], 0) }}</div>
        <div class="text-[10px] text-slate-500 mt-1 mono">XAF · {{ $data['period_label'] }}</div>
    </div>
    <div class="panel p-4 {{ $yoy >= 0 ? 'border-l-4 border-emerald-400' : 'border-l-4 border-rose-400' }}">
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">vs prior period</div>
        <div class="text-xl font-bold {{ $yoy >= 0 ? 'text-emerald-700' : 'text-rose-700' }} mt-1 mono">{{ $yoy >= 0 ? '+' : '' }}{{ number_format($yoy, 1) }}%</div>
        <div class="text-[10px] text-slate-500 mt-1 mono">prev {{ number_format($prv['revenue_ht'], 0) }}</div>
    </div>
    <div class="panel p-4">
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Invoices</div>
        <div class="text-xl font-bold text-slate-900 mt-1 mono">{{ $cur['invoice_count'] }}</div>
        <div class="text-[10px] text-slate-500 mt-1 mono">prev {{ $prv['invoice_count'] }}</div>
    </div>
    <div class="panel p-4 {{ $cur['no_po_count'] > 0 ? 'border-l-4 border-rose-400' : '' }}">
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Without PO</div>
        <div class="text-xl font-bold text-rose-700 mt-1 mono">{{ $cur['no_po_count'] }}</div>
        <div class="text-[10px] text-slate-500 mt-1 mono">GL-quality flag</div>
    </div>
</div>

<div class="panel p-5 mb-4">
    <h2 class="text-sm font-semibold text-slate-900 mb-3">Variance analysis · current vs prior {{ $days }}d</h2>
    <table class="w-full text-[12px]">
        <thead class="text-[10px] uppercase tracking-widest text-slate-500">
            <tr class="border-b border-stone-200">
                <th class="text-left py-2 pr-3 font-semibold">Metric</th>
                <th class="text-right py-2 px-3 font-semibold">Current</th>
                <th class="text-right py-2 px-3 font-semibold">Prior</th>
                <th class="text-right py-2 px-3 font-semibold">Δ</th>
                <th class="text-right py-2 px-3 font-semibold">Δ %</th>
                <th class="text-center py-2 pl-3 font-semibold w-12">Status</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-stone-100">
        @foreach ($rowsVariance as [$label, $c, $p, $unit])
            @php
                $var = $c - $p;
                $pct = $p != 0 ? (($c - $p) / $p) * 100 : null;
                $st  = $varStatus($c, $p);
            @endphp
            <tr>
                <td class="py-2 pr-3 text-slate-700">{{ $label }}</td>
                <td class="py-2 px-3 text-right mono font-semibold text-slate-900">{{ number_format($c, 0) }}</td>
                <td class="py-2 px-3 text-right mono text-slate-500">{{ number_format($p, 0) }}</td>
                <td class="py-2 px-3 text-right mono text-slate-700">{{ ($var >= 0 ? '+' : '') . number_format($var, 0) }}</td>
                <td class="py-2 px-3 text-right mono {{ $pct === null ? 'text-slate-400' : ($pct >= 0 ? 'text-emerald-700' : 'text-rose-700') }}">{{ $pct === null ? '—' : ($pct >= 0 ? '+' : '') . number_format($pct, 1) . '%' }}</td>
                <td class="py-2 pl-3 text-center"><span class="inline-block w-2.5 h-2.5 rounded-full {{ $varDot($st) }}"></span></td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
    <div class="panel p-5">
        <h2 class="text-sm font-semibold text-slate-900 mb-3">Top customers · last {{ $days }} days</h2>
        <table class="w-full text-[12px]">
            <thead class="text-[10px] uppercase tracking-widest text-slate-500">
                <tr class="border-b border-stone-200">
                    <th class="text-left py-2 pr-3 font-semibold">Customer</th>
                    <th class="text-left py-2 px-3 font-semibold">Category</th>
                    <th class="text-right py-2 px-3 font-semibold">Revenue</th>
                    <th class="text-right py-2 px-3 font-semibold">Share</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
            @foreach ($data['by_customer'] as $row)
                <tr>
                    <td class="py-2 pr-3 text-slate-900 font-medium">{{ $row['customer'] }}</td>
                    <td class="py-2 px-3 text-slate-500">{{ $row['category'] }}</td>
                    <td class="py-2 px-3 text-right mono font-semibold text-slate-900">{{ number_format($row['revenue'], 0) }}</td>
                    <td class="py-2 px-3 text-right mono text-slate-600">{{ number_format($row['share_pct'], 1) }}%</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    <div class="panel p-5">
        <h2 class="text-sm font-semibold text-slate-900 mb-3">By product category</h2>
        <table class="w-full text-[12px]">
            <thead class="text-[10px] uppercase tracking-widest text-slate-500">
                <tr class="border-b border-stone-200">
                    <th class="text-left py-2 pr-3 font-semibold">Category</th>
                    <th class="text-right py-2 px-3 font-semibold">Revenue</th>
                    <th class="text-right py-2 px-3 font-semibold">COGS</th>
                    <th class="text-right py-2 px-3 font-semibold">Gross</th>
                    <th class="text-right py-2 px-3 font-semibold">Margin %</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
            @foreach ($data['by_category'] as $row)
                <tr>
                    <td class="py-2 pr-3 text-slate-900 font-medium">{{ $row['category'] }}</td>
                    <td class="py-2 px-3 text-right mono text-slate-900">{{ number_format($row['revenue'], 0) }}</td>
                    <td class="py-2 px-3 text-right mono text-slate-500">{{ number_format($row['cogs'], 0) }}</td>
                    <td class="py-2 px-3 text-right mono text-emerald-700 font-semibold">{{ number_format($row['gross'], 0) }}</td>
                    <td class="py-2 px-3 text-right mono text-slate-700">{{ number_format($row['margin_pct'], 1) }}%</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
