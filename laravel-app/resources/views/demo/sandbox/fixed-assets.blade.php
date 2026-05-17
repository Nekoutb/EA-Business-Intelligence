@extends('demo.sandbox.layout')
@section('title', 'Fixed Assets')

@section('header')
    <div>
        <div class="text-[10px] uppercase tracking-widest text-amber-700 font-semibold mb-1">Demo · Fixed Assets</div>
        <h1 class="text-2xl font-bold text-slate-900">Asset register · {{ $data['asset_count'] }} assets</h1>
        <p class="text-[12px] text-slate-500 mt-1">As of {{ $data['as_of'] }} · straight-line depreciation · all figures in XAF</p>
    </div>
@endsection

@section('content')
@include('demo.sandbox._kpi-strip', ['kpis' => $kpis])

<div class="panel p-5 mb-4">
    <h2 class="text-sm font-semibold text-slate-900 mb-3">By category</h2>
    <table class="w-full text-[12px]">
        <thead class="text-[10px] uppercase tracking-widest text-slate-500">
            <tr class="border-b border-stone-200">
                <th class="text-left py-2 pr-3 font-semibold">Category</th>
                <th class="text-right py-2 px-3 font-semibold">Count</th>
                <th class="text-right py-2 px-3 font-semibold">Cost</th>
                <th class="text-right py-2 px-3 font-semibold">Acc. Dep</th>
                <th class="text-right py-2 px-3 font-semibold">NBV</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-stone-100">
        @foreach ($data['by_category'] as $cat)
            <tr>
                <td class="py-2 pr-3 font-semibold text-slate-800">{{ $cat['category'] }}</td>
                <td class="py-2 px-3 text-right mono text-slate-600">{{ $cat['count'] }}</td>
                <td class="py-2 px-3 text-right mono text-slate-900">{{ number_format($cat['acquisition_cost'], 0) }}</td>
                <td class="py-2 px-3 text-right mono text-rose-700">−{{ number_format($cat['accumulated_dep'], 0) }}</td>
                <td class="py-2 px-3 text-right mono font-semibold text-emerald-700">{{ number_format($cat['net_book_value'], 0) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>

<div class="panel overflow-hidden">
    <div class="px-4 py-3 border-b border-stone-200">
        <h2 class="text-sm font-semibold text-slate-900">Asset register</h2>
    </div>
    <table class="w-full text-[12px]">
        <thead class="bg-stone-50 text-[10px] uppercase tracking-widest text-slate-500">
            <tr>
                <th class="text-left py-2 px-3 font-semibold">Code</th>
                <th class="text-left py-2 px-3 font-semibold">Asset</th>
                <th class="text-left py-2 px-3 font-semibold">Category</th>
                <th class="text-left py-2 px-3 font-semibold">Acquired</th>
                <th class="text-right py-2 px-3 font-semibold">Cost</th>
                <th class="text-right py-2 px-3 font-semibold">Acc. Dep</th>
                <th class="text-right py-2 px-3 font-semibold">NBV</th>
                <th class="text-right py-2 px-3 font-semibold">Depr %</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-stone-100">
        @foreach ($data['rows'] as $a)
            <tr class="hover:bg-stone-50">
                <td class="py-2 px-3 mono font-semibold text-slate-700">{{ $a['asset_code'] }}</td>
                <td class="py-2 px-3 text-slate-900">{{ $a['name'] }}</td>
                <td class="py-2 px-3 text-slate-600">{{ $a['category'] }}</td>
                <td class="py-2 px-3 mono text-slate-500">{{ $a['acquisition_date'] }}</td>
                <td class="py-2 px-3 text-right mono text-slate-900">{{ number_format($a['acquisition_cost'], 0) }}</td>
                <td class="py-2 px-3 text-right mono text-rose-700">−{{ number_format($a['accumulated_dep'], 0) }}</td>
                <td class="py-2 px-3 text-right mono font-semibold text-emerald-700">{{ number_format($a['net_book_value'], 0) }}</td>
                <td class="py-2 px-3 text-right mono text-slate-500">{{ number_format($a['pct_depreciated'], 0) }}%</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endsection
