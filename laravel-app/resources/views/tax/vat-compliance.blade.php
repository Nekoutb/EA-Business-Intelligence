@extends('layouts.app')
@section('title', 'VAT Compliance')

@section('header')
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">VAT Compliance</h1>
            <p class="text-xs text-slate-500 mt-1">Tax-regime classification based on prior-year trial-balance turnover (Cameroon CGI)</p>
        </div>
    </div>
@endsection

@section('content')
    @if ($error)
        <div class="bg-rose-50 border border-rose-200 text-rose-700 rounded-xl p-4 text-sm">
            <div class="font-medium mb-1">Could not load data from Odoo</div>
            <div>{{ $error }}</div>
            <div class="mt-2">Go to <a href="{{ tenant_url('/settings') }}" class="underline">Settings</a> to verify your Odoo connection.</div>
        </div>
    @elseif ($analysis)
        @php
            $cur        = $analysis['currency'] ?? 'XAF';
            $threshold  = (float) ($analysis['regime_threshold'] ?? 0);
            $regime     = $analysis['tax_regime'] ?? '—';
            $refYear    = $analysis['reference_year'] ?? null;
            $appYear    = $analysis['application_year'] ?? null;
        @endphp

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
            <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
                <p class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-1">
                    Prior-Year Turnover{{ $refYear ? " ({$refYear})" : '' }}
                </p>
                <p class="text-[11px] text-slate-500 mb-3">Sum of trial-balance accounts 70XX</p>
                <p class="text-3xl font-bold text-slate-900 tabular-nums">
                    {{ number_format($threshold / 1_000_000, 1) }} M
                    <span class="text-base font-semibold text-slate-500">{{ $cur }}</span>
                </p>
            </div>

            <div class="bg-gradient-to-br from-brand-50 to-brand-100 border border-brand-300 rounded-xl p-5 shadow-sm">
                <p class="text-[10px] uppercase tracking-widest text-brand-700 font-semibold mb-1">
                    {{ $appYear ? "{$appYear} " : '' }}Applicable Tax Regime
                </p>
                <p class="text-[11px] text-brand-700/70 mb-3">Based on prior-year annual assessment</p>
                <p class="text-2xl font-bold text-brand-900">{{ $regime }}</p>
            </div>
        </div>

        <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
            <h2 class="text-sm font-semibold text-slate-900 mb-3">Regime thresholds (Cameroon CGI)</h2>
            <div class="text-sm text-slate-700 space-y-1.5">
                <div class="flex items-baseline gap-2"><span class="w-32 text-xs text-slate-500 uppercase tracking-wider">≥ 50M</span> Real Regime (régime du réel)</div>
                <div class="flex items-baseline gap-2"><span class="w-32 text-xs text-slate-500 uppercase tracking-wider">10M – 50M</span> Simplified Regime (régime simplifié)</div>
                <div class="flex items-baseline gap-2"><span class="w-32 text-xs text-slate-500 uppercase tracking-wider">&lt; 10M</span> Impôt Libératoire</div>
            </div>
            <div class="mt-4 pt-4 border-t border-slate-100 text-xs text-slate-500">
                Need the obligations calendar? Open <a href="{{ tenant_url('/tax-calendar') }}" class="text-brand-700 underline">Tax Calendar</a>.
            </div>
        </div>
    @endif
@endsection
