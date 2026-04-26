@extends('layouts.app')
@section('title', 'Tax Console')

@section('header')
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Tax Console</h1>
            <p class="text-xs text-slate-500 mt-1">Tax-regime classification &amp; effective tax rate (Cameroon CGI / OHADA chart)</p>
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
            <div class="mt-2">Go to <a href="{{ tenant_url('/settings') }}" class="underline">Settings</a> to verify your Odoo connection.</div>
        </div>
    @elseif ($analysis)
        @php
            $cur          = $analysis['currency'] ?? 'XAF';
            $threshold    = (float) ($analysis['regime_threshold'] ?? 0);
            $regime       = $analysis['tax_regime'] ?? '—';
            $refYear      = $analysis['reference_year']   ?? null;
            $appYear      = $analysis['application_year'] ?? null;
            $taxExpense   = (float) ($analysis['tax_expense']   ?? 0);
            $pretaxAbs    = (float) ($analysis['pretax_income'] ?? 0);
            $pretaxSign   = $analysis['pretax_sign'] ?? '';
            $etr          = $analysis['effective_tax_rate'] ?? null;   // null when pretax = 0
        @endphp

        {{-- Top row: regime classification --}}
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
            <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
                <p class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-1">
                    Prior-Year Turnover{{ $refYear ? " ({$refYear})" : '' }}
                </p>
                <p class="text-[11px] text-slate-500 mb-3">Sum of trial-balance accounts 70*</p>
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

        {{-- Effective Tax Rate row --}}
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            {{-- Headline ETR card --}}
            <div class="bg-gradient-to-br from-warm-50 to-warm-100 border border-warm-300 rounded-xl p-5 shadow-sm md:col-span-1">
                <p class="text-[10px] uppercase tracking-widest text-warm-700 font-semibold mb-1">
                    Effective Tax Rate · YTD
                </p>
                <p class="text-[11px] text-warm-700/70 mb-3">Tax expense (89*) ÷ |Pretax income (6*+7*+8* ex 89*)|</p>
                <p class="text-4xl font-bold text-warm-900 tabular-nums">
                    @if(!is_null($etr))
                        {{ number_format($etr, 1) }}<span class="text-2xl font-semibold text-warm-700">%</span>
                    @else
                        —
                    @endif
                </p>
                <p class="text-[10px] text-warm-700/70 mt-2">
                    @if (is_null($etr))
                        Pretax income is zero; ETR not computable.
                    @endif
                </p>
            </div>

            {{-- Tax expense card --}}
            <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
                <p class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-1">
                    Income Tax Expense · YTD
                </p>
                <p class="text-[11px] text-slate-500 mb-3">Σ posted balance on accounts 89*</p>
                <p class="text-2xl font-bold text-slate-900 tabular-nums">
                    {{ number_format($taxExpense, 0, '.', ',') }}
                    <span class="text-sm font-semibold text-slate-500">{{ $cur }}</span>
                </p>
            </div>

            {{-- Pretax income card --}}
            <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
                <p class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-1">
                    Pretax {{ $pretaxSign === '−' ? 'Loss' : 'Income' }} · YTD
                </p>
                <p class="text-[11px] text-slate-500 mb-3">|Σ classes 6 + 7 + 8 (excl. 89*)|</p>
                <p class="text-2xl font-bold {{ $pretaxSign === '−' ? 'text-rose-700' : 'text-slate-900' }} tabular-nums">
                    {{ $pretaxSign }}{{ number_format($pretaxAbs, 0, '.', ',') }}
                    <span class="text-sm font-semibold text-slate-500">{{ $cur }}</span>
                </p>
            </div>
        </div>

        {{-- Methodology --}}
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
