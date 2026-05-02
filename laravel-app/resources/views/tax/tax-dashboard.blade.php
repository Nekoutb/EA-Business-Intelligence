@extends('layouts.app')
@section('title', 'Tax Dashboard')

@section('header')
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Tax Dashboard</h1>
            <p class="text-xs text-slate-500 mt-1">Tax-regime classification, effective tax rate &amp; obligations calendar (Cameroon CGI / OHADA)</p>
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
            $etr          = $analysis['effective_tax_rate'] ?? null;
        @endphp

        {{-- ─────────── Combined Regime + Turnover box ─────────── --}}
        <div class="bg-gradient-to-br from-brand-50 to-white border border-brand-200 rounded-xl shadow-sm overflow-hidden mb-4">
            <div class="grid grid-cols-1 md:grid-cols-[1fr_1.2fr] divide-y md:divide-y-0 md:divide-x divide-brand-200">
                {{-- Left: prior-year turnover --}}
                <div class="p-5">
                    <p class="text-[10px] uppercase tracking-widest text-brand-700 font-semibold mb-1">
                        Prior-Year Turnover{{ $refYear ? " · {$refYear}" : '' }}
                    </p>
                    <p class="text-[11px] text-brand-700/70 mb-3">Σ trial-balance accounts 70*</p>
                    <p class="text-3xl font-bold text-brand-900 tabular-nums">
                        {{ number_format($threshold / 1_000_000, 1) }}<span class="text-base text-brand-700 font-semibold"> M</span>
                        <span class="text-base font-semibold text-brand-700">{{ $cur }}</span>
                    </p>
                </div>
                {{-- Right: applicable regime --}}
                <div class="p-5 bg-brand-100/30">
                    <p class="text-[10px] uppercase tracking-widest text-brand-700 font-semibold mb-1">
                        {{ $appYear ? "{$appYear} " : '' }}Applicable Tax Regime
                    </p>
                    <p class="text-[11px] text-brand-700/70 mb-3">Determined by prior-year annual assessment</p>
                    <p class="text-2xl font-bold text-brand-900">{{ $regime }}</p>
                    <p class="text-[11px] text-brand-700/80 mt-2">
                        Thresholds:
                        <span class="font-medium">≥ 50M</span> Real ·
                        <span class="font-medium">10M – 50M</span> Simplified ·
                        <span class="font-medium">&lt; 10M</span> Impôt Libératoire
                    </p>
                </div>
            </div>
        </div>

        {{-- ─────────── Effective Tax Rate row ─────────── --}}
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
            <div class="bg-gradient-to-br from-warm-50 to-warm-100 border border-warm-300 rounded-xl p-5 shadow-sm">
                <p class="text-[10px] uppercase tracking-widest text-warm-700 font-semibold mb-1">Effective Tax Rate · YTD</p>
                <p class="text-[11px] text-warm-700/70 mb-3">Tax expense (89*) ÷ |Pretax income (6+7+8 ex 89)|</p>
                <p class="text-4xl font-bold text-warm-900 tabular-nums">
                    @if(!is_null($etr))
                        {{ number_format($etr, 1) }}<span class="text-2xl font-semibold text-warm-700">%</span>
                    @else
                        —
                    @endif
                </p>
                <p class="text-[10px] text-warm-700/70 mt-2">
                    @if (is_null($etr)) Pretax income is zero; ETR not computable. @endif
                </p>
            </div>

            <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
                <p class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-1">Income Tax Expense · YTD</p>
                <p class="text-[11px] text-slate-500 mb-3">Σ posted balance on accounts 89*</p>
                <p class="text-2xl font-bold text-slate-900 tabular-nums">
                    {{ number_format($taxExpense, 0, '.', ',') }}
                    <span class="text-sm font-semibold text-slate-500">{{ $cur }}</span>
                </p>
            </div>

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

        {{-- ─────────── Tax Calendar (moved from /tax-calendar) ─────────── --}}
        @php
            $year = $appYear ?: (int) date('Y');
            $rows = [
                [
                    'title'    => 'Monthly Tax Return',
                    'subtitle' => '15th of M+1 (DGI portal)',
                    'due'      => [
                        1  => "Declaration & payment of Dec ".($year-1)." VAT collected (Régime du Réel)",
                        5  => "Declaration & payment of Jan {$year} VAT collected (Régime du Réel)",
                        9  => "Declaration & payment of Feb {$year} VAT collected (Régime du Réel)",
                        13 => "Declaration & payment of Mar {$year} VAT collected (Régime du Réel)",
                        17 => "Declaration & payment of Apr {$year} VAT collected (Régime du Réel)",
                        21 => "Declaration & payment of May {$year} VAT collected (Régime du Réel)",
                        26 => "Declaration & payment of Jun {$year} VAT collected (Régime du Réel)",
                        30 => "Declaration & payment of Jul {$year} VAT collected (Régime du Réel)",
                        34 => "Declaration & payment of Aug {$year} VAT collected (Régime du Réel)",
                        39 => "Declaration & payment of Sep {$year} VAT collected (Régime du Réel)",
                        43 => "Declaration & payment of Oct {$year} VAT collected (Régime du Réel)",
                        48 => "Declaration & payment of Nov {$year} VAT collected (Régime du Réel)",
                    ],
                ],
                [
                    'title'    => 'Definitive Prorata',
                    'subtitle' => '31 March (DGI portal)',
                    'due'      => [10 => "Compute definitive prorata of VAT deduction for ".($year-1)],
                ],
                [
                    'title'    => 'Quarterly VAT Refund',
                    'subtitle' => 'End of quarter (if applicable)',
                    'due'      => [
                        13 => 'End of Q1 — Quarterly VAT refund claim (if applicable)',
                        26 => 'End of Q2 — Quarterly VAT refund claim (if applicable)',
                        39 => 'End of Q3 — Quarterly VAT refund claim (if applicable)',
                        52 => 'End of Q4 — Quarterly VAT refund claim (if applicable)',
                    ],
                ],
                [
                    'title'    => 'Year-End Balance Test',
                    'subtitle' => '31 December (Internal)',
                    'due'      => [52 => 'Year-end balance test on accounts 4431 / 4432 / 4433 / 4434'],
                ],
            ];
        @endphp

        <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
            <div class="flex flex-wrap items-baseline justify-between gap-3 mb-3">
                <div>
                    <h2 class="text-sm font-semibold text-slate-900">{{ $year }} VAT Tax Calendar</h2>
                    <p class="text-[11px] text-slate-500">52-week obligations grid · hover a marker for the deadline detail</p>
                </div>
                <div class="flex gap-4 text-xs">
                    <div class="flex items-center gap-1.5">
                        <div class="w-3 h-3 bg-amber-300 border border-amber-500 rounded-sm"></div>
                        <span class="text-slate-600">Due Date</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <div class="w-3 h-3 bg-slate-200 border border-slate-300 rounded-sm"></div>
                        <span class="text-slate-600">Regular Week</span>
                    </div>
                </div>
            </div>

            <div class="space-y-1.5">
                @foreach ($rows as $row)
                    <div class="flex gap-2 items-stretch">
                        <div class="w-48 flex-shrink-0 pr-2 border-r border-slate-200">
                            <div class="text-sm font-semibold text-slate-900">{{ $row['title'] }}</div>
                            <div class="text-[11px] text-slate-500">{{ $row['subtitle'] }}</div>
                        </div>
                        <div class="grid flex-1 gap-0.5" style="grid-template-columns: repeat(52, minmax(0, 1fr));">
                            @for ($w = 1; $w <= 52; $w++)
                                @php $due = $row['due'][$w] ?? null; @endphp
                                <div class="relative group">
                                    <div class="border rounded h-6 flex items-center justify-center text-[9px] font-bold cursor-help
                                                {{ $due ? 'bg-amber-300 border-amber-500 text-amber-900' : 'bg-slate-100 border-slate-300 text-slate-500' }}">
                                        W{{ $w }}
                                    </div>
                                    @if ($due)
                                        <div class="absolute bottom-full left-1/2 -translate-x-1/2 mb-2 hidden group-hover:block bg-slate-900 text-white text-[10px] rounded p-2 w-44 z-10 shadow-lg whitespace-normal">
                                            {{ $due }}
                                        </div>
                                    @endif
                                </div>
                            @endfor
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="mt-4 pt-4 border-t border-slate-100 text-[11px] text-slate-500">
                Currently classified as <strong class="text-slate-700">{{ $regime }}</strong>
                based on {{ number_format($threshold / 1_000_000, 1) }}M {{ $cur }}
                of {{ $refYear ?? 'prior-year' }} turnover.
            </div>
        </div>
    @endif
@endsection
