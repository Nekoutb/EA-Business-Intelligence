@extends('demo.sandbox.layout')
@section('title', 'Cashflow Forecast')

@section('header')
    <div>
        <div class="text-[10px] uppercase tracking-widest text-amber-700 font-semibold mb-1">Demo · Cashflow Forecast</div>
        <h1 class="text-2xl font-bold text-slate-900">{{ $data['weeks'] }}-week cash projection</h1>
        <p class="text-[12px] text-slate-500 mt-1">Opens at current cash, projects in AR collection by due-date and AP + opex out. Updates with every payment.</p>
    </div>
@endsection

@section('content')
<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
    <div class="panel p-4">
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Opening cash</div>
        <div class="text-xl font-bold mono text-slate-900 mt-1">{{ number_format($data['opening_cash'], 0) }}</div>
    </div>
    <div class="panel p-4 border-l-4 {{ $data['final_cash'] > $data['opening_cash'] ? 'border-emerald-400' : 'border-rose-400' }}">
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Forecast end-cash · wk {{ $data['weeks'] }}</div>
        <div class="text-xl font-bold mono mt-1 {{ $data['final_cash'] > $data['opening_cash'] ? 'text-emerald-700' : 'text-rose-700' }}">{{ number_format($data['final_cash'], 0) }}</div>
    </div>
    <div class="panel p-4 border-l-4 {{ $data['min_cash'] > 0 ? 'border-emerald-400' : 'border-rose-400' }}">
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Minimum cash</div>
        <div class="text-xl font-bold mono mt-1 {{ $data['min_cash'] > 0 ? 'text-slate-900' : 'text-rose-700' }}">{{ number_format($data['min_cash'], 0) }}</div>
        <div class="text-[10px] text-slate-500 mt-0.5">trough in window</div>
    </div>
    <div class="panel p-4">
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Weekly opex baseline</div>
        <div class="text-xl font-bold mono text-slate-900 mt-1">{{ number_format($data['weekly_opex'], 0) }}</div>
    </div>
</div>

<div class="panel overflow-hidden">
    <table class="w-full text-[12px]">
        <thead class="bg-stone-50 text-[10px] uppercase tracking-widest text-slate-500">
            <tr>
                <th class="text-left py-2 px-3 font-semibold">Week of</th>
                <th class="text-right py-2 px-3 font-semibold">Inflow</th>
                <th class="text-right py-2 px-3 font-semibold">Outflow</th>
                <th class="text-right py-2 px-3 font-semibold">Net</th>
                <th class="text-right py-2 px-3 font-semibold">End cash</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-stone-100">
        @foreach ($data['forecast'] as $w)
            <tr class="hover:bg-stone-50">
                <td class="py-2 px-3 mono text-slate-700">{{ $w['week_start'] }}</td>
                <td class="py-2 px-3 text-right mono text-emerald-700">{{ $w['inflow'] > 0 ? '+' . number_format($w['inflow'], 0) : '—' }}</td>
                <td class="py-2 px-3 text-right mono text-rose-700">−{{ number_format($w['outflow'], 0) }}</td>
                <td class="py-2 px-3 text-right mono font-semibold {{ $w['net'] >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">{{ $w['net'] >= 0 ? '+' : '' }}{{ number_format($w['net'], 0) }}</td>
                <td class="py-2 px-3 text-right mono font-bold {{ $w['cash_end'] >= 0 ? 'text-slate-900' : 'text-rose-700' }}">{{ number_format($w['cash_end'], 0) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endsection
