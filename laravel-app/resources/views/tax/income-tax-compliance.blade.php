@extends('layouts.app')
@section('title', 'Income Tax Compliance')

@section('header')
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Income Tax Compliance</h1>
            <p class="text-xs text-slate-500 mt-1">Surfaces transactions whose treatment creates exposure under Cameroon CGI / OHADA rules</p>
        </div>
        @if(!empty($analysis['as_of']))
            <div class="text-[11px] text-slate-500">As of <span class="tabular-nums font-medium text-slate-700">{{ $analysis['as_of'] }}</span></div>
        @endif
    </div>
@endsection

@section('content')
    @if ($error)
        <div class="bg-rose-50 border border-rose-200 text-rose-700 rounded-xl p-4 text-sm">
            <div class="font-medium mb-1">Could not load data from Odoo</div>
            <div>{{ $error }}</div>
        </div>
    @elseif ($analysis)
        @php
            $cur     = $analysis['currency'] ?? 'XAF';
            $hv      = $analysis['high_value_cash'] ?? [];
            $thresh  = (float) ($hv['threshold_xaf'] ?? 100000);
            $count   = (int)   ($hv['count'] ?? 0);
            $total   = (float) ($hv['total_amount'] ?? 0);
            $citPct  = (float) ($hv['cit_rate_pct'] ?? 33.0);
            $whtPct  = (float) ($hv['wht_rate_pct'] ?? 16.5);
            $totPct  = (float) ($hv['total_rate_pct'] ?? 49.5);
            $citImp  = (float) ($hv['cit_impact'] ?? 0);
            $whtImp  = (float) ($hv['wht_impact'] ?? 0);
            $totImp  = (float) ($hv['total_impact'] ?? 0);
            $year    = (int)   ($analysis['period']['year'] ?? date('Y'));
        @endphp

        <div class="space-y-4">
            {{-- ──────────────── High-Value Cash Payments box ──────────────── --}}
            <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">

                {{-- Title bar --}}
                <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4 border-b border-slate-200 bg-gradient-to-r from-rose-50/60 to-white">
                    <div>
                        <div class="text-[10px] uppercase tracking-widest text-rose-700 font-semibold">Disallowed Expenses · YTD {{ $year }}</div>
                        <h2 class="text-lg font-semibold text-slate-900 mt-1">High-Value Cash Payments &gt; {{ number_format($thresh, 0, '.', ',') }} {{ $cur }}</h2>
                        <p class="text-[11px] text-slate-500 mt-1">Posted credits to cash-on-hand accounts (57*) above the threshold</p>
                    </div>
                    <span class="inline-flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-widest text-rose-700 bg-rose-100 border border-rose-300 rounded-full px-2.5 py-1">
                        <span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span>
                        Non-deductible for CIT
                    </span>
                </div>

                {{-- Headline metrics --}}
                <div class="grid grid-cols-1 md:grid-cols-3 divide-y md:divide-y-0 md:divide-x divide-slate-200">
                    <div class="p-5">
                        <p class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Number of transactions</p>
                        <p class="text-3xl font-bold text-slate-900 tabular-nums mt-2">{{ number_format($count) }}</p>
                        <p class="text-[11px] text-slate-500 mt-1">Lines crediting account 57* &gt; {{ number_format($thresh / 1000, 0) }}K {{ $cur }}</p>
                    </div>
                    <div class="p-5">
                        <p class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Total disallowed amount</p>
                        <p class="text-3xl font-bold text-slate-900 tabular-nums mt-2">
                            {{ number_format($total, 0, '.', ',') }} <span class="text-base font-semibold text-slate-500">{{ $cur }}</span>
                        </p>
                        <p class="text-[11px] text-slate-500 mt-1">Sum of credit amounts on flagged lines</p>
                    </div>
                    <div class="p-5 bg-rose-50/50">
                        <p class="text-[10px] uppercase tracking-widest text-rose-700 font-semibold">Tax exposure ({{ number_format($totPct, 1) }}%)</p>
                        <p class="text-3xl font-bold text-rose-900 tabular-nums mt-2">
                            {{ number_format($totImp, 0, '.', ',') }} <span class="text-base font-semibold text-rose-700">{{ $cur }}</span>
                        </p>
                        <p class="text-[11px] text-rose-700/80 mt-1">Combined CIT + WHT on disallowed amount</p>
                    </div>
                </div>

                {{-- Note + breakdown --}}
                <div class="border-t border-slate-200 bg-slate-50/60 px-5 py-4">
                    <div class="flex items-start gap-3">
                        <div class="text-rose-600 shrink-0 mt-0.5">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4a2 2 0 00-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z"/>
                            </svg>
                        </div>
                        <div class="flex-1 text-sm text-slate-700 leading-relaxed">
                            <p>
                                Cash payments above <strong>{{ number_format($thresh, 0, '.', ',') }} {{ $cur }}</strong>
                                are <strong class="text-rose-700">non-deductible for corporate income tax purposes</strong>
                                under Cameroon CGI. The expense is added back to taxable income, and the disallowed amount
                                is treated as a deemed distribution to shareholders, subject to withholding tax.
                            </p>
                            <p class="mt-2">
                                Total exposure assessed at <strong>{{ number_format($totPct, 1) }}%</strong> of the
                                disallowed amount, comprised of:
                            </p>
                            <div class="grid grid-cols-2 gap-3 mt-3 max-w-lg">
                                <div class="bg-white border border-slate-200 rounded-lg p-3">
                                    <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Income Tax (IS)</div>
                                    <div class="text-base font-bold text-slate-900 tabular-nums mt-1">
                                        {{ number_format($citPct, 1) }}% <span class="text-[11px] font-normal text-slate-500">on add-back</span>
                                    </div>
                                    <div class="text-[12px] text-slate-700 tabular-nums mt-0.5">
                                        = {{ number_format($citImp, 0, '.', ',') }} {{ $cur }}
                                    </div>
                                </div>
                                <div class="bg-white border border-slate-200 rounded-lg p-3">
                                    <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">WHT on Dividends</div>
                                    <div class="text-base font-bold text-slate-900 tabular-nums mt-1">
                                        {{ number_format($whtPct, 1) }}% <span class="text-[11px] font-normal text-slate-500">on deemed distribution</span>
                                    </div>
                                    <div class="text-[12px] text-slate-700 tabular-nums mt-0.5">
                                        = {{ number_format($whtImp, 0, '.', ',') }} {{ $cur }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Methodology footer --}}
            <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm text-[12px] text-slate-600 leading-relaxed">
                <strong class="text-slate-900">Methodology.</strong>
                Posted move lines whose <code class="text-[11px] bg-slate-100 px-1.5 py-0.5 rounded">account_id</code>
                belongs to an account whose <code class="text-[11px] bg-slate-100 px-1.5 py-0.5 rounded">code</code>
                starts with <code class="text-[11px] bg-slate-100 px-1.5 py-0.5 rounded">57</code>
                (cash on hand under OHADA), with individual line credit greater than
                <code class="text-[11px] bg-slate-100 px-1.5 py-0.5 rounded">{{ number_format($thresh, 0, '.', ',') }}</code>,
                in the YTD window. CIT and WHT rates above reflect the standard Cameroon CGI position; engagement-specific
                derogations should be reviewed separately.
            </div>
        </div>
    @endif
@endsection
