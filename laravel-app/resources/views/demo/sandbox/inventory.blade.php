@extends('demo.sandbox.layout')
@section('title', 'Inventory')

@section('header')
    <div>
        <div class="text-[10px] uppercase tracking-widest text-amber-700 font-semibold mb-1">Demo · Inventory</div>
        <h1 class="text-2xl font-bold text-slate-900">Stock & turnover · {{ $data['product_count'] }} SKUs</h1>
        <p class="text-[12px] text-slate-500 mt-1">As of {{ $data['as_of'] }} · 90-day rolling window · stock value at COGS · figures in XAF</p>
    </div>
@endsection

@section('content')
@include('demo.sandbox._kpi-strip', ['kpis' => $kpis])

<div class="panel overflow-hidden">
    <table class="w-full text-[12px]">
        <thead class="bg-stone-50 text-[10px] uppercase tracking-widest text-slate-500">
            <tr>
                <th class="text-left py-2 px-3 font-semibold">Product</th>
                <th class="text-left py-2 px-3 font-semibold">Category</th>
                <th class="text-right py-2 px-3 font-semibold">Unit price</th>
                <th class="text-right py-2 px-3 font-semibold">COGS</th>
                <th class="text-right py-2 px-3 font-semibold">Sold (90d)</th>
                <th class="text-right py-2 px-3 font-semibold">In stock</th>
                <th class="text-right py-2 px-3 font-semibold">Stock value</th>
                <th class="text-right py-2 px-3 font-semibold">Turnover</th>
                <th class="text-right py-2 px-3 font-semibold">Days cover</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-stone-100">
        @foreach ($data['rows'] as $row)
            @php
                $turnoverCls = $row['turnover_ratio'] >= 1.0
                    ? 'text-emerald-700'
                    : ($row['turnover_ratio'] >= 0.5 ? 'text-amber-700' : 'text-rose-700');
            @endphp
            <tr class="hover:bg-stone-50">
                <td class="py-2 px-3 font-medium text-slate-900">{{ $row['product'] }}</td>
                <td class="py-2 px-3 text-slate-600">{{ $row['category'] }}</td>
                <td class="py-2 px-3 text-right mono text-slate-900">{{ number_format($row['unit_price'], 0) }}</td>
                <td class="py-2 px-3 text-right mono text-slate-500">{{ number_format($row['default_cogs'], 0) }}</td>
                <td class="py-2 px-3 text-right mono text-slate-700">{{ number_format($row['units_sold_90d'], 0) }}</td>
                <td class="py-2 px-3 text-right mono text-slate-900 font-semibold">{{ number_format($row['units_in_stock'], 0) }}</td>
                <td class="py-2 px-3 text-right mono text-slate-900">{{ number_format($row['stock_value'], 0) }}</td>
                <td class="py-2 px-3 text-right mono font-semibold {{ $turnoverCls }}">{{ number_format($row['turnover_ratio'], 2) }}x</td>
                <td class="py-2 px-3 text-right mono text-slate-500">{{ number_format($row['days_of_cover'], 0) }}d</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>

<div class="mt-4 panel p-4 bg-stone-50/40 border border-stone-200">
    <div class="text-[10px] uppercase tracking-widest text-slate-700 font-semibold mb-1">Method note</div>
    <p class="text-[11px] text-slate-600 leading-relaxed">
        Stock-on-hand is approximated for the demo (real BMS reads it directly from Odoo Inventory).
        Opening stock per product = 2.5 months of trailing 90-day sales; current stock = opening − (½ × units sold).
        Real customers see actual stock levels with on-hand / reserved / incoming columns.
    </p>
</div>
@endsection
