@extends('demo.sandbox.layout')
@section('title', 'Treasury')

@section('header')
    <div class="flex items-end justify-between gap-4">
        <div>
            <div class="text-[10px] uppercase tracking-widest text-amber-700 font-semibold mb-1">Demo · Treasury</div>
            <h1 class="text-2xl font-bold text-slate-900">Cash & liquidity position</h1>
            <p class="text-[12px] text-slate-500 mt-1">As of {{ $data['as_of'] }} · last {{ $days }} days movement · all figures in XAF</p>
        </div>
        <form method="GET" class="flex items-center gap-2">
            <label class="text-[11px] uppercase tracking-widest text-slate-500 font-semibold">Window:</label>
            <select name="days" onchange="this.form.submit()" class="border border-stone-300 rounded-lg px-3 py-1.5 text-[12px] bg-white">
                @foreach ([30, 60, 90, 180] as $d)
                    <option value="{{ $d }}" @selected($days === $d)>last {{ $d }} days</option>
                @endforeach
            </select>
        </form>
    </div>
@endsection

@section('content')
<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
    <div class="panel p-4 border-l-4 {{ $data['cash_total'] > 0 ? 'border-emerald-400' : 'border-rose-400' }}">
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Cash position</div>
        <div class="text-2xl font-bold mono text-slate-900 mt-1">{{ number_format($data['cash_total'], 0) }}</div>
        <div class="text-[10px] text-slate-500 mt-0.5">XAF · all bank + cash accounts</div>
    </div>
    <div class="panel p-4 border-l-4 border-emerald-400">
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Inflows · {{ $days }}d</div>
        <div class="text-xl font-bold mono text-emerald-700 mt-1">+{{ number_format($data['inflow_total'], 0) }}</div>
        <div class="text-[10px] text-slate-500 mt-0.5">customer payments</div>
    </div>
    <div class="panel p-4 border-l-4 border-rose-400">
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Outflows · {{ $days }}d</div>
        <div class="text-xl font-bold mono text-rose-700 mt-1">−{{ number_format($data['outflow_total'], 0) }}</div>
        <div class="text-[10px] text-slate-500 mt-0.5">suppliers + opex</div>
    </div>
    <div class="panel p-4 border-l-4 {{ $data['net_movement'] >= 0 ? 'border-emerald-400' : 'border-rose-400' }}">
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Net movement</div>
        <div class="text-xl font-bold mono mt-1 {{ $data['net_movement'] >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">
            {{ $data['net_movement'] >= 0 ? '+' : '' }}{{ number_format($data['net_movement'], 0) }}
        </div>
        <div class="text-[10px] text-slate-500 mt-0.5">inflows − outflows</div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
    <div class="panel p-5">
        <h2 class="text-sm font-semibold text-slate-900 mb-3">Cash by account</h2>
        <table class="w-full text-[12px]">
            <thead class="text-[10px] uppercase tracking-widest text-slate-500">
                <tr class="border-b border-stone-200">
                    <th class="text-left py-2 pr-3 font-semibold">Code</th>
                    <th class="text-left py-2 px-3 font-semibold">Account</th>
                    <th class="text-right py-2 px-3 font-semibold">Balance</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
            @forelse ($data['cash_by_account'] as $a)
                <tr>
                    <td class="py-2 pr-3 mono font-semibold text-slate-700">{{ $a['code'] }}</td>
                    <td class="py-2 px-3 text-slate-800">{{ $a['name'] }}</td>
                    <td class="py-2 px-3 text-right mono font-semibold {{ $a['balance'] >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">{{ number_format($a['balance'], 0) }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="py-4 text-center text-slate-500 italic">No cash accounts with balance.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="panel p-5">
        <h2 class="text-sm font-semibold text-slate-900 mb-3">Weekly cash trend</h2>
        <table class="w-full text-[12px]">
            <thead class="text-[10px] uppercase tracking-widest text-slate-500">
                <tr class="border-b border-stone-200">
                    <th class="text-left py-2 pr-3 font-semibold">Week of</th>
                    <th class="text-right py-2 px-3 font-semibold">Inflows</th>
                    <th class="text-right py-2 px-3 font-semibold">Outflows</th>
                    <th class="text-right py-2 px-3 font-semibold">Net</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
            @foreach ($data['weekly_trend'] as $w)
                <tr>
                    <td class="py-2 pr-3 mono text-slate-600">{{ $w['week_start'] }}</td>
                    <td class="py-2 px-3 text-right mono text-emerald-700">{{ $w['inflows'] > 0 ? '+' . number_format($w['inflows'], 0) : '—' }}</td>
                    <td class="py-2 px-3 text-right mono text-rose-700">{{ $w['outflows'] > 0 ? '−' . number_format($w['outflows'], 0) : '—' }}</td>
                    <td class="py-2 px-3 text-right mono font-semibold {{ $w['net'] >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">{{ $w['net'] >= 0 ? '+' : '' }}{{ number_format($w['net'], 0) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>

<div class="panel p-4 bg-amber-50/40 border border-amber-200">
    <div class="text-[10px] uppercase tracking-widest text-amber-800 font-semibold mb-1">Try this</div>
    <p class="text-[12px] text-slate-700">
        Use <a href="/demo/sandbox/data/payments" class="text-amber-700 underline">Payments</a> to record a customer payment — watch the Cash position + Inflows tick up the next time you load this page.
    </p>
</div>
@endsection
