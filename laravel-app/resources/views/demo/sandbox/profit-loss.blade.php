@extends('demo.sandbox.layout')
@section('title', 'Profit & Loss')

@section('header')
    <div class="flex items-end justify-between gap-4">
        <div>
            <div class="text-[10px] uppercase tracking-widest text-amber-700 font-semibold mb-1">Demo · Profit & Loss</div>
            <h1 class="text-2xl font-bold text-slate-900">P&L · last {{ $days }} days</h1>
            <p class="text-[12px] text-slate-500 mt-1">Period <span class="font-semibold mono">{{ $data['period_label'] }}</span></p>
        </div>
        <form method="GET" class="flex items-center gap-2">
            <label class="text-[11px] uppercase tracking-widest text-slate-500 font-semibold">Window:</label>
            <select name="days" onchange="this.form.submit()" class="border border-stone-300 rounded-lg px-3 py-1.5 text-[12px] bg-white">
                @foreach ([30, 60, 90, 180, 365] as $d)
                    <option value="{{ $d }}" @selected($days === $d)>last {{ $d }} days</option>
                @endforeach
            </select>
        </form>
    </div>
@endsection

@section('content')
@include('demo.sandbox._kpi-strip', ['kpis' => $kpis])

@php
    $rows = [
        ['Revenue (HT)',         $data['revenue'],       '+'],
        ['Cost of goods sold',   -$data['cogs'],         '-'],
        ['Gross profit',         $data['gross_profit'],  '='],
        ['Operating expense',    -$data['opex'],         '-'],
        ['EBITDA',               $data['ebitda'],        '='],
        ['Depreciation',         -$data['depreciation'], '-'],
        ['Net profit',           $data['net_profit'],    '='],
    ];
@endphp

<div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-5">
    <div class="lg:col-span-2 panel p-5">
        <h2 class="text-sm font-semibold text-slate-900 mb-3">Income statement · last {{ $days }} days</h2>
        <table class="w-full text-[13px]">
            @foreach ($rows as [$label, $val, $sign])
                @php
                    $isTotal = $sign === '=';
                    $cls = $isTotal
                        ? ($val >= 0 ? 'font-bold text-emerald-700 border-t border-stone-300' : 'font-bold text-rose-700 border-t border-stone-300')
                        : ($val >= 0 ? 'text-slate-900' : 'text-rose-700');
                @endphp
                <tr class="{{ $isTotal ? 'border-t border-stone-300' : 'border-b border-stone-100' }}">
                    <td class="py-2 pr-3 {{ $isTotal ? 'font-bold text-slate-900' : 'text-slate-600' }}">{{ $label }}</td>
                    <td class="py-2 text-right mono {{ $cls }}">{{ number_format($val, 0) }}</td>
                </tr>
            @endforeach
        </table>
        <p class="text-[10px] text-slate-500 mt-3 mono">All amounts in XAF</p>
    </div>

    <div class="panel p-5">
        <h2 class="text-sm font-semibold text-slate-900 mb-3">Margins</h2>
        <div class="space-y-3">
            @foreach ([
                ['Gross margin %',  $data['gross_margin_pct'],  35, 20],
                ['EBITDA margin %', $data['ebitda_margin_pct'], 10, 0],
                ['Net margin %',    $data['net_margin_pct'],    8,  0],
            ] as [$lbl, $val, $green, $red])
                @php
                    $st = $val >= $green ? 'green' : ($val >= $red ? 'amber' : 'red');
                    $bg = ['green'=>'bg-emerald-50','amber'=>'bg-amber-50','red'=>'bg-rose-50'][$st];
                    $dot = ['green'=>'bg-emerald-500','amber'=>'bg-amber-500','red'=>'bg-rose-500'][$st];
                @endphp
                <div class="{{ $bg }} rounded-lg p-3">
                    <div class="flex items-center justify-between mb-0.5">
                        <span class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">{{ $lbl }}</span>
                        <span class="w-2 h-2 rounded-full {{ $dot }}"></span>
                    </div>
                    <div class="text-xl font-bold text-slate-900 mono">{{ ($val >= 0 ? '+' : '') }}{{ number_format($val, 1) }}%</div>
                </div>
            @endforeach
        </div>
    </div>
</div>

<div class="panel p-5">
    <h2 class="text-sm font-semibold text-slate-900 mb-3">Opex breakdown · journal entries by account</h2>
    <table class="w-full text-[12px]">
        <thead class="text-[10px] uppercase tracking-widest text-slate-500">
            <tr class="border-b border-stone-200">
                <th class="text-left py-2 pr-3 font-semibold w-20">Code</th>
                <th class="text-left py-2 px-3 font-semibold">Account</th>
                <th class="text-right py-2 px-3 font-semibold">Amount (XAF)</th>
                <th class="text-right py-2 px-3 font-semibold">Share of opex</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-stone-100">
        @forelse ($data['opex_breakdown'] as $row)
            <tr>
                <td class="py-2 pr-3 mono font-semibold text-slate-700">{{ $row['code'] }}</td>
                <td class="py-2 px-3 text-slate-700">{{ $row['name'] }}</td>
                <td class="py-2 px-3 text-right mono font-semibold text-slate-900">{{ number_format($row['amount'], 0) }}</td>
                <td class="py-2 px-3 text-right mono text-slate-600">{{ $data['opex'] > 0 ? number_format(($row['amount'] / $data['opex']) * 100, 1) : 0 }}%</td>
            </tr>
        @empty
            <tr><td colspan="4" class="py-4 text-center text-slate-500 italic">No opex booked yet — try the journal entry form in the Data tables tab.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
