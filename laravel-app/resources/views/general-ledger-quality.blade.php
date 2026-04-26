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
    @if ($data['error'] ?? null)
        <div class="mb-6 px-4 py-3 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-sm">
            <strong>Error:</strong> {{ $data['error'] }}
        </div>
    @endif

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
                                        $passed = (bool)($chk['passed'] ?? false);
                                        $count  = (int)($chk['count']  ?? 0);
                                    @endphp
                                    <li class="flex items-start gap-3 text-sm">
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
                                        @if ($note)
                                            <span class="text-slate-500 italic">{{ $note }}</span>
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

@endsection
