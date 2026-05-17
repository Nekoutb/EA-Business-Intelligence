@extends('demo.sandbox.layout')
@section('title', 'Balance Sheet')

@section('header')
    <div>
        <div class="text-[10px] uppercase tracking-widest text-amber-700 font-semibold mb-1">Demo · Balance Sheet Review</div>
        <h1 class="text-2xl font-bold text-slate-900">Balance sheet as of {{ $data['as_of'] }}</h1>
        <p class="text-[12px] text-slate-500 mt-1">Grouped by OHADA class · all figures in XAF · live from trial balance</p>
    </div>
@endsection

@section('content')
@include('demo.sandbox._kpi-strip', ['kpis' => $kpis])

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
    <div class="panel p-5">
        <h2 class="text-sm font-semibold text-slate-900 mb-3">Assets</h2>
        @foreach (['fixed_assets', 'inventory', 'receivables', 'vat_recv', 'treasury'] as $k)
            @php $s = $data['sections'][$k]; @endphp
            <div class="mb-3 pb-2 border-b border-stone-100 last:border-0">
                <div class="flex items-center justify-between mb-1">
                    <span class="text-[11px] font-semibold uppercase tracking-wider text-slate-700">{{ $s['label'] }}</span>
                    <span class="mono font-bold text-slate-900 text-[13px]">{{ number_format($s['total'], 0) }}</span>
                </div>
                @foreach ($s['rows'] as $r)
                    <div class="flex items-center justify-between pl-3 py-0.5 text-[11px]">
                        <span class="text-slate-600 mono">{{ $r['code'] }} · {{ $r['name'] }}</span>
                        <span class="text-slate-500 mono">{{ number_format($r['balance'], 0) }}</span>
                    </div>
                @endforeach
            </div>
        @endforeach
        <div class="mt-3 pt-3 border-t-2 border-stone-300 flex justify-between">
            <span class="font-bold text-slate-900 text-sm">TOTAL ASSETS</span>
            <span class="mono font-bold text-emerald-700 text-base">{{ number_format($data['total_assets'], 0) }}</span>
        </div>
    </div>

    <div class="panel p-5">
        <h2 class="text-sm font-semibold text-slate-900 mb-3">Liabilities & Equity</h2>
        @foreach (['equity', 'payables', 'vat_pay', 'other_liabilities'] as $k)
            @php $s = $data['sections'][$k]; @endphp
            <div class="mb-3 pb-2 border-b border-stone-100 last:border-0">
                <div class="flex items-center justify-between mb-1">
                    <span class="text-[11px] font-semibold uppercase tracking-wider text-slate-700">{{ $s['label'] }}</span>
                    <span class="mono font-bold text-slate-900 text-[13px]">{{ number_format(abs($s['total']), 0) }}</span>
                </div>
                @foreach ($s['rows'] as $r)
                    <div class="flex items-center justify-between pl-3 py-0.5 text-[11px]">
                        <span class="text-slate-600 mono">{{ $r['code'] }} · {{ $r['name'] }}</span>
                        <span class="text-slate-500 mono">{{ number_format(abs($r['balance']), 0) }}</span>
                    </div>
                @endforeach
            </div>
        @endforeach
        <div class="mt-3 pt-3 border-t-2 border-stone-300 flex justify-between">
            <span class="font-bold text-slate-900 text-sm">TOTAL L+E</span>
            <span class="mono font-bold text-rose-700 text-base">{{ number_format($data['total_liabilities'] + $data['total_equity'], 0) }}</span>
        </div>
    </div>
</div>

@if (abs($data['balance_check']) > 100)
<div class="mt-4 panel p-3 border border-amber-200 bg-amber-50 text-[12px] text-amber-800">
    <strong>Balance check:</strong> Assets − (L + E) = {{ number_format($data['balance_check'], 0) }} XAF.
    A non-zero residual is normal in the demo because retained earnings (net profit YTD) hasn't been transferred from class 12 to class 11.
    The real BMS posts the year-end closing entry to clear this.
</div>
@endif
@endsection
