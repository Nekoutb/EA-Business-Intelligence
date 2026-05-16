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
@php
    $overdueRate = $data['overdue_rate'];
    $dso = $data['dso'];
    $dsoStatus     = $dso <= 30 ? 'green' : ($dso <= 60 ? 'amber' : 'red');
    $overdueStatus = $overdueRate <= 10 ? 'green' : ($overdueRate <= 30 ? 'amber' : 'red');
    $top5Status    = $data['top5_pct'] <= 50 ? 'green' : ($data['top5_pct'] <= 60 ? 'amber' : 'red');
    $bg = fn ($s) => ['green' => 'border-emerald-400','amber' => 'border-amber-400','red' => 'border-rose-400'][$s] ?? 'border-stone-200';
@endphp

<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
    <div class="panel p-4 border-l-4 border-stone-300">
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Total AR</div>
        <div class="text-xl font-bold text-slate-900 mt-1 mono">{{ number_format($data['total_ar'], 0) }}</div>
        <div class="text-[10px] text-slate-500 mt-1">{{ $data['open_invoice_count'] }} open invoices</div>
    </div>
    <div class="panel p-4 border-l-4 {{ $bg($overdueStatus) }}">
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Overdue</div>
        <div class="text-xl font-bold text-slate-900 mt-1 mono">{{ number_format($data['overdue_ar'], 0) }}</div>
        <div class="text-[10px] text-slate-500 mt-1">{{ number_format($overdueRate, 1) }}% of total</div>
    </div>
    <div class="panel p-4 border-l-4 {{ $bg($dsoStatus) }}">
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">DSO</div>
        <div class="text-xl font-bold text-slate-900 mt-1 mono">{{ number_format($dso, 0) }}d</div>
        <div class="text-[10px] text-slate-500 mt-1">days sales outstanding</div>
    </div>
    <div class="panel p-4 border-l-4 {{ $bg($top5Status) }}">
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Top 5 share</div>
        <div class="text-xl font-bold text-slate-900 mt-1 mono">{{ number_format($data['top5_pct'], 1) }}%</div>
        <div class="text-[10px] text-slate-500 mt-1">concentration risk</div>
    </div>
</div>

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
