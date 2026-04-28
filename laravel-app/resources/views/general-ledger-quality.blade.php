@extends('layouts.app')

@section('title', 'GL Quality')

@section('header')
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-2xl font-bold text-slate-900">General Ledger Quality</h2>
            <p class="text-xs text-slate-500 mt-1">
                {{ $data['period_label'] ?? 'YTD' }}
                @if ($data['error'] ?? null)
                    — Last sync failed
                @else
                    — Last synced just now
                @endif
            </p>
        </div>
        <form method="POST" action="{{ url('/sync') }}" class="inline">
            @csrf
            <button type="submit" class="px-4 py-2 text-sm font-medium bg-slate-200 text-slate-700 rounded-lg hover:bg-slate-300 transition">
                Sync now
            </button>
        </form>
    </div>
@endsection

@section('content')
    @if ($error ?? null)
        <div class="mb-6 px-4 py-3 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-sm">
            <div class="font-medium mb-1">Could not load data from Odoo</div>
            <div>{{ $error }}</div>
            <div class="mt-2 text-[12px]">Go to <a href="{{ tenant_url('/settings') }}" class="underline">Settings</a> to verify your Odoo connection.</div>
        </div>
    @elseif (!$data)
        <div class="mb-6 px-4 py-3 rounded-lg bg-slate-50 border border-slate-200 text-slate-600 text-sm">
            No data available.
        </div>
    @else
    @if ($data['error'] ?? null)
        <div class="mb-6 px-4 py-3 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-sm">
            <strong>Partial error:</strong> {{ $data['error'] }}
        </div>
    @endif

    {{-- ───────── Overall GL Quality (hero panel + trend) ───────── --}}
    @php
        $overall      = (float)($data['overall_score']     ?? 0);
        $threshold    = (float)($data['overall_threshold'] ?? 96.0);
        $sectionScores = $data['section_scores']           ?? [];
        $trend        = $data['monthly_trend']             ?? [];
        $isGreen      = $overall >= $threshold;
        $heroBg       = $isGreen ? 'from-emerald-50 to-emerald-100/40' : 'from-rose-50 to-rose-100/40';
        $heroRing     = $isGreen ? 'ring-emerald-300' : 'ring-rose-300';
        $heroText     = $isGreen ? 'text-emerald-700' : 'text-rose-700';
        $heroIcon     = $isGreen ? 'M5 13l4 4L19 7' : 'M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4a2 2 0 00-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z';
    @endphp

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
        {{-- Hero: overall score --}}
        <section class="bg-gradient-to-br {{ $heroBg }} rounded-xl border border-slate-200 ring-1 {{ $heroRing }} shadow-sm overflow-hidden">
            <div class="px-6 py-5">
                <div class="flex items-start justify-between">
                    <div>
                        <h3 class="text-base font-semibold text-slate-900">Overall GL Quality Score</h3>
                        <p class="text-xs text-slate-500 mt-0.5">
                            Equal-weighted average of four sections · threshold {{ number_format($threshold, 0) }}%
                        </p>
                    </div>
                    <div class="flex items-center gap-2 {{ $heroText }}">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="{{ $heroIcon }}"/>
                        </svg>
                        <span class="text-xs font-semibold uppercase tracking-wide">{{ $isGreen ? 'Above threshold' : 'Below threshold' }}</span>
                    </div>
                </div>
                <div class="mt-3 flex items-baseline gap-3">
                    <span class="text-5xl font-extrabold {{ $heroText }} leading-none">{{ number_format($overall, 1) }}%</span>
                </div>

                @if (!empty($sectionScores))
                    <dl class="mt-5 grid grid-cols-2 gap-x-5 gap-y-2 text-xs">
                        <div class="flex items-baseline justify-between border-b border-slate-200/70 pb-1">
                            <dt class="text-slate-600">Reconciliation (avg of 5)</dt>
                            <dd class="text-slate-900 font-semibold">{{ number_format($sectionScores['reconciliation'] ?? 0, 1) }}%</dd>
                        </div>
                        <div class="flex items-baseline justify-between border-b border-slate-200/70 pb-1">
                            <dt class="text-slate-600">Description Quality</dt>
                            <dd class="text-slate-900 font-semibold">{{ number_format($sectionScores['description_quality'] ?? 0, 1) }}%</dd>
                        </div>
                        <div class="flex items-baseline justify-between">
                            <dt class="text-slate-600">Manual Entry Reviews</dt>
                            <dd class="text-slate-900 font-semibold">{{ number_format($sectionScores['manual_entry_review'] ?? 0, 1) }}%</dd>
                        </div>
                        <div class="flex items-baseline justify-between">
                            <dt class="text-slate-600">Journal Entry Timing</dt>
                            <dd class="text-slate-900 font-semibold">{{ number_format($sectionScores['journal_timing'] ?? 0, 1) }}%</dd>
                        </div>
                    </dl>
                @endif
            </div>
        </section>

        {{-- Monthly trend --}}
        <section class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
            <header class="px-5 py-4 border-b border-slate-200 bg-gradient-to-r from-slate-50 to-white">
                <h3 class="text-base font-semibold text-slate-900">Monthly Trend</h3>
                <p class="text-xs text-slate-500 mt-0.5">
                    AR + AP reconciliation accuracy at each YTD month-end ·
                    <span class="text-slate-400 italic">historical inventory / fixed-assets sub-ledgers are current-only and excluded from the trend</span>
                </p>
            </header>
            <div class="p-4">
                <canvas id="glQualityTrend" height="80"></canvas>
            </div>
        </section>
    </div>

    @php
        $asOfDate  = $data['as_of'] ?? null;
        $asOfLabel = $asOfDate ? \DateTime::createFromFormat('Y-m-d', $asOfDate)->format('j M Y') : '';
        $currency  = $data['currency'] ?? 'USD';

        // Color-code the headline accuracy %
        $tone = function (float $accuracy): string {
            if ($accuracy >= 99.99) return 'text-emerald-700';
            if ($accuracy >= 99)    return 'text-yellow-800';
            if ($accuracy >= 95)    return 'text-amber-800';
            return 'text-rose-800';
        };

        $ar       = $data['receivables']            ?? [];
        $ap       = $data['payables']               ?? [];
        $inv      = $data['inventory']              ?? [];
        $faOrig   = $data['fixed_assets_original']  ?? [];
        $faBook   = $data['fixed_assets_book']      ?? [];
    @endphp

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">

        {{-- ───────── Box 1: Accounts Receivable ───────── --}}
        @php
            $left     = (float)($ar['left_value']      ?? 0);
            $right    = (float)($ar['right_value']     ?? 0);
            $variance = (float)($ar['variance']        ?? 0);
            $accuracy = (float)($ar['accuracy_pct']    ?? 0);
            $byAcc    = $ar['by_account']              ?? [];
            $brkTotal = (float)($ar['breakdown_total'] ?? $right);
            $subtitle = $ar['subtitle']                ?? '';
            $varColor = abs($variance) < 0.01 ? 'text-slate-700' : 'text-amber-700';
            $msgColor = $tone($accuracy);
        @endphp
        <details class="group/ar bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
            <summary class="px-5 py-4 cursor-pointer list-none flex items-center justify-between gap-4 hover:bg-slate-50 transition [&::-webkit-details-marker]:hidden">
                <div class="flex-1 min-w-0">
                    <h3 class="text-base font-semibold text-slate-900">Accounts Receivable</h3>
                    @if ($asOfLabel)
                        <p class="text-xs text-slate-500 mt-0.5">
                            <span class="font-medium text-slate-700">YTD as of {{ $asOfLabel }}</span>
                        </p>
                    @endif
                </div>
                <div class="flex items-center gap-3 flex-shrink-0">
                    <span class="text-2xl font-bold {{ $msgColor }}">{{ number_format($accuracy, 1) }}%</span>
                    <svg class="w-4 h-4 text-slate-400 transition-transform group-open/ar:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </div>
            </summary>

            <div class="border-t border-slate-200">
                <p class="text-xs text-slate-500 px-5 pt-3">{{ $subtitle }}</p>

                <dl class="px-5 py-4 space-y-3">
                    <div class="flex items-baseline justify-between border-b border-slate-100 pb-2">
                        <dt class="text-sm text-slate-600">{{ $ar['left_label'] ?? '—' }}</dt>
                        <dd class="text-base font-semibold text-slate-900">{{ ea_money_short($left, $currency) }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between border-b border-slate-100 pb-2">
                        <dt class="text-sm text-slate-600">{{ $ar['right_label'] ?? '—' }}</dt>
                        <dd class="text-base font-semibold text-slate-900">{{ ea_money_short($right, $currency) }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between border-b border-slate-100 pb-2">
                        <dt class="text-sm text-slate-600">Variance <span class="text-[11px] text-slate-400">(right − left)</span></dt>
                        <dd class="text-base font-semibold {{ $varColor }}">{{ ea_money_short($variance, $currency) }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between">
                        <dt class="text-sm text-slate-600">Reconciliation accuracy</dt>
                        <dd class="text-lg font-bold {{ $msgColor }}">{{ number_format($accuracy, 1) }}%</dd>
                    </div>
                </dl>

                <details class="border-t border-slate-200 group/ar-breakdown">
                    <summary class="px-5 py-2.5 text-xs font-medium text-slate-600 cursor-pointer hover:bg-slate-50 flex items-center gap-2 select-none [&::-webkit-details-marker]:hidden">
                        <svg class="w-3.5 h-3.5 transition-transform group-open/ar-breakdown:rotate-90" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        Trial balance breakdown
                        <span class="text-[11px] text-slate-400 font-normal">({{ count($byAcc) }} {{ count($byAcc) === 1 ? 'account' : 'accounts' }})</span>
                    </summary>
                    <div class="overflow-x-auto border-t border-slate-200">
                        @include('partials.gl-quality-account-breakdown', ['rows' => $byAcc, 'total' => $brkTotal, 'currency' => $currency])
                    </div>
                </details>
            </div>
        </details>

        {{-- ───────── Box 2: Accounts Payable ───────── --}}
        @php
            $left     = (float)($ap['left_value']      ?? 0);
            $right    = (float)($ap['right_value']     ?? 0);
            $variance = (float)($ap['variance']        ?? 0);
            $accuracy = (float)($ap['accuracy_pct']    ?? 0);
            $byAcc    = $ap['by_account']              ?? [];
            $brkTotal = (float)($ap['breakdown_total'] ?? $right);
            $subtitle = $ap['subtitle']                ?? '';
            $varColor = abs($variance) < 0.01 ? 'text-slate-700' : 'text-amber-700';
            $msgColor = $tone($accuracy);
        @endphp
        <details class="group/ap bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
            <summary class="px-5 py-4 cursor-pointer list-none flex items-center justify-between gap-4 hover:bg-slate-50 transition [&::-webkit-details-marker]:hidden">
                <div class="flex-1 min-w-0">
                    <h3 class="text-base font-semibold text-slate-900">Accounts Payable</h3>
                    @if ($asOfLabel)
                        <p class="text-xs text-slate-500 mt-0.5">
                            <span class="font-medium text-slate-700">YTD as of {{ $asOfLabel }}</span>
                        </p>
                    @endif
                </div>
                <div class="flex items-center gap-3 flex-shrink-0">
                    <span class="text-2xl font-bold {{ $msgColor }}">{{ number_format($accuracy, 1) }}%</span>
                    <svg class="w-4 h-4 text-slate-400 transition-transform group-open/ap:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </div>
            </summary>

            <div class="border-t border-slate-200">
                <p class="text-xs text-slate-500 px-5 pt-3">{{ $subtitle }}</p>

                <dl class="px-5 py-4 space-y-3">
                    <div class="flex items-baseline justify-between border-b border-slate-100 pb-2">
                        <dt class="text-sm text-slate-600">{{ $ap['left_label'] ?? '—' }}</dt>
                        <dd class="text-base font-semibold text-slate-900">{{ ea_money_short($left, $currency) }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between border-b border-slate-100 pb-2">
                        <dt class="text-sm text-slate-600">{{ $ap['right_label'] ?? '—' }}</dt>
                        <dd class="text-base font-semibold text-slate-900">{{ ea_money_short($right, $currency) }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between border-b border-slate-100 pb-2">
                        <dt class="text-sm text-slate-600">Variance <span class="text-[11px] text-slate-400">(|right| − |left|)</span></dt>
                        <dd class="text-base font-semibold {{ $varColor }}">{{ ea_money_short($variance, $currency) }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between">
                        <dt class="text-sm text-slate-600">Reconciliation accuracy</dt>
                        <dd class="text-lg font-bold {{ $msgColor }}">{{ number_format($accuracy, 1) }}%</dd>
                    </div>
                </dl>

                <details class="border-t border-slate-200 group/ap-breakdown">
                    <summary class="px-5 py-2.5 text-xs font-medium text-slate-600 cursor-pointer hover:bg-slate-50 flex items-center gap-2 select-none [&::-webkit-details-marker]:hidden">
                        <svg class="w-3.5 h-3.5 transition-transform group-open/ap-breakdown:rotate-90" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        Trial balance breakdown
                        <span class="text-[11px] text-slate-400 font-normal">({{ count($byAcc) }} {{ count($byAcc) === 1 ? 'account' : 'accounts' }}, signed)</span>
                    </summary>
                    <div class="overflow-x-auto border-t border-slate-200">
                        @include('partials.gl-quality-account-breakdown', ['rows' => $byAcc, 'total' => $brkTotal, 'currency' => $currency])
                    </div>
                </details>
            </div>
        </details>

        {{-- ───────── Box 3: Inventory ───────── --}}
        @php
            $left     = (float)($inv['left_value']      ?? 0);
            $right    = (float)($inv['right_value']     ?? 0);
            $variance = (float)($inv['variance']        ?? 0);
            $accuracy = (float)($inv['accuracy_pct']    ?? 0);
            $byAcc    = $inv['by_account']              ?? [];
            $brkTotal = (float)($inv['breakdown_total'] ?? $right);
            $subtitle = $inv['subtitle']                ?? '';
            $varColor = abs($variance) < 0.01 ? 'text-slate-700' : 'text-amber-700';
            $msgColor = $tone($accuracy);
        @endphp
        <details class="group/inv bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
            <summary class="px-5 py-4 cursor-pointer list-none flex items-center justify-between gap-4 hover:bg-slate-50 transition [&::-webkit-details-marker]:hidden">
                <div class="flex-1 min-w-0">
                    <h3 class="text-base font-semibold text-slate-900">Inventory</h3>
                    @if ($asOfLabel)
                        <p class="text-xs text-slate-500 mt-0.5">
                            <span class="font-medium text-slate-700">YTD as of {{ $asOfLabel }}</span>
                        </p>
                    @endif
                </div>
                <div class="flex items-center gap-3 flex-shrink-0">
                    <span class="text-2xl font-bold {{ $msgColor }}">{{ number_format($accuracy, 1) }}%</span>
                    <svg class="w-4 h-4 text-slate-400 transition-transform group-open/inv:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </div>
            </summary>

            <div class="border-t border-slate-200">
                <p class="text-xs text-slate-500 px-5 pt-3">{{ $subtitle }}</p>

                <dl class="px-5 py-4 space-y-3">
                    <div class="flex items-baseline justify-between border-b border-slate-100 pb-2">
                        <dt class="text-sm text-slate-600">{{ $inv['left_label'] ?? '—' }}</dt>
                        <dd class="text-base font-semibold text-slate-900">{{ ea_money_short($left, $currency) }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between border-b border-slate-100 pb-2">
                        <dt class="text-sm text-slate-600">{{ $inv['right_label'] ?? '—' }}</dt>
                        <dd class="text-base font-semibold text-slate-900">{{ ea_money_short($right, $currency) }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between border-b border-slate-100 pb-2">
                        <dt class="text-sm text-slate-600">Variance <span class="text-[11px] text-slate-400">(right − left)</span></dt>
                        <dd class="text-base font-semibold {{ $varColor }}">{{ ea_money_short($variance, $currency) }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between">
                        <dt class="text-sm text-slate-600">Reconciliation accuracy</dt>
                        <dd class="text-lg font-bold {{ $msgColor }}">{{ number_format($accuracy, 1) }}%</dd>
                    </div>
                </dl>

                <details class="border-t border-slate-200 group/inv-breakdown">
                    <summary class="px-5 py-2.5 text-xs font-medium text-slate-600 cursor-pointer hover:bg-slate-50 flex items-center gap-2 select-none [&::-webkit-details-marker]:hidden">
                        <svg class="w-3.5 h-3.5 transition-transform group-open/inv-breakdown:rotate-90" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        Trial balance breakdown
                        <span class="text-[11px] text-slate-400 font-normal">({{ count($byAcc) }} {{ count($byAcc) === 1 ? 'account' : 'accounts' }})</span>
                    </summary>
                    <div class="overflow-x-auto border-t border-slate-200">
                        @include('partials.gl-quality-account-breakdown', ['rows' => $byAcc, 'total' => $brkTotal, 'currency' => $currency])
                    </div>
                </details>
            </div>
        </details>

        {{-- ───────── Box 4: Fixed Assets — Original Value ───────── --}}
        @php
            $left     = (float)($faOrig['left_value']      ?? 0);
            $right    = (float)($faOrig['right_value']     ?? 0);
            $variance = (float)($faOrig['variance']        ?? 0);
            $accuracy = (float)($faOrig['accuracy_pct']    ?? 0);
            $byAcc    = $faOrig['by_account']              ?? [];
            $brkTotal = (float)($faOrig['breakdown_total'] ?? $right);
            $subtitle = $faOrig['subtitle']                ?? '';
            $varColor = abs($variance) < 0.01 ? 'text-slate-700' : 'text-amber-700';
            $msgColor = $tone($accuracy);
        @endphp
        <details class="group/faorig bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
            <summary class="px-5 py-4 cursor-pointer list-none flex items-center justify-between gap-4 hover:bg-slate-50 transition [&::-webkit-details-marker]:hidden">
                <div class="flex-1 min-w-0">
                    <h3 class="text-base font-semibold text-slate-900">Fixed Assets — Original Value</h3>
                    @if ($asOfLabel)
                        <p class="text-xs text-slate-500 mt-0.5">
                            <span class="font-medium text-slate-700">YTD as of {{ $asOfLabel }}</span>
                        </p>
                    @endif
                </div>
                <div class="flex items-center gap-3 flex-shrink-0">
                    <span class="text-2xl font-bold {{ $msgColor }}">{{ number_format($accuracy, 1) }}%</span>
                    <svg class="w-4 h-4 text-slate-400 transition-transform group-open/faorig:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </div>
            </summary>

            <div class="border-t border-slate-200">
                <p class="text-xs text-slate-500 px-5 pt-3">{{ $subtitle }}</p>

                <dl class="px-5 py-4 space-y-3">
                    <div class="flex items-baseline justify-between border-b border-slate-100 pb-2">
                        <dt class="text-sm text-slate-600">{{ $faOrig['left_label'] ?? '—' }}</dt>
                        <dd class="text-base font-semibold text-slate-900">{{ ea_money_short($left, $currency) }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between border-b border-slate-100 pb-2">
                        <dt class="text-sm text-slate-600">{{ $faOrig['right_label'] ?? '—' }}</dt>
                        <dd class="text-base font-semibold text-slate-900">{{ ea_money_short($right, $currency) }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between border-b border-slate-100 pb-2">
                        <dt class="text-sm text-slate-600">Variance <span class="text-[11px] text-slate-400">(right − left)</span></dt>
                        <dd class="text-base font-semibold {{ $varColor }}">{{ ea_money_short($variance, $currency) }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between">
                        <dt class="text-sm text-slate-600">Reconciliation accuracy</dt>
                        <dd class="text-lg font-bold {{ $msgColor }}">{{ number_format($accuracy, 1) }}%</dd>
                    </div>
                </dl>

                <details class="border-t border-slate-200 group/faorig-breakdown">
                    <summary class="px-5 py-2.5 text-xs font-medium text-slate-600 cursor-pointer hover:bg-slate-50 flex items-center gap-2 select-none [&::-webkit-details-marker]:hidden">
                        <svg class="w-3.5 h-3.5 transition-transform group-open/faorig-breakdown:rotate-90" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        Trial balance breakdown
                        <span class="text-[11px] text-slate-400 font-normal">({{ count($byAcc) }} {{ count($byAcc) === 1 ? 'account' : 'accounts' }})</span>
                    </summary>
                    <div class="overflow-x-auto border-t border-slate-200">
                        @include('partials.gl-quality-account-breakdown', ['rows' => $byAcc, 'total' => $brkTotal, 'currency' => $currency])
                    </div>
                </details>
            </div>
        </details>

        {{-- ───────── Box 5: Fixed Assets — Net Book Value ───────── --}}
        @php
            $left     = (float)($faBook['left_value']      ?? 0);
            $right    = (float)($faBook['right_value']     ?? 0);
            $variance = (float)($faBook['variance']        ?? 0);
            $accuracy = (float)($faBook['accuracy_pct']    ?? 0);
            $byAcc    = $faBook['by_account']              ?? [];
            $brkTotal = (float)($faBook['breakdown_total'] ?? $right);
            $subtitle = $faBook['subtitle']                ?? '';
            $varColor = abs($variance) < 0.01 ? 'text-slate-700' : 'text-amber-700';
            $msgColor = $tone($accuracy);
        @endphp
        <details class="group/fanbv bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
            <summary class="px-5 py-4 cursor-pointer list-none flex items-center justify-between gap-4 hover:bg-slate-50 transition [&::-webkit-details-marker]:hidden">
                <div class="flex-1 min-w-0">
                    <h3 class="text-base font-semibold text-slate-900">Fixed Assets — Net Book Value</h3>
                    @if ($asOfLabel)
                        <p class="text-xs text-slate-500 mt-0.5">
                            <span class="font-medium text-slate-700">YTD as of {{ $asOfLabel }}</span>
                        </p>
                    @endif
                </div>
                <div class="flex items-center gap-3 flex-shrink-0">
                    <span class="text-2xl font-bold {{ $msgColor }}">{{ number_format($accuracy, 1) }}%</span>
                    <svg class="w-4 h-4 text-slate-400 transition-transform group-open/fanbv:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </div>
            </summary>

            <div class="border-t border-slate-200">
                <p class="text-xs text-slate-500 px-5 pt-3">{{ $subtitle }}</p>

                <dl class="px-5 py-4 space-y-3">
                    <div class="flex items-baseline justify-between border-b border-slate-100 pb-2">
                        <dt class="text-sm text-slate-600">{{ $faBook['left_label'] ?? '—' }}</dt>
                        <dd class="text-base font-semibold text-slate-900">{{ ea_money_short($left, $currency) }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between border-b border-slate-100 pb-2">
                        <dt class="text-sm text-slate-600">{{ $faBook['right_label'] ?? '—' }}</dt>
                        <dd class="text-base font-semibold text-slate-900">{{ ea_money_short($right, $currency) }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between border-b border-slate-100 pb-2">
                        <dt class="text-sm text-slate-600">Variance <span class="text-[11px] text-slate-400">(right − left)</span></dt>
                        <dd class="text-base font-semibold {{ $varColor }}">{{ ea_money_short($variance, $currency) }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between">
                        <dt class="text-sm text-slate-600">Reconciliation accuracy</dt>
                        <dd class="text-lg font-bold {{ $msgColor }}">{{ number_format($accuracy, 1) }}%</dd>
                    </div>
                </dl>

                <details class="border-t border-slate-200 group/fanbv-breakdown">
                    <summary class="px-5 py-2.5 text-xs font-medium text-slate-600 cursor-pointer hover:bg-slate-50 flex items-center gap-2 select-none [&::-webkit-details-marker]:hidden">
                        <svg class="w-3.5 h-3.5 transition-transform group-open/fanbv-breakdown:rotate-90" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        Trial balance breakdown
                        <span class="text-[11px] text-slate-400 font-normal">({{ count($byAcc) }} {{ count($byAcc) === 1 ? 'account' : 'accounts' }})</span>
                    </summary>
                    <div class="overflow-x-auto border-t border-slate-200">
                        @include('partials.gl-quality-account-breakdown', ['rows' => $byAcc, 'total' => $brkTotal, 'currency' => $currency])
                    </div>
                </details>
            </div>
        </details>

    </div>

    {{-- ───────── Description Quality (full width) ───────── --}}
    @php
        $dq = $data['description_quality'] ?? null;
    @endphp

    @if ($dq)
        @php
            $dqScore  = (float)($dq['overall_score']  ?? 0);
            $dqPassed = (int)($dq['checks_passed']    ?? 0);
            $dqTotal  = (int)($dq['checks_total']     ?? 0);
            $dqLines  = (int)($dq['lines_reviewed']   ?? 0);
            $dqCapped = (bool)($dq['lines_capped']    ?? false);
            $dqLimit  = (int)($dq['fetch_limit']      ?? 50000);
            $dqExcl   = $dq['exclusions']             ?? [];
            $dqCats   = $dq['categories']             ?? [];
            $dqError  = $dq['error']                  ?? null;

            // Color the headline like the reconciliation accuracy
            if ($dqScore >= 99.99) $dqColor = 'text-emerald-700';
            elseif ($dqScore >= 80) $dqColor = 'text-yellow-800';
            elseif ($dqScore >= 50) $dqColor = 'text-amber-800';
            else                   $dqColor = 'text-rose-800';
        @endphp

        <details class="group/dq bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden mb-6">
            <summary class="px-5 py-4 cursor-pointer list-none flex items-center justify-between gap-4 hover:bg-slate-50 transition [&::-webkit-details-marker]:hidden">
                <div class="flex-1 min-w-0">
                    <h3 class="text-base font-semibold text-slate-900">GL Description Quality</h3>
                    <p class="text-xs text-slate-500 mt-0.5">
                        @if ($asOfLabel)
                            <span class="font-medium text-slate-700">YTD as of {{ $asOfLabel }}</span> ·
                        @endif
                        {{ number_format($dqLines) }} {{ $dqLines === 1 ? 'line' : 'lines' }} reviewed
                        @if ($dqCapped)
                            <span class="ml-1 text-amber-700">(capped at {{ number_format($dqLimit) }})</span>
                        @endif
                    </p>
                    @if (!empty($dqExcl))
                        <p class="text-[11px] text-slate-500 mt-1">
                            Excluding:
                            @foreach ($dqExcl as $i => $rule)
                                <span class="inline-flex items-center gap-1 ml-1">
                                    <svg class="w-3 h-3 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                    {{ $rule['label'] ?? '' }}{{ $i < count($dqExcl) - 1 ? ',' : '' }}
                                </span>
                            @endforeach
                        </p>
                    @endif
                </div>
                <div class="flex items-center gap-3 flex-shrink-0">
                    @php
                        $dqReportCount = (int)($dq['report'] ? count($dq['report']) : 0);
                        $dqReportCap   = (bool)($dq['report_capped'] ?? false);
                    @endphp
                    @if ($dqReportCount > 0)
                        <a href="{{ tenant_url('/general-ledger-quality/description-report') }}"
                           onclick="event.stopPropagation();"
                           class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-medium transition border border-slate-200"
                           title="Download CSV report of all flagged lines{{ $dqReportCap ? ' (capped at ' . number_format($dq['report_limit'] ?? 5000) . ')' : '' }}">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3"/>
                            </svg>
                            Download report ({{ number_format($dqReportCount) }}{{ $dqReportCap ? '+' : '' }})
                        </a>
                    @endif
                    <div class="text-right">
                        <div class="text-2xl font-bold {{ $dqColor }} leading-none">{{ number_format($dqScore, 1) }}%</div>
                        <div class="text-[11px] text-slate-500 mt-0.5">{{ $dqPassed }} / {{ $dqTotal }} checks passed</div>
                    </div>
                    <svg class="w-4 h-4 text-slate-400 transition-transform group-open/dq:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </div>
            </summary>

            <div class="border-t border-slate-200">
                <p class="text-xs text-slate-500 px-5 pt-3">
                    Audits posted GL line narrations (<code class="font-mono bg-slate-100 px-1 rounded">account.move.line.name</code>)
                    against {{ $dqTotal }} description-quality rules. Lines on accounts already proven by reconciliation are skipped.
                </p>

                @if ($dqError)
                    <div class="mx-5 mt-3 px-3 py-2 rounded bg-rose-50 border border-rose-200 text-rose-700 text-xs">
                        Partial error: {{ $dqError }}
                    </div>
                @endif

                <div class="px-5 pb-5 pt-3 space-y-5">
                    @foreach ($dqCats as $cat)
                        <div>
                            <h4 class="text-[11px] font-semibold uppercase tracking-wide text-slate-500 mb-2 border-b border-slate-100 pb-1">
                                {{ $cat['label'] ?? '' }}
                            </h4>
                            <ul class="space-y-1.5">
                                @foreach (($cat['checks'] ?? []) as $chk)
                                    @php
                                        $passed        = (bool)($chk['passed'] ?? false);
                                        $count         = (int)($chk['count']  ?? 0);
                                        $notApplicable = !empty($chk['not_applicable']);
                                    @endphp
                                    <li class="flex items-start gap-3 text-sm">
                                        @if ($notApplicable)
                                            <span class="inline-flex items-center justify-center w-5 h-5 mt-0.5 flex-shrink-0 rounded-full bg-slate-200 text-slate-500 text-[9px] font-bold tracking-wider">N/A</span>
                                        @elseif ($passed)
                                            <svg class="w-5 h-5 mt-0.5 flex-shrink-0 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                                            </svg>
                                        @else
                                            <svg class="w-5 h-5 mt-0.5 flex-shrink-0 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/>
                                            </svg>
                                        @endif
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-baseline justify-between gap-3">
                                                <span class="text-slate-900">
                                                    <span class="text-slate-400 font-mono text-xs mr-1">{{ $chk['no'] ?? '' }}.</span>
                                                    {{ $chk['name'] ?? '' }}
                                                </span>
                                                <span class="text-xs whitespace-nowrap {{ $notApplicable ? 'text-slate-400 italic' : ($passed ? 'text-slate-500' : 'text-rose-700 font-medium') }}">
                                                    @if ($notApplicable)
                                                        Not applicable
                                                    @else
                                                        {{ number_format($count) }} {{ $count === 1 ? 'line' : 'lines' }}
                                                    @endif
                                                </span>
                                            </div>
                                            <p class="text-[11px] text-slate-500 mt-0.5">{{ $chk['description'] ?? '' }}</p>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>

                <div class="px-5 py-3 border-t border-slate-200 bg-slate-50/50">
                    <p class="text-xs text-slate-600 text-center">
                        Score:
                        <span class="font-semibold {{ $dqColor }}">{{ $dqPassed }} of {{ $dqTotal }} checks passed</span>
                        ({{ number_format($dqScore, 1) }}%)
                    </p>
                </div>
            </div>
        </details>
    @endif

    {{-- ───────── Manual Entry Reviews (full width) ───────── --}}
    @php
        $mer = $data['manual_entry_review'] ?? null;
    @endphp

    @if ($mer)
        @php
            $merScore   = (float)($mer['overall_score']  ?? 0);
            $merPassed  = (int)($mer['checks_passed']    ?? 0);
            $merTotal   = (int)($mer['checks_total']     ?? 0);
            $merLines   = (int)($mer['lines_reviewed']   ?? 0);
            $merCapped  = (bool)($mer['lines_capped']    ?? false);
            $merLimit   = (int)($mer['fetch_limit']      ?? 100000);
            $merChecks  = $mer['checks']                 ?? [];
            $merReport  = $mer['report']                 ?? [];
            $merReportN = count($merReport);
            $merReportCapped = (bool)($mer['report_capped'] ?? false);
            $merError   = $mer['error']                  ?? null;

            if ($merScore >= 99.99) $merColor = 'text-emerald-700';
            elseif ($merScore >= 80) $merColor = 'text-yellow-800';
            elseif ($merScore >= 50) $merColor = 'text-amber-800';
            else                     $merColor = 'text-rose-800';
        @endphp

        <details class="group/mer bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden mb-6">
            <summary class="px-5 py-4 cursor-pointer list-none flex items-center justify-between gap-4 hover:bg-slate-50 transition [&::-webkit-details-marker]:hidden">
                <div class="flex-1 min-w-0">
                    <h3 class="text-base font-semibold text-slate-900">Manual Entry Reviews</h3>
                    <p class="text-xs text-slate-500 mt-0.5">
                        @if ($asOfLabel)
                            <span class="font-medium text-slate-700">YTD as of {{ $asOfLabel }}</span> ·
                        @endif
                        {{ number_format($merLines) }} {{ $merLines === 1 ? 'line' : 'lines' }} reviewed
                        @if ($merCapped)
                            <span class="ml-1 text-amber-700">(capped at {{ number_format($merLimit) }})</span>
                        @endif
                    </p>
                </div>
                <div class="flex items-center gap-3 flex-shrink-0">
                    @if ($merReportN > 0)
                        <a href="{{ tenant_url('/general-ledger-quality/manual-entry-report') }}"
                           onclick="event.stopPropagation();"
                           class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-medium transition border border-slate-200"
                           title="Download CSV report of all flagged lines{{ $merReportCapped ? ' (capped)' : '' }}">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3"/>
                            </svg>
                            Download report ({{ number_format($merReportN) }}{{ $merReportCapped ? '+' : '' }})
                        </a>
                    @endif
                    <div class="text-right">
                        <div class="text-2xl font-bold {{ $merColor }} leading-none">{{ number_format($merScore, 1) }}%</div>
                        <div class="text-[11px] text-slate-500 mt-0.5">{{ $merPassed }} / {{ $merTotal }} checks passed</div>
                    </div>
                    <svg class="w-4 h-4 text-slate-400 transition-transform group-open/mer:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </div>
            </summary>

            <div class="border-t border-slate-200">
                <p class="text-xs text-slate-500 px-5 pt-3">
                    Eight audit-style controls focused on where manual journal entries
                    (<code class="font-mono bg-slate-100 px-1 rounded">move_type='entry'</code>) are appearing — or failing to appear — across the OHADA chart of accounts.
                </p>

                @if ($merError)
                    <div class="mx-5 mt-3 px-3 py-2 rounded bg-rose-50 border border-rose-200 text-rose-700 text-xs">
                        Partial error: {{ $merError }}
                    </div>
                @endif

                <ul class="px-5 py-4 space-y-2">
                    @foreach ($merChecks as $chk)
                        @php
                            $passed = (bool)($chk['passed'] ?? false);
                            $count  = (int)($chk['count']  ?? 0);
                            $note   = $chk['note']         ?? null;
                        @endphp
                        @php
                            $notApplicable = !empty($chk['not_applicable']);
                        @endphp
                        <li class="flex items-start gap-3 text-sm border-b border-slate-100 pb-2 last:border-b-0">
                            @if ($notApplicable)
                                <span class="inline-flex items-center justify-center w-5 h-5 mt-0.5 flex-shrink-0 rounded-full bg-slate-200 text-slate-500 text-[9px] font-bold tracking-wider">N/A</span>
                            @elseif ($passed)
                                <svg class="w-5 h-5 mt-0.5 flex-shrink-0 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                                </svg>
                            @else
                                <svg class="w-5 h-5 mt-0.5 flex-shrink-0 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            @endif
                            <div class="flex-1 min-w-0">
                                <div class="flex items-baseline justify-between gap-3">
                                    <span class="text-slate-900">
                                        <span class="text-slate-400 font-mono text-xs mr-1">{{ $chk['no'] ?? '' }}.</span>
                                        {{ $chk['name'] ?? '' }}
                                    </span>
                                    <span class="text-xs whitespace-nowrap {{ $notApplicable ? 'text-slate-400 italic' : ($passed ? 'text-slate-500' : 'text-rose-700 font-medium') }}">
                                        @if ($note ?? null)
                                            <span class="text-slate-500 italic">{{ $note }}</span>
                                        @elseif ($notApplicable)
                                            Not applicable
                                        @else
                                            {{ number_format($count) }} {{ $count === 1 ? 'line' : 'lines' }}
                                        @endif
                                    </span>
                                </div>
                                <p class="text-[11px] text-slate-500 mt-0.5">{{ $chk['description'] ?? '' }}</p>
                            </div>
                        </li>
                    @endforeach
                </ul>

                <div class="px-5 py-3 border-t border-slate-200 bg-slate-50/50">
                    <p class="text-xs text-slate-600 text-center">
                        Score:
                        <span class="font-semibold {{ $merColor }}">{{ $merPassed }} of {{ $merTotal }} checks passed</span>
                        ({{ number_format($merScore, 1) }}%)
                    </p>
                </div>
            </div>
        </details>
    @endif

    {{-- ───────── Journal Entry Timing (full width) ───────── --}}
    @php
        $jt = $data['journal_timing'] ?? null;
    @endphp

    @if ($jt)
        @php
            $jtScore   = (float)($jt['overall_score']  ?? 0);
            $jtPassed  = (int)($jt['checks_passed']    ?? 0);
            $jtTotal   = (int)($jt['checks_total']     ?? 0);
            $jtLines   = (int)($jt['lines_reviewed']   ?? 0);
            $jtCapped  = (bool)($jt['lines_capped']    ?? false);
            $jtLimit   = (int)($jt['fetch_limit']      ?? 100000);
            $jtChecks  = $jt['checks']                 ?? [];
            $jtReport  = $jt['report']                 ?? [];
            $jtReportN = count($jtReport);
            $jtReportCapped = (bool)($jt['report_capped'] ?? false);
            $jtError   = $jt['error']                  ?? null;

            if ($jtScore >= 99.99) $jtColor = 'text-emerald-700';
            elseif ($jtScore >= 80) $jtColor = 'text-yellow-800';
            elseif ($jtScore >= 50) $jtColor = 'text-amber-800';
            else                    $jtColor = 'text-rose-800';
        @endphp

        <details class="group/jt bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden mb-6">
            <summary class="px-5 py-4 cursor-pointer list-none flex items-center justify-between gap-4 hover:bg-slate-50 transition [&::-webkit-details-marker]:hidden">
                <div class="flex-1 min-w-0">
                    <h3 class="text-base font-semibold text-slate-900">Journal Entry Timing</h3>
                    <p class="text-xs text-slate-500 mt-0.5">
                        @if ($asOfLabel)
                            <span class="font-medium text-slate-700">YTD as of {{ $asOfLabel }}</span> ·
                        @endif
                        {{ number_format($jtLines) }} {{ $jtLines === 1 ? 'line' : 'lines' }} reviewed
                        @if ($jtCapped)
                            <span class="ml-1 text-amber-700">(capped at {{ number_format($jtLimit) }})</span>
                        @endif
                    </p>
                </div>
                <div class="flex items-center gap-3 flex-shrink-0">
                    @if ($jtReportN > 0)
                        <a href="{{ tenant_url('/general-ledger-quality/timing-report') }}"
                           onclick="event.stopPropagation();"
                           class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-medium transition border border-slate-200"
                           title="Download CSV report of all flagged lines{{ $jtReportCapped ? ' (capped)' : '' }} — includes the recorded date and delay in days">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3"/>
                            </svg>
                            Download report ({{ number_format($jtReportN) }}{{ $jtReportCapped ? '+' : '' }})
                        </a>
                    @endif
                    <div class="text-right">
                        <div class="text-2xl font-bold {{ $jtColor }} leading-none">{{ number_format($jtScore, 1) }}%</div>
                        <div class="text-[11px] text-slate-500 mt-0.5">{{ $jtPassed }} / {{ $jtTotal }} checks passed</div>
                    </div>
                    <svg class="w-4 h-4 text-slate-400 transition-transform group-open/jt:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </div>
            </summary>

            <div class="border-t border-slate-200">
                <p class="text-xs text-slate-500 px-5 pt-3">
                    Compares each posted line's system <code class="font-mono bg-slate-100 px-1 rounded">create_date</code>
                    (when it was actually entered into Odoo) against its accounting <code class="font-mono bg-slate-100 px-1 rounded">date</code>
                    (the effective period). Flags entries recorded long after the period they relate to, and entries recorded on weekends or fixed Cameroon/OHADA public holidays.
                </p>

                @if ($jtError)
                    <div class="mx-5 mt-3 px-3 py-2 rounded bg-rose-50 border border-rose-200 text-rose-700 text-xs">
                        Partial error: {{ $jtError }}
                    </div>
                @endif

                <ul class="px-5 py-4 space-y-2">
                    @foreach ($jtChecks as $chk)
                        @php
                            $passed = (bool)($chk['passed'] ?? false);
                            $count  = (int)($chk['count']  ?? 0);
                        @endphp
                        <li class="flex items-start gap-3 text-sm border-b border-slate-100 pb-2 last:border-b-0">
                            @if ($passed)
                                <svg class="w-5 h-5 mt-0.5 flex-shrink-0 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                                </svg>
                            @else
                                <svg class="w-5 h-5 mt-0.5 flex-shrink-0 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            @endif
                            <div class="flex-1 min-w-0">
                                <div class="flex items-baseline justify-between gap-3">
                                    <span class="text-slate-900">
                                        <span class="text-slate-400 font-mono text-xs mr-1">{{ $chk['no'] ?? '' }}.</span>
                                        {{ $chk['name'] ?? '' }}
                                    </span>
                                    <span class="text-xs whitespace-nowrap {{ $passed ? 'text-slate-500' : 'text-rose-700 font-medium' }}">
                                        {{ number_format($count) }} {{ $count === 1 ? 'line' : 'lines' }}
                                    </span>
                                </div>
                                <p class="text-[11px] text-slate-500 mt-0.5">{{ $chk['description'] ?? '' }}</p>
                            </div>
                        </li>
                    @endforeach
                </ul>

                <div class="px-5 py-3 border-t border-slate-200 bg-slate-50/50">
                    <p class="text-xs text-slate-600 text-center">
                        Score:
                        <span class="font-semibold {{ $jtColor }}">{{ $jtPassed }} of {{ $jtTotal }} checks passed</span>
                        ({{ number_format($jtScore, 1) }}%)
                    </p>
                </div>
            </div>
        </details>
    @endif

    @endif {{-- end: $error / $data null guard --}}
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const trendData = @json($data['monthly_trend'] ?? []);
    const threshold = @json($data['overall_threshold'] ?? 96.0);
    const canvas = document.getElementById('glQualityTrend');
    if (!canvas || !trendData.length) {
        if (canvas) {
            canvas.parentElement.innerHTML =
                '<p class="text-center text-slate-500 py-6 text-sm">No trend data yet — at least one month-end must be in the past.</p>';
        }
        return;
    }

    const labels = trendData.map(p => p.month);
    const values = trendData.map(p => p.score);

    new Chart(canvas.getContext('2d'), {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Reconciliation accuracy',
                    data: values,
                    borderColor: 'rgb(79, 70, 229)',
                    backgroundColor: 'rgba(79, 70, 229, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.3,
                    pointBackgroundColor: 'rgb(79, 70, 229)',
                    pointBorderColor: 'white',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                },
                {
                    // Threshold line (96% by default)
                    label: 'Threshold (' + threshold + '%)',
                    data: labels.map(() => threshold),
                    borderColor: 'rgba(16, 185, 129, 0.6)',
                    borderDash: [4, 4],
                    borderWidth: 1.5,
                    pointRadius: 0,
                    fill: false,
                    tension: 0,
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: { display: true, position: 'top', labels: { boxWidth: 12, font: { size: 11 } } },
                tooltip: {
                    backgroundColor: 'rgba(0, 0, 0, 0.85)',
                    padding: 10,
                    titleFont: { size: 12, weight: 'bold' },
                    bodyFont: { size: 11 },
                    callbacks: {
                        label: ctx => ctx.dataset.label + ': ' + (ctx.parsed.y == null ? '—' : ctx.parsed.y.toFixed(1) + '%'),
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: false,
                    suggestedMin: Math.max(0, Math.min(...values.filter(v => v != null), threshold) - 5),
                    suggestedMax: 100,
                    ticks: { callback: v => v + '%' },
                }
            }
        }
    });
});
</script>
@endpush
