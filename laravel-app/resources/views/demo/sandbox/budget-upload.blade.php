@extends('demo.sandbox.layout')
@section('title', 'Budget Upload')

@section('header')
    <div>
        <div class="text-[10px] uppercase tracking-widest text-amber-700 font-semibold mb-1">Demo · Budget Upload</div>
        <h1 class="text-2xl font-bold text-slate-900">Annual budget vs YTD actual</h1>
        <p class="text-[12px] text-slate-500 mt-1">Sample budget line for the seeded business. Real BMS lets the operator upload a CSV with their full plan.</p>
    </div>
@endsection

@section('content')
@include('demo.sandbox._kpi-strip', ['kpis' => $kpis])

<div class="panel p-5">
    <div class="flex items-center justify-between mb-3">
        <h2 class="text-sm font-semibold text-slate-900">Budget vs YTD actual · per account</h2>
        <a href="#" onclick="alert('Demo: CSV upload disabled. In the real BMS this opens the budget upload modal.')" class="text-[11px] text-amber-700 hover:underline font-semibold">+ Upload CSV (demo)</a>
    </div>
    <table class="w-full text-[12px]">
        <thead class="text-[10px] uppercase tracking-widest text-slate-500">
            <tr class="border-b border-stone-200">
                <th class="text-left py-2 pr-3 font-semibold">Account</th>
                <th class="text-left py-2 px-3 font-semibold">Label</th>
                <th class="text-right py-2 px-3 font-semibold">Monthly budget</th>
                <th class="text-right py-2 px-3 font-semibold">YTD budget (3m)</th>
                <th class="text-right py-2 px-3 font-semibold">YTD actual</th>
                <th class="text-right py-2 px-3 font-semibold">Variance</th>
                <th class="text-right py-2 px-3 font-semibold">Var %</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-stone-100">
        @foreach ($data['sample_budget_lines'] as $line)
            @php
                $ytdBudget = $line['monthly_budget'] * 3;
                $var       = $line['ytd_actual'] - $ytdBudget;
                $varPct    = $ytdBudget != 0 ? ($var / $ytdBudget) * 100 : 0;
                $favourable = str_starts_with((string) $line['account'], '70')
                    ? $var >= 0
                    : $var <= 0;
                $cls = $favourable ? 'text-emerald-700' : 'text-rose-700';
            @endphp
            <tr>
                <td class="py-2 pr-3 mono font-semibold text-slate-700">{{ $line['account'] }}</td>
                <td class="py-2 px-3 text-slate-800">{{ $line['label'] }}</td>
                <td class="py-2 px-3 text-right mono text-slate-700">{{ number_format($line['monthly_budget'], 0) }}</td>
                <td class="py-2 px-3 text-right mono text-slate-700">{{ number_format($ytdBudget, 0) }}</td>
                <td class="py-2 px-3 text-right mono font-semibold text-slate-900">{{ number_format($line['ytd_actual'], 0) }}</td>
                <td class="py-2 px-3 text-right mono {{ $cls }}">{{ ($var >= 0 ? '+' : '') . number_format($var, 0) }}</td>
                <td class="py-2 px-3 text-right mono {{ $cls }}">{{ ($varPct >= 0 ? '+' : '') . number_format($varPct, 1) }}%</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>

<div class="mt-4 panel p-4 bg-stone-50/40 border border-stone-200">
    <div class="text-[10px] uppercase tracking-widest text-slate-700 font-semibold mb-1">Real BMS upload format</div>
    <p class="text-[11px] text-slate-600 leading-relaxed">
        Production users upload a CSV with columns:
        <code class="bg-white border border-stone-200 rounded px-1 py-0.5 mono">account_code, label, jan, feb, mar, ..., dec</code>.
        The Forecast + every module's variance column reads from the locked baseline this upload creates.
    </p>
</div>
@endsection
