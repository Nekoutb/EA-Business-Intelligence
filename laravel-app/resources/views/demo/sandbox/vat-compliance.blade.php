@extends('demo.sandbox.layout')
@section('title', 'VAT Compliance')

@section('header')
    <div>
        <div class="text-[10px] uppercase tracking-widest text-amber-700 font-semibold mb-1">Demo · VAT Compliance</div>
        <h1 class="text-2xl font-bold text-slate-900">VAT calendar &amp; filing status</h1>
        <p class="text-[12px] text-slate-500 mt-1">19.25% standard rate · monthly filing due the 15th of the following month · last 6 periods · figures in XAF</p>
    </div>
@endsection

@section('content')
<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
    <div class="panel p-4">
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Total VAT (6m)</div>
        <div class="text-xl font-bold mono text-slate-900 mt-1">{{ number_format($data['total_vat_6m'], 0) }}</div>
    </div>
    <div class="panel p-4 border-l-4 border-emerald-400">
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Filed</div>
        <div class="text-xl font-bold mono text-emerald-700 mt-1">{{ $data['filed_count'] }}</div>
    </div>
    <div class="panel p-4 border-l-4 border-amber-400">
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Pending</div>
        <div class="text-xl font-bold mono text-amber-700 mt-1">{{ $data['pending_count'] }}</div>
    </div>
    <div class="panel p-4 border-l-4 {{ $data['overdue_count'] > 0 ? 'border-rose-400' : 'border-stone-300' }}">
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Overdue</div>
        <div class="text-xl font-bold mono {{ $data['overdue_count'] > 0 ? 'text-rose-700' : 'text-slate-900' }} mt-1">{{ $data['overdue_count'] }}</div>
    </div>
</div>

<div class="panel overflow-hidden">
    <table class="w-full text-[12px]">
        <thead class="bg-stone-50 text-[10px] uppercase tracking-widest text-slate-500">
            <tr>
                <th class="text-left py-2 px-3 font-semibold">Period</th>
                <th class="text-right py-2 px-3 font-semibold">VAT collected</th>
                <th class="text-right py-2 px-3 font-semibold">VAT deductible</th>
                <th class="text-right py-2 px-3 font-semibold">VAT payable</th>
                <th class="text-left py-2 px-3 font-semibold">Filing deadline</th>
                <th class="text-center py-2 px-3 font-semibold">Status</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-stone-100">
        @foreach ($data['calendar'] as $row)
            @php
                $cls = $row['status'] === 'filed'
                    ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
                    : ($row['overdue'] ? 'bg-rose-50 text-rose-700 border-rose-200' : 'bg-amber-50 text-amber-700 border-amber-200');
                $lbl = $row['status'] === 'filed' ? 'filed' : ($row['overdue'] ? 'overdue' : 'pending');
            @endphp
            <tr class="hover:bg-stone-50">
                <td class="py-2 px-3 mono font-semibold text-slate-800">{{ $row['month_label'] }}</td>
                <td class="py-2 px-3 text-right mono text-slate-700">{{ number_format($row['vat_collected'], 0) }}</td>
                <td class="py-2 px-3 text-right mono text-slate-500">{{ number_format($row['vat_deductible'], 0) }}</td>
                <td class="py-2 px-3 text-right mono font-semibold text-slate-900">{{ number_format($row['vat_payable'], 0) }}</td>
                <td class="py-2 px-3 mono text-slate-600">{{ $row['filing_deadline'] }}</td>
                <td class="py-2 px-3 text-center">
                    <span class="inline-block px-2 py-0.5 rounded-full border text-[9px] font-semibold uppercase {{ $cls }}">{{ $lbl }}</span>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endsection
