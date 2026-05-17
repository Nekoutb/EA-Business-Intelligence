@extends('demo.sandbox.layout')
@section('title', 'Invoice Compliance')

@section('header')
    <div>
        <div class="text-[10px] uppercase tracking-widest text-amber-700 font-semibold mb-1">Demo · Invoice Compliance (CGI)</div>
        <h1 class="text-2xl font-bold text-slate-900">Per-invoice CGI compliance checks</h1>
        <p class="text-[12px] text-slate-500 mt-1">VAT applied correctly · customer present · PO referenced when claimed · all checks rolled into one compliance score</p>
    </div>
@endsection

@section('content')
@include('demo.sandbox._kpi-strip', ['kpis' => $kpis])

@if ($data['issue_count'] === 0)
    <div class="panel p-8 text-center bg-emerald-50/40 border border-emerald-200">
        <svg class="w-12 h-12 mx-auto text-emerald-600 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12l2 2 4-4M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <h2 class="text-base font-bold text-emerald-900 mb-1">All invoices CGI-compliant ✓</h2>
        <p class="text-[12px] text-slate-600">No issues found across the {{ $data['total_checked'] }} invoices checked.</p>
    </div>
@else
    <div class="panel overflow-hidden">
        <div class="px-4 py-3 border-b border-stone-200 bg-rose-50/40">
            <h2 class="text-sm font-semibold text-slate-900">Issues requiring attention</h2>
            <p class="text-[11px] text-slate-600 mt-0.5">Each row lists one invoice with one or more CGI/internal checks failed.</p>
        </div>
        <table class="w-full text-[12px]">
            <thead class="bg-stone-50 text-[10px] uppercase tracking-widest text-slate-500">
                <tr>
                    <th class="text-left py-2 px-3 font-semibold">Invoice</th>
                    <th class="text-left py-2 px-3 font-semibold">Date</th>
                    <th class="text-left py-2 px-3 font-semibold">Customer</th>
                    <th class="text-right py-2 px-3 font-semibold">Amount HT</th>
                    <th class="text-left py-2 px-3 font-semibold">Issues</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
            @foreach ($data['issues'] as $row)
                <tr class="hover:bg-stone-50">
                    <td class="py-2 px-3 mono font-semibold text-slate-700">{{ $row['invoice']->number }}</td>
                    <td class="py-2 px-3 mono text-slate-500">{{ $row['invoice']->invoice_date->format('Y-m-d') }}</td>
                    <td class="py-2 px-3 text-slate-700">{{ $row['invoice']->customer?->name ?? '—' }}</td>
                    <td class="py-2 px-3 text-right mono text-slate-900">{{ number_format($row['invoice']->amount_ht, 0) }}</td>
                    <td class="py-2 px-3">
                        @foreach ($row['issues'] as $iss)
                            <span class="inline-block bg-rose-50 border border-rose-200 text-rose-700 text-[10px] mono rounded px-1.5 py-0.5 mr-1">{{ $iss }}</span>
                        @endforeach
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif
@endsection
