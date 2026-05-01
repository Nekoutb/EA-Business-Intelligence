@extends('layouts.app')
@section('title', 'Investment Analysis')

@section('header')
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 mb-1">
                <span class="text-[10px] uppercase tracking-[0.35em] text-[#8a8578] font-medium">As of {{ $asOf }} · Cameroon · {{ ea_last_sync_label() }}</span>
            </div>
            <h1 class="text-2xl font-bold text-[#1e3d44]">Investment Analysis</h1>
            <div class="text-[11px] text-[#7c7466] mt-1">Capital deployment and returns lens — Capex, Depreciation, ROCE, Asset Turnover. Balance-sheet items as of selected date; P&amp;L denominators trailing 12 months.</div>
        </div>
        <form method="get" class="flex items-end gap-2">
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[#8a8578] font-medium mb-1">As of</label>
                <input type="date" name="as_of" value="{{ $asOf }}" max="{{ now()->format('Y-m-d') }}" class="text-xs border border-[#e8e4de] rounded-lg px-3 py-2 bg-white text-[#1e3d44] focus:ring-2 focus:ring-brand-300 focus:border-brand-300 outline-none">
            </div>
            <button type="submit" class="text-xs font-medium px-3 py-2 rounded-lg bg-brand-600 hover:bg-brand-700 text-white">Apply</button>
        </form>
    </div>
@endsection

@section('content')
    @if ($error)
        <div class="bg-coral-50 border border-coral-200 text-coral-800 rounded-xl p-4 text-sm mb-6"><strong>Could not fetch:</strong> {{ $error }}</div>
    @endif

    @if (!$data)
        <div class="bg-warm-50 border border-warm-200 rounded-xl p-5">
            <div class="text-sm font-semibold text-[#7a5a1a]">Awaiting data</div>
        </div>
    @else
        @php
            $kpis = $data['kpis'] ?? [];
            $comp = $data['components'] ?? [];
            $ccy  = $data['currency']   ?? 'XAF';

            $fmt = function ($v, string $unit) {
                if ($v === null || !is_finite((float) $v)) return '—';
                if ($unit === '%') return number_format((float) $v, 1) . '%';
                if ($unit === 'x') return number_format((float) $v, 2) . 'x';
                return ea_money_short((float) $v, 'XAF');
            };
            $tone = function ($v, string $better) {
                if ($v === null) return 'slate';
                if ($better === 'higher')  return $v > 0 ? 'sage'  : 'coral';
                if ($better === 'lower')   return $v < 0 ? 'sage'  : 'coral';
                return 'brand';
            };
            $palettes = [
                'sage'  => ['bar' => 'bg-sage-500',  'bg' => 'bg-sage-50',  'bdr' => 'border-sage-100',  'txt' => 'text-sage-700'],
                'brand' => ['bar' => 'bg-brand-500', 'bg' => 'bg-brand-50', 'bdr' => 'border-brand-100', 'txt' => 'text-brand-700'],
                'warm'  => ['bar' => 'bg-warm-500',  'bg' => 'bg-warm-50',  'bdr' => 'border-warm-100',  'txt' => 'text-warm-700'],
                'coral' => ['bar' => 'bg-coral-500', 'bg' => 'bg-coral-50', 'bdr' => 'border-coral-100', 'txt' => 'text-coral-700'],
                'slate' => ['bar' => 'bg-slate-400', 'bg' => 'bg-slate-50', 'bdr' => 'border-slate-100', 'txt' => 'text-slate-600'],
            ];
        @endphp

        <div class="mb-6">
            <div class="flex items-center gap-2 mb-3">
                <div class="w-1 h-4 rounded bg-brand-400"></div>
                <h2 class="text-xs font-semibold uppercase tracking-wider text-[#5a6a70]">Capital deployment</h2>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3">
                @foreach (['capex_ytd', 'depreciation_ytd', 'net_investment', 'capital_employed'] as $k)
                    @php $kpi = $kpis[$k] ?? null; if (!$kpi) continue; $colour = $tone($kpi['value'], $kpi['better']); $p = $palettes[$colour]; @endphp
                    <div class="bg-white rounded-xl border border-[#e8e4de] shadow-sm flex overflow-hidden">
                        <div class="w-1 {{ $p['bar'] }} shrink-0"></div>
                        <div class="flex-1 p-4">
                            <div class="text-[11px] uppercase tracking-wider text-[#8a8578] font-medium">{{ $kpi['label'] }}</div>
                            <div class="text-2xl font-bold text-[#2d3748] mt-1 tabular-nums">{{ $fmt($kpi['value'], $kpi['unit']) }}</div>
                            <div class="text-[11px] text-[#7c7466] mt-0.5">{{ $kpi['sub'] }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="mb-6">
            <div class="flex items-center gap-2 mb-3">
                <div class="w-1 h-4 rounded bg-sage-400"></div>
                <h2 class="text-xs font-semibold uppercase tracking-wider text-[#5a6a70]">Returns &amp; efficiency</h2>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3">
                @foreach (['roce', 'asset_turnover', 'capex_to_revenue', 'replacement_rate'] as $k)
                    @php $kpi = $kpis[$k] ?? null; if (!$kpi) continue; $colour = $tone($kpi['value'], $kpi['better']); $p = $palettes[$colour]; @endphp
                    <div class="bg-white rounded-xl border border-[#e8e4de] shadow-sm flex overflow-hidden">
                        <div class="w-1 {{ $p['bar'] }} shrink-0"></div>
                        <div class="flex-1 p-4">
                            <div class="text-[11px] uppercase tracking-wider text-[#8a8578] font-medium">{{ $kpi['label'] }}</div>
                            <div class="text-2xl font-bold text-[#2d3748] mt-1 tabular-nums">{{ $fmt($kpi['value'], $kpi['unit']) }}</div>
                            <div class="text-[11px] text-[#7c7466] mt-0.5">{{ $kpi['sub'] }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="bg-white border border-[#e8e4de] rounded-xl p-5">
            <h3 class="text-sm font-semibold text-[#1e3d44] mb-3">Components</h3>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                @foreach ([
                    'fixed_assets_now'   => 'Fixed Assets (now)',
                    'fixed_assets_start' => 'Fixed Assets (start of year)',
                    'equity'             => 'Equity (Class 10)',
                    'lt_debt'            => 'Long-term debt (Class 16)',
                    'total_assets'       => 'Total Assets',
                    'revenue_ttm'        => 'Revenue (TTM)',
                    'ebit_ttm'           => 'EBIT (TTM)',
                    'ebit_ytd'           => 'EBIT YTD',
                ] as $key => $label)
                    <div class="bg-[#fcfbf8] rounded-lg p-3 border border-[#f1ede5]">
                        <div class="text-[10px] uppercase tracking-wider text-[#8a8578] font-medium">{{ $label }}</div>
                        <div class="text-lg font-bold text-[#2d3748] mt-1 tabular-nums">{{ ea_money_short((float) ($comp[$key] ?? 0), $ccy) }}</div>
                    </div>
                @endforeach
            </div>
            <div class="text-[11px] text-[#7c7466] mt-4 leading-relaxed">
                <strong>Methodology:</strong> Capex = Δ Class 2 net fixed assets + D&amp;A YTD (because the Class 2 balance = additions − depreciation). ROCE = EBIT TTM ÷ (Equity + LT Debt). Asset Turnover = Revenue TTM ÷ Total Assets. Replacement rate &gt; 100% means depreciation is outpacing capex — assets wearing down faster than being replaced.
            </div>
        </div>
    @endif
@endsection
