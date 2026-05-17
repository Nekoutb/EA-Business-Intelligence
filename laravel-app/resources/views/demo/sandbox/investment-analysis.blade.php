@extends('demo.sandbox.layout')
@section('title', 'Investment Analysis')

@section('header')
    <div>
        <div class="text-[10px] uppercase tracking-widest text-amber-700 font-semibold mb-1">Demo · Investment Analysis</div>
        <h1 class="text-2xl font-bold text-slate-900">Sample 5-year project · NPV / IRR / Payback</h1>
        <p class="text-[12px] text-slate-500 mt-1">Discount rate {{ number_format($data['discount_rate'] * 100, 1) }}% · all figures in XAF · template based on a typical capex investment</p>
    </div>
@endsection

@section('content')
<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
    <div class="panel p-4 border-l-4 {{ $data['npv'] > 0 ? 'border-emerald-400' : 'border-rose-400' }}">
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">NPV</div>
        <div class="text-xl font-bold mono mt-1 {{ $data['npv'] > 0 ? 'text-emerald-700' : 'text-rose-700' }}">{{ ($data['npv'] >= 0 ? '+' : '') }}{{ number_format($data['npv'], 0) }}</div>
        <div class="text-[10px] text-slate-500 mt-0.5">@ {{ number_format($data['discount_rate'] * 100, 1) }}%</div>
    </div>
    <div class="panel p-4 border-l-4 {{ $data['irr'] > $data['discount_rate'] ? 'border-emerald-400' : 'border-rose-400' }}">
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">IRR</div>
        <div class="text-xl font-bold mono mt-1 {{ $data['irr'] > $data['discount_rate'] ? 'text-emerald-700' : 'text-rose-700' }}">{{ number_format($data['irr'] * 100, 1) }}%</div>
    </div>
    <div class="panel p-4">
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Payback</div>
        <div class="text-xl font-bold mono text-slate-900 mt-1">{{ $data['payback_years'] !== null ? number_format($data['payback_years'], 1) . ' yr' : '—' }}</div>
    </div>
    <div class="panel p-4">
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Verdict</div>
        <div class="text-sm font-bold mono mt-1 {{ $data['npv'] > 0 ? 'text-emerald-700' : 'text-rose-700' }}">{{ $data['decision'] }}</div>
    </div>
</div>

<div class="panel overflow-hidden">
    <table class="w-full text-[12px]">
        <thead class="bg-stone-50 text-[10px] uppercase tracking-widest text-slate-500">
            <tr>
                <th class="text-left py-2 px-3 font-semibold w-20">Year</th>
                <th class="text-left py-2 px-3 font-semibold">Description</th>
                <th class="text-right py-2 px-3 font-semibold">Nominal cash</th>
                <th class="text-right py-2 px-3 font-semibold">Discount factor</th>
                <th class="text-right py-2 px-3 font-semibold">Present value</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-stone-100">
        @foreach ($data['cash_flows'] as $r)
            <tr class="hover:bg-stone-50">
                <td class="py-2 px-3 mono font-semibold text-slate-700">Y{{ $r['year'] }}</td>
                <td class="py-2 px-3 text-slate-700">{{ $r['label'] }}</td>
                <td class="py-2 px-3 text-right mono {{ $r['cash'] >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">{{ ($r['cash'] >= 0 ? '+' : '') }}{{ number_format($r['cash'], 0) }}</td>
                <td class="py-2 px-3 text-right mono text-slate-500">{{ number_format($r['discount_factor'], 4) }}</td>
                <td class="py-2 px-3 text-right mono font-semibold {{ $r['present_value'] >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">{{ ($r['present_value'] >= 0 ? '+' : '') }}{{ number_format($r['present_value'], 0) }}</td>
            </tr>
        @endforeach
        </tbody>
        <tfoot class="bg-stone-100 text-[12px] font-bold">
            <tr>
                <td colspan="2" class="py-2 px-3 text-right text-slate-900">TOTAL</td>
                <td class="py-2 px-3 text-right mono text-slate-900">{{ number_format($data['total_nominal'], 0) }}</td>
                <td></td>
                <td class="py-2 px-3 text-right mono {{ $data['npv'] >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">{{ number_format($data['npv'], 0) }}</td>
            </tr>
        </tfoot>
    </table>
</div>
@endsection
