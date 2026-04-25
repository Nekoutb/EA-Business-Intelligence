@extends('layouts.app')
@section('title', 'Invoice Compliance')

@section('header')
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">VAT Invoice Compliance</h1>
            <p class="text-xs text-slate-500 mt-1">Conditions de fond &amp; forme — Cameroon CGI invoice-field verification</p>
        </div>
    </div>
@endsection

@section('content')
    <x-period-filter :period="$period"/>

    @if ($error)
        <div class="bg-rose-50 border border-rose-200 text-rose-700 rounded-xl p-4 text-sm">
            <div class="font-medium mb-1">Could not load data from Odoo</div>
            <div>{{ $error }}</div>
        </div>
    @elseif ($analysis)
        @php $invoice = $analysis['invoice'] ?? null; @endphp

        @if ($invoice)
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
                    <p class="text-[10px] uppercase tracking-wider text-slate-500 font-medium mb-1">Invoice #</p>
                    <p class="text-xl font-bold text-slate-900 truncate">{{ $invoice['number'] }}</p>
                </div>
                <div class="bg-brand-50 border border-brand-200 rounded-xl p-4 shadow-sm">
                    <p class="text-[10px] uppercase tracking-wider text-brand-700 font-medium mb-1">Customer</p>
                    <p class="text-xl font-bold text-brand-900 truncate">{{ $invoice['customer'] }}</p>
                </div>
                <div class="bg-slate-50 border border-slate-200 rounded-xl p-4 shadow-sm">
                    <p class="text-[10px] uppercase tracking-wider text-slate-500 font-medium mb-1">Invoice Date</p>
                    <p class="text-xl font-bold text-slate-900">{{ $invoice['date'] }}</p>
                </div>
                <div class="bg-slate-900 text-white rounded-xl p-4 shadow-sm">
                    <p class="text-[10px] uppercase tracking-wider text-white/70 font-medium mb-1">VAT Rate</p>
                    <p class="text-xl font-bold">
                        {{ $invoice['vat_rate'] !== null ? number_format($invoice['vat_rate'], 2) . ' %' : '—' }}
                    </p>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
                    <p class="text-[10px] uppercase tracking-wider text-slate-500 font-medium mb-1">Price HT</p>
                    <p class="text-lg font-semibold text-slate-900 tabular-nums">{{ number_format((float) $invoice['amount_untaxed'], 0, ',', ' ') }} XAF</p>
                </div>
                <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
                    <p class="text-[10px] uppercase tracking-wider text-slate-500 font-medium mb-1">VAT Amount</p>
                    <p class="text-lg font-semibold text-slate-900 tabular-nums">{{ number_format((float) $invoice['vat_amount'], 0, ',', ' ') }} XAF</p>
                </div>
                <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
                    <p class="text-[10px] uppercase tracking-wider text-slate-500 font-medium mb-1">Total TTC</p>
                    <p class="text-lg font-semibold text-slate-900 tabular-nums">{{ number_format((float) $invoice['amount_total'], 0, ',', ' ') }} XAF</p>
                </div>
            </div>

            <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
                <h2 class="text-sm font-semibold text-slate-900 mb-3">Invoice field verification</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-100">
                                <th class="pb-2 font-medium">Field</th>
                                <th class="pb-2 font-medium">Value</th>
                                <th class="pb-2 font-medium text-center">Status</th>
                                <th class="pb-2 font-medium">Reference</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            @foreach ($analysis['fields'] as $field)
                                @php
                                    $pass = $field['status'] === 'pass';
                                    $cls  = $pass ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800';
                                    $lbl  = $pass ? 'Present' : 'Missing';
                                @endphp
                                <tr class="hover:bg-slate-50">
                                    <td class="py-2 font-medium text-slate-900">{{ $field['label'] }}</td>
                                    <td class="py-2 text-slate-700">{{ $field['value'] }}</td>
                                    <td class="py-2 text-center">
                                        <span class="inline-block px-2 py-0.5 rounded text-[10px] font-semibold {{ $cls }}">{{ $lbl }}</span>
                                    </td>
                                    <td class="py-2 text-slate-500 text-xs">{{ $field['reference'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @else
            <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-xl p-4 text-sm">
                {{ $analysis['note'] ?? 'No invoice was found for the selected period.' }}
            </div>
        @endif
    @endif
@endsection
