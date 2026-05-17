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
@include('demo.sandbox._kpi-strip', ['kpis' => $kpis])

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
