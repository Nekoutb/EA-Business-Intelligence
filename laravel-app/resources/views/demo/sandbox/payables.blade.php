@extends('demo.sandbox.layout')
@section('title', 'Payables')

@section('header')
    <div>
        <div class="text-[10px] uppercase tracking-widest text-amber-700 font-semibold mb-1">Demo · Payables</div>
        <h1 class="text-2xl font-bold text-slate-900">Accounts payable</h1>
        <p class="text-[12px] text-slate-500 mt-1">As of <span class="font-semibold mono">{{ $data['as_of'] }}</span> · all figures in XAF</p>
    </div>
@endsection

@section('content')
@php
    $overdueStatus = $data['overdue_rate'] <= 10 ? 'green' : ($data['overdue_rate'] <= 30 ? 'amber' : 'red');
    $dpoStatus     = $data['dpo'] >= 30 ? 'green' : ($data['dpo'] >= 15 ? 'amber' : 'red');
    $bg = fn ($s) => ['green' => 'border-emerald-400', 'amber' => 'border-amber-400', 'red' => 'border-rose-400'][$s] ?? 'border-stone-200';
@endphp

<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
    <div class="panel p-4 border-l-4 border-stone-300">
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Total AP</div>
        <div class="text-xl font-bold mono text-slate-900 mt-1">{{ number_format($data['total_ap'], 0) }}</div>
        <div class="text-[10px] text-slate-500 mt-0.5">{{ $data['open_bill_count'] }} open bills</div>
    </div>
    <div class="panel p-4 border-l-4 {{ $bg($overdueStatus) }}">
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Overdue</div>
        <div class="text-xl font-bold mono text-slate-900 mt-1">{{ number_format($data['overdue_ap'], 0) }}</div>
        <div class="text-[10px] text-slate-500 mt-0.5">{{ number_format($data['overdue_rate'], 1) }}% of total</div>
    </div>
    <div class="panel p-4 border-l-4 {{ $bg($dpoStatus) }}">
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">DPO</div>
        <div class="text-xl font-bold mono text-slate-900 mt-1">{{ number_format($data['dpo'], 0) }}d</div>
        <div class="text-[10px] text-slate-500 mt-0.5">days payable outstanding</div>
    </div>
    <div class="panel p-4 border-l-4 border-stone-300">
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Suppliers with balance</div>
        <div class="text-xl font-bold mono text-slate-900 mt-1">{{ count($data['by_supplier']) }}</div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
    <div class="panel p-5">
        <h2 class="text-sm font-semibold text-slate-900 mb-3">Aging — what we owe by bucket</h2>
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
                    $cls = match($bucket) {
                        'current' => 'text-emerald-700',
                        '1-30'    => 'text-amber-700',
                        '31-60'   => 'text-orange-700',
                        '61-90'   => 'text-rose-600',
                        '90+'     => 'text-rose-800 font-semibold',
                        default   => '',
                    };
                @endphp
                <tr>
                    <td class="py-2 pr-3 {{ $cls }}">{{ $bucket === 'current' ? 'Current (not yet due)' : $bucket . ' days overdue' }}</td>
                    <td class="py-2 px-3 text-right mono {{ $cls }}">{{ number_format($amount, 0) }}</td>
                    <td class="py-2 px-3 text-right mono text-slate-600">{{ number_format($data['aging_pct'][$bucket], 1) }}%</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    <div class="panel p-5">
        <h2 class="text-sm font-semibold text-slate-900 mb-3">Top suppliers by balance</h2>
        <table class="w-full text-[12px]">
            <thead class="text-[10px] uppercase tracking-widest text-slate-500">
                <tr class="border-b border-stone-200">
                    <th class="text-left py-2 pr-3 font-semibold">Supplier</th>
                    <th class="text-right py-2 px-3 font-semibold">Open bills</th>
                    <th class="text-right py-2 px-3 font-semibold">Balance</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
            @foreach (array_slice($data['by_supplier'], 0, 10) as $row)
                <tr>
                    <td class="py-2 pr-3 text-slate-900 font-medium">{{ $row['supplier'] }}</td>
                    <td class="py-2 px-3 text-right mono text-slate-600">{{ $row['bill_count'] }}</td>
                    <td class="py-2 px-3 text-right mono font-semibold text-slate-900">{{ number_format($row['balance'], 0) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
