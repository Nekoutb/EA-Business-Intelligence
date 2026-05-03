@extends('layouts.app')
@section('title', 'VAT Compliance')

@section('page-title', 'VAT Compliance')
@section('content')
    <div class="bg-white border border-slate-200 rounded-xl p-8 shadow-sm">
        <div class="max-w-2xl">
            <div class="inline-flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-widest text-amber-700 bg-amber-100 border border-amber-300 rounded-full px-2.5 py-1 mb-4">
                <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                Coming next
            </div>

            <h2 class="text-xl font-semibold text-slate-900 mb-3">VAT analytics — currently in build.</h2>
            <p class="text-sm text-slate-600 leading-relaxed mb-5">
                A dedicated workspace for the VAT-specific obligations carried by the firm —
                collected vs. deductible reconciliation, monthly return packets, prorata of
                deduction (provisional and definitive), refund eligibility, and the year-end
                balance test on accounts 4431 / 4432 / 4433 / 4434.
            </p>

            <div class="border-t border-slate-200 pt-5">
                <p class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-3">In the meantime</p>
                <ul class="space-y-2 text-sm text-slate-700">
                    <li class="flex items-start gap-2">
                        <span class="text-slate-400 mt-1">→</span>
                        <span>Tax-regime classification &amp; effective tax rate live in <a href="{{ tenant_url('/tax-dashboard') }}" class="text-brand-700 font-medium underline">Tax Dashboard</a>.</span>
                    </li>
                    <li class="flex items-start gap-2">
                        <span class="text-slate-400 mt-1">→</span>
                        <span>The 52-week obligations calendar is now embedded in the <a href="{{ tenant_url('/tax-dashboard') }}" class="text-brand-700 font-medium underline">Tax Dashboard</a> as well.</span>
                    </li>
                    <li class="flex items-start gap-2">
                        <span class="text-slate-400 mt-1">→</span>
                        <span>Invoice-level CGI checks remain in <a href="{{ tenant_url('/vat-invoice-compliance') }}" class="text-brand-700 font-medium underline">Invoice Compliance</a>.</span>
                    </li>
                </ul>
            </div>
        </div>
    </div>
@endsection
