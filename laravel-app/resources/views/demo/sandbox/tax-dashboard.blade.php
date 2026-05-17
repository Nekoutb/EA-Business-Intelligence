@extends('demo.sandbox.layout')
@section('title', 'Tax Dashboard')

@section('header')
    <div>
        <div class="text-[10px] uppercase tracking-widest text-amber-700 font-semibold mb-1">Demo · Tax Dashboard</div>
        <h1 class="text-2xl font-bold text-slate-900">Tax compliance overview</h1>
        <p class="text-[12px] text-slate-500 mt-1">As of {{ $data['as_of'] }} · all figures in XAF · next filing due {{ $data['next_filing_date'] }}</p>
    </div>
@endsection

@section('content')
<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
    <div class="panel p-4 border-l-4 border-rose-400">
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">VAT payable · current month</div>
        <div class="text-xl font-bold mono text-rose-700 mt-1">{{ number_format($data['vat_payable_current'], 0) }}</div>
        <a href="/demo/sandbox/vat-compliance" class="text-[10px] text-rose-700 underline">VAT compliance →</a>
    </div>
    <div class="panel p-4 border-l-4 border-rose-400">
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">IRPP retenu · current month</div>
        <div class="text-xl font-bold mono text-rose-700 mt-1">{{ number_format($data['irpp_payable_current'], 0) }}</div>
        <a href="/demo/sandbox/tax-return" class="text-[10px] text-rose-700 underline">Tax return →</a>
    </div>
    <div class="panel p-4 border-l-4 border-amber-400">
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">IS annual estimate</div>
        <div class="text-xl font-bold mono text-amber-700 mt-1">{{ number_format($data['is_estimated_annual'], 0) }}</div>
        <a href="/demo/sandbox/income-tax" class="text-[10px] text-amber-700 underline">Income Tax →</a>
    </div>
    <div class="panel p-4 border-l-4 {{ $data['invoice_compliance_pct'] >= 95 ? 'border-emerald-400' : ($data['invoice_compliance_pct'] >= 80 ? 'border-amber-400' : 'border-rose-400') }}">
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Invoice compliance</div>
        <div class="text-xl font-bold mono mt-1">{{ number_format($data['invoice_compliance_pct'], 1) }}%</div>
        <a href="/demo/sandbox/invoice-compliance" class="text-[10px] text-slate-700 underline">{{ $data['invoice_issues'] }} issue{{ $data['invoice_issues'] !== 1 ? 's' : '' }} →</a>
    </div>
</div>

<div class="panel p-5">
    <h2 class="text-sm font-semibold text-slate-900 mb-3">Filing calendar · last 6 months</h2>
    <table class="w-full text-[12px]">
        <thead class="text-[10px] uppercase tracking-widest text-slate-500">
            <tr class="border-b border-stone-200">
                <th class="text-left py-2 pr-3 font-semibold">Period</th>
                <th class="text-right py-2 px-3 font-semibold">VAT collected</th>
                <th class="text-right py-2 px-3 font-semibold">VAT deductible</th>
                <th class="text-right py-2 px-3 font-semibold">VAT payable</th>
                <th class="text-left py-2 px-3 font-semibold">Deadline</th>
                <th class="text-center py-2 px-3 font-semibold">Status</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-stone-100">
        @foreach ($data['vat_calendar'] as $row)
            @php
                $cls = $row['status'] === 'filed'
                    ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
                    : ($row['overdue'] ? 'bg-rose-50 text-rose-700 border-rose-200' : 'bg-amber-50 text-amber-700 border-amber-200');
                $lbl = $row['status'] === 'filed' ? 'filed' : ($row['overdue'] ? 'overdue' : 'pending');
            @endphp
            <tr>
                <td class="py-2 pr-3 mono text-slate-700">{{ $row['month_label'] }}</td>
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
