@extends('layouts.app')
@section('title', 'Tax Calendar')

@section('header')
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Tax Calendar</h1>
            <p class="text-xs text-slate-500 mt-1">52-week obligations calendar — VAT returns, prorata, refunds, year-end balance test</p>
        </div>
    </div>
@endsection

@section('content')
    @if ($error)
        <div class="bg-rose-50 border border-rose-200 text-rose-700 rounded-xl p-4 text-sm">{{ $error }}</div>
    @endif

    @php
        $year = (int) date('Y');
        $rows = [
            [
                'title'    => 'Monthly VAT Return',
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
                'due'      => [52 => 'Year-end balance test on accounts 4431/4432/4433/4434'],
            ],
        ];
    @endphp

    <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
        <h2 class="text-sm font-semibold text-slate-900 mb-3">{{ $year }} VAT Tax Calendar</h2>

        <div class="flex gap-4 mb-3 text-xs">
            <div class="flex items-center gap-1.5">
                <div class="w-3 h-3 bg-amber-300 border border-amber-500 rounded-sm"></div>
                <span class="text-slate-600">Due Date</span>
            </div>
            <div class="flex items-center gap-1.5">
                <div class="w-3 h-3 bg-slate-200 border border-slate-300 rounded-sm"></div>
                <span class="text-slate-600">Regular Week</span>
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
    </div>

    @if ($analysis)
        <div class="mt-4 text-xs text-slate-500">
            Currently classified as <strong>{{ $analysis['tax_regime'] }}</strong> based on
            {{ number_format(((float) $analysis['regime_threshold']) / 1_000_000, 1) }}M {{ $analysis['currency'] }}
            of {{ $analysis['reference_year'] ?? 'prior-year' }} turnover.
        </div>
    @endif
@endsection
