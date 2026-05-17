@extends('demo.sandbox.layout')
@section('title', 'Receivables')

@section('header')
    <div>
        <div class="text-[10px] uppercase tracking-widest text-amber-700 font-semibold mb-1">Demo · Receivables</div>
        <h1 class="text-2xl font-bold text-slate-900">Accounts receivable</h1>
        <p class="text-[12px] text-slate-500 mt-1">As of <span class="font-semibold mono">{{ $data['as_of'] }}</span> · all figures in XAF</p>
    </div>
@endsection

@section('content')
@include('demo.sandbox._kpi-strip', ['kpis' => $kpis])

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
    <div class="panel p-5">
        <h2 class="text-sm font-semibold text-slate-900 mb-3">Aging buckets</h2>
        <table class="w-full text-[12px]">
            <thead class="text-[10px] uppercase tracking-widest text-slate-500">
                <tr class="border-b border-stone-200">
                    <th class="text-left py-2 pr-3 font-semibold">Bucket</th>
                    <th class="text-right py-2 px-3 font-semibold">Amount</th>
                    <th class="text-right py-2 px-3 font-semibold">Share</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
            @foreach ($data['aging'] as $bucket => $amount)
                @php
                    $rowCls = match($bucket) {
                        'current' => 'text-emerald-700',
                        '1-30'    => 'text-amber-700',
                        '31-60'   => 'text-orange-700',
                        '61-90'   => 'text-rose-600',
                        '90+'     => 'text-rose-800 font-semibold',
                        default   => '',
                    };
                @endphp
                <tr>
                    <td class="py-2 pr-3 {{ $rowCls }}">{{ $bucket === 'current' ? 'Current (not yet due)' : $bucket . ' days overdue' }}</td>
                    <td class="py-2 px-3 text-right mono {{ $rowCls }}">{{ number_format($amount, 0) }}</td>
                    <td class="py-2 px-3 text-right mono text-slate-600">{{ number_format($data['aging_pct'][$bucket], 1) }}%</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <div class="mt-3 text-[11px] text-slate-600 bg-stone-50 border border-stone-200 rounded-lg p-3">
            <div class="font-semibold">{{ number_format($data['pct_gt_60'], 1) }}% past 60 days</div>
            <div>{{ number_format($data['pct_gt_90'], 1) }}% past 90 days (collection-risk band)</div>
        </div>
    </div>

    <div class="panel p-5">
        <h2 class="text-sm font-semibold text-slate-900 mb-3">Top customers by balance</h2>
        <table class="w-full text-[12px]">
            <thead class="text-[10px] uppercase tracking-widest text-slate-500">
                <tr class="border-b border-stone-200">
                    <th class="text-left py-2 pr-3 font-semibold">Customer</th>
                    <th class="text-right py-2 px-3 font-semibold">Open invoices</th>
                    <th class="text-right py-2 px-3 font-semibold">Balance</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
            @foreach (array_slice($data['by_customer'], 0, 10) as $row)
                <tr>
                    <td class="py-2 pr-3 text-slate-900 font-medium">{{ $row['customer'] }}</td>
                    <td class="py-2 px-3 text-right mono text-slate-600">{{ $row['invoice_count'] }}</td>
                    <td class="py-2 px-3 text-right mono font-semibold text-slate-900">{{ number_format($row['balance'], 0) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
