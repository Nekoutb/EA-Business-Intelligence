@extends('demo.sandbox.layout')
@section('title', 'Customer Insights')

@section('header')
    <div>
        <div class="text-[10px] uppercase tracking-widest text-amber-700 font-semibold mb-1">Demo · Customer Insights</div>
        <h1 class="text-2xl font-bold text-slate-900">Customer portfolio · {{ $data['customer_count'] }} accounts</h1>
        <p class="text-[12px] text-slate-500 mt-1">Segmentation, risk, lifetime value. As of {{ $data['as_of'] }}.</p>
    </div>
@endsection

@section('content')
<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
    <div class="panel p-4">
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Total customers</div>
        <div class="text-xl font-bold mono text-slate-900 mt-1">{{ $data['customer_count'] }}</div>
    </div>
    <div class="panel p-4 border-l-4 border-emerald-400">
        <div class="text-[10px] uppercase tracking-widest text-emerald-700 font-semibold">Paid up</div>
        <div class="text-xl font-bold mono text-emerald-700 mt-1">{{ $data['paid_up_count'] }}</div>
    </div>
    <div class="panel p-4 border-l-4 border-amber-400">
        <div class="text-[10px] uppercase tracking-widest text-amber-700 font-semibold">Healthy outstanding</div>
        <div class="text-xl font-bold mono text-amber-700 mt-1">{{ $data['healthy_count'] }}</div>
    </div>
    <div class="panel p-4 border-l-4 border-rose-400">
        <div class="text-[10px] uppercase tracking-widest text-rose-700 font-semibold">At risk</div>
        <div class="text-xl font-bold mono text-rose-700 mt-1">{{ $data['at_risk_count'] }}</div>
        <div class="text-[10px] text-slate-500 mt-0.5">overdue balance > 0</div>
    </div>
</div>

<div class="panel p-5 mb-4">
    <h2 class="text-sm font-semibold text-slate-900 mb-3">By category</h2>
    <table class="w-full text-[12px]">
        <thead class="text-[10px] uppercase tracking-widest text-slate-500">
            <tr class="border-b border-stone-200">
                <th class="text-left py-2 pr-3 font-semibold">Category</th>
                <th class="text-right py-2 px-3 font-semibold">Customers</th>
                <th class="text-right py-2 px-3 font-semibold">Total billed</th>
                <th class="text-right py-2 px-3 font-semibold">Outstanding</th>
                <th class="text-right py-2 px-3 font-semibold">Overdue</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-stone-100">
        @foreach ($data['by_category'] as $cat)
            <tr>
                <td class="py-2 pr-3 font-semibold text-slate-800">{{ $cat['category'] }}</td>
                <td class="py-2 px-3 text-right mono text-slate-700">{{ $cat['customer_count'] }}</td>
                <td class="py-2 px-3 text-right mono text-slate-900 font-semibold">{{ number_format($cat['total_billed'], 0) }}</td>
                <td class="py-2 px-3 text-right mono text-slate-700">{{ number_format($cat['total_outstanding'], 0) }}</td>
                <td class="py-2 px-3 text-right mono {{ $cat['total_overdue'] > 0 ? 'text-rose-700 font-semibold' : 'text-slate-500' }}">{{ number_format($cat['total_overdue'], 0) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>

<div class="panel overflow-hidden">
    <div class="px-4 py-3 border-b border-stone-200">
        <h2 class="text-sm font-semibold text-slate-900">All customers · ranked by lifetime billed</h2>
    </div>
    <table class="w-full text-[12px]">
        <thead class="bg-stone-50 text-[10px] uppercase tracking-widest text-slate-500">
            <tr>
                <th class="text-left py-2 px-3 font-semibold">Customer</th>
                <th class="text-left py-2 px-3 font-semibold">Category</th>
                <th class="text-right py-2 px-3 font-semibold">Terms</th>
                <th class="text-right py-2 px-3 font-semibold">Invoices</th>
                <th class="text-right py-2 px-3 font-semibold">Billed (HT)</th>
                <th class="text-right py-2 px-3 font-semibold">Outstanding</th>
                <th class="text-right py-2 px-3 font-semibold">Overdue</th>
                <th class="text-center py-2 px-3 font-semibold">Risk</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-stone-100">
        @foreach ($data['customers'] as $c)
            @php
                $cls = match($c['risk']) {
                    'at_risk'  => 'bg-rose-50 text-rose-700 border-rose-200',
                    'healthy'  => 'bg-amber-50 text-amber-700 border-amber-200',
                    'paid_up'  => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                    default    => 'bg-slate-50 text-slate-500 border-slate-200',
                };
                $lbl = match($c['risk']) {
                    'at_risk' => 'at risk',
                    'healthy' => 'healthy',
                    'paid_up' => 'paid up',
                    default   => '—',
                };
            @endphp
            <tr class="hover:bg-stone-50">
                <td class="py-2 px-3 font-medium text-slate-900">{{ $c['name'] }}</td>
                <td class="py-2 px-3 text-slate-600">{{ $c['category'] ?? '—' }}</td>
                <td class="py-2 px-3 text-right mono text-slate-500">{{ $c['payment_terms_days'] }}d</td>
                <td class="py-2 px-3 text-right mono text-slate-700">{{ $c['invoice_count'] }}</td>
                <td class="py-2 px-3 text-right mono font-semibold text-slate-900">{{ number_format($c['total_billed'], 0) }}</td>
                <td class="py-2 px-3 text-right mono text-slate-700">{{ number_format($c['outstanding'], 0) }}</td>
                <td class="py-2 px-3 text-right mono {{ $c['overdue'] > 0 ? 'text-rose-700 font-semibold' : 'text-slate-500' }}">{{ number_format($c['overdue'], 0) }}</td>
                <td class="py-2 px-3 text-center">
                    <span class="inline-block px-2 py-0.5 rounded-full border text-[9px] font-semibold uppercase {{ $cls }}">{{ $lbl }}</span>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endsection
