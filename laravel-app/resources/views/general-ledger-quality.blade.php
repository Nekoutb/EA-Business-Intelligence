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

        $ar  = $data['receivables'] ?? [];
        $ap  = $data['payables']    ?? [];
        $inv = $data['inventory']   ?? [];
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
                        <dt class="text-sm text-slate-600">Reconciliation</dt>
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
                        <dt class="text-sm text-slate-600">Reconciliation</dt>
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
                        <dt class="text-sm text-slate-600">Reconciliation</dt>
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

    </div>

@endsection
