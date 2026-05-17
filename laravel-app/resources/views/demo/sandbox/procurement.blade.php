@extends('demo.sandbox.layout')
@section('title', 'Procurement')

@section('header')
    <div class="flex items-end justify-between gap-4">
        <div>
            <div class="text-[10px] uppercase tracking-widest text-amber-700 font-semibold mb-1">Demo · Procurement</div>
            <h1 class="text-2xl font-bold text-slate-900">Spend & PO compliance · last {{ $days }} days</h1>
            <p class="text-[12px] text-slate-500 mt-1">{{ $data['period_label'] }} · {{ $data['bill_count'] }} bills · {{ number_format($data['total_spend'], 0) }} XAF spent</p>
        </div>
        <form method="GET" class="flex items-center gap-2">
            <label class="text-[11px] uppercase tracking-widest text-slate-500 font-semibold">Window:</label>
            <select name="days" onchange="this.form.submit()" class="border border-stone-300 rounded-lg px-3 py-1.5 text-[12px] bg-white">
                @foreach ([30, 60, 90, 180, 365] as $d)
                    <option value="{{ $d }}" @selected($days === $d)>last {{ $d }} days</option>
                @endforeach
            </select>
        </form>
    </div>
@endsection

@section('content')
@include('demo.sandbox._kpi-strip', ['kpis' => $kpis])

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
    <div class="panel p-5">
        <h2 class="text-sm font-semibold text-slate-900 mb-3">Top suppliers by spend</h2>
        <table class="w-full text-[12px]">
            <thead class="text-[10px] uppercase tracking-widest text-slate-500">
                <tr class="border-b border-stone-200">
                    <th class="text-left py-2 pr-3 font-semibold">Supplier</th>
                    <th class="text-right py-2 px-3 font-semibold">Bills</th>
                    <th class="text-right py-2 px-3 font-semibold">Spend</th>
                    <th class="text-right py-2 px-3 font-semibold">Share</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
            @foreach (array_slice($data['by_supplier'], 0, 10) as $row)
                <tr>
                    <td class="py-2 pr-3 text-slate-900 font-medium">{{ $row['supplier'] }}</td>
                    <td class="py-2 px-3 text-right mono text-slate-600">{{ $row['bills'] }}</td>
                    <td class="py-2 px-3 text-right mono font-semibold text-slate-900">{{ number_format($row['spend'], 0) }}</td>
                    <td class="py-2 px-3 text-right mono text-slate-600">{{ number_format($row['share_pct'], 1) }}%</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    <div class="panel p-5">
        <h2 class="text-sm font-semibold text-slate-900 mb-3">Spend by cost category</h2>
        <table class="w-full text-[12px]">
            <thead class="text-[10px] uppercase tracking-widest text-slate-500">
                <tr class="border-b border-stone-200">
                    <th class="text-left py-2 pr-3 font-semibold">Category</th>
                    <th class="text-right py-2 px-3 font-semibold">Bills</th>
                    <th class="text-right py-2 px-3 font-semibold">Spend</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
            @foreach ($data['by_category'] as $row)
                <tr>
                    <td class="py-2 pr-3 text-slate-800">{{ $row['category'] }}</td>
                    <td class="py-2 px-3 text-right mono text-slate-600">{{ $row['bills'] }}</td>
                    <td class="py-2 px-3 text-right mono font-semibold text-slate-900">{{ number_format($row['spend'], 0) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
