@extends('layouts.app')
@section('title', 'Dashboard')

@section('header')
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Dashboard</h1>
            <p class="text-xs text-slate-500 mt-1">Top-management pulse · 11 KPIs across Financial, Operational, Compliance</p>
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
            $cur     = $analysis['currency'] ?? 'XAF';
            $kpis    = $analysis['kpis']     ?? [];
            $type    = $period['type']  ?? 'ytd';
            $year    = $period['year']  ?? (int) date('Y');
            $month   = $period['month'] ?? (int) date('m');

            $fmtN   = fn ($n) => number_format((float) $n, 0, '.', ',');
            $fmtPct = function ($p) {
                if ($p === null) return '—';
                $n = (float) $p * 100.0;
                $sign = $n > 0 ? '+' : '';
                return $sign . number_format($n, 1) . ' %';
            };
            $chipClass = function ($p) {
                if ($p === null) return 'text-slate-500 bg-slate-100 border-slate-200';
                if ($p >= 0)     return 'text-emerald-700 bg-emerald-50 border-emerald-200';
                if ($p >= -0.05) return 'text-amber-700 bg-amber-50 border-amber-200';
                return 'text-rose-700 bg-rose-50 border-rose-200';
            };
            $arrow = fn ($p) => $p === null ? '·' : ($p >= 0 ? '↑' : '↓');
        @endphp

        {{-- Period filter --}}
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-4 mb-4">
            <form method="GET" action="{{ tenant_url('/') }}" class="flex flex-wrap items-end gap-4">
                <div>
                    <label class="block text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-1.5">Period</label>
                    <div class="inline-flex border border-slate-300 rounded-lg overflow-hidden">
                        @foreach (['ytd' => 'YTD', 'mtd' => 'MTD', 'month' => 'Month'] as $v => $l)
                            <a href="{{ tenant_url('/') }}?period={{ $v }}&year={{ $year }}&month={{ $month }}"
                               class="px-3 py-1.5 text-sm {{ $type === $v ? 'bg-slate-900 text-white' : 'bg-white text-slate-700 hover:bg-slate-50' }}">
                                {{ $l }}
                            </a>
                        @endforeach
                    </div>
                </div>
                <div>
                    <label class="block text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-1.5">Year</label>
                    <select name="year" onchange="this.form.submit()" class="border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white">
                        @foreach (range((int) date('Y'), (int) date('Y') - 4) as $y)
                            <option value="{{ $y }}" {{ $year === $y ? 'selected' : '' }}>{{ $y }}</option>
                        @endforeach
                    </select>
                </div>
                @if ($type === 'month')
                    <div>
                        <label class="block text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-1.5">Month</label>
                        <select name="month" onchange="this.form.submit()" class="border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white">
                            @foreach (range(1, 12) as $m)
                                <option value="{{ $m }}" {{ $month === $m ? 'selected' : '' }}>{{ \DateTime::createFromFormat('!m', (string) $m)->format('M') }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <input type="hidden" name="period" value="{{ $type }}">
                <div class="flex-1 text-right text-[11px] text-slate-500">{{ $periodLabel ?? '' }}</div>
            </form>
        </div>

        {{-- Theme A · Financial --}}
        <div class="mb-3">
            <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-2 px-1">Theme A · Financial Performance</div>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3">

                {{-- F1 --}}
                @php $k = $kpis['F1'] ?? []; @endphp
                <a href="{{ $k['drill'] ?? '#' }}" class="bg-white border border-slate-200 rounded-xl shadow-sm p-4 hover:shadow-md transition block">
                    <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">{{ $k['label'] ?? 'F1' }}</div>
                    <div class="text-2xl font-bold tabular-nums text-slate-900 mt-1">
                        {{ $k['value_fmt'] ?? '—' }}
                        <span class="text-sm font-medium text-slate-500">{{ $k['unit'] ?? '' }}</span>
                    </div>
                    <div class="flex flex-wrap items-center gap-1.5 mt-2">
                        @if (array_key_exists('vs_budget', $k) && $k['vs_budget'] !== null)
                            <span class="inline-flex items-center gap-1 text-[10px] font-semibold uppercase tracking-wider px-2 py-0.5 rounded-full border {{ $chipClass($k['vs_budget']) }}">
                                {{ $arrow($k['vs_budget']) }} {{ $fmtPct($k['vs_budget']) }} Budget
                            </span>
                        @endif
                        @if (array_key_exists('vs_py', $k) && $k['vs_py'] !== null)
                            <span class="inline-flex items-center gap-1 text-[10px] font-semibold uppercase tracking-wider px-2 py-0.5 rounded-full border {{ $chipClass($k['vs_py']) }}">
                                {{ $arrow($k['vs_py']) }} {{ $fmtPct($k['vs_py']) }} PY
                            </span>
                        @endif
                    </div>
                    <div class="text-[10px] text-slate-400 mt-2">{{ $k['sub'] ?? '' }}</div>
                </a>

                {{-- F2 (wide_recon) --}}
                @php $k = $kpis['F2'] ?? []; @endphp
                <a href="{{ $k['drill'] ?? '#' }}" class="bg-white border border-slate-200 rounded-xl shadow-sm p-4 hover:shadow-md transition block">
                    <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">{{ $k['label'] ?? 'F2' }}</div>
                    <div class="grid grid-cols-3 gap-2 mt-2">
                        <div>
                            <div class="text-[9px] uppercase tracking-wider text-slate-500">Scanned</div>
                            <div class="text-sm font-semibold tabular-nums text-slate-900">{{ $fmtN($k['expected'] ?? 0) }}</div>
                        </div>
                        <div>
                            <div class="text-[9px] uppercase tracking-wider text-slate-500">Billed</div>
                            <div class="text-sm font-semibold tabular-nums text-slate-900">{{ $fmtN($k['billed'] ?? 0) }}</div>
                        </div>
                        <div>
                            <div class="text-[9px] uppercase tracking-wider text-slate-500">Collected</div>
                            <div class="text-sm font-semibold tabular-nums text-slate-900">{{ $fmtN($k['collected'] ?? 0) }}</div>
                        </div>
                    </div>
                    @php
                        $br = $k['billing_realisation_pct']    ?? null;
                        $cr = $k['collection_realisation_pct'] ?? null;
                        $brFr = $br === null ? null : ($br - 100) / 100;
                        $crFr = $cr === null ? null : ($cr - 100) / 100;
                    @endphp
                    <div class="flex flex-wrap items-center gap-1.5 mt-2">
                        <span class="inline-flex items-center gap-1 text-[10px] font-semibold uppercase tracking-wider px-2 py-0.5 rounded-full border {{ $chipClass($brFr) }}">
                            B/S {{ $br === null ? '—' : number_format((float) $br, 1) . ' %' }}
                        </span>
                        <span class="inline-flex items-center gap-1 text-[10px] font-semibold uppercase tracking-wider px-2 py-0.5 rounded-full border {{ $chipClass($crFr) }}">
                            C/B {{ $cr === null ? '—' : number_format((float) $cr, 1) . ' %' }}
                        </span>
                    </div>
                    <div class="text-[10px] text-slate-400 mt-2">{{ $k['sub'] ?? '' }} · YTD</div>
                </a>

                {{-- F3 --}}
                @php $k = $kpis['F3'] ?? []; @endphp
                <a href="{{ $k['drill'] ?? '#' }}" class="bg-white border border-slate-200 rounded-xl shadow-sm p-4 hover:shadow-md transition block">
                    <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">{{ $k['label'] ?? 'F3' }}</div>
                    <div class="text-2xl font-bold tabular-nums text-slate-900 mt-1">
                        {{ $k['value_fmt'] ?? '—' }}
                        <span class="text-sm font-medium text-slate-500">{{ $k['unit'] ?? '' }}</span>
                    </div>
                    <div class="flex flex-wrap items-center gap-1.5 mt-2">
                        @if (array_key_exists('vs_budget', $k) && $k['vs_budget'] !== null)
                            <span class="inline-flex items-center gap-1 text-[10px] font-semibold uppercase tracking-wider px-2 py-0.5 rounded-full border {{ $chipClass($k['vs_budget']) }}">
                                {{ $arrow($k['vs_budget']) }} {{ $fmtPct($k['vs_budget']) }} Budget
                            </span>
                        @endif
                    </div>
                    <div class="text-[10px] text-slate-400 mt-2">{{ $k['sub'] ?? '' }}</div>
                </a>

                {{-- F4 (wide_aging) --}}
                @php $k = $kpis['F4'] ?? []; @endphp
                <a href="{{ $k['drill'] ?? '#' }}" class="bg-white border border-slate-200 rounded-xl shadow-sm p-4 hover:shadow-md transition block">
                    <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">{{ $k['label'] ?? 'F4' }}</div>
                    <div class="text-2xl font-bold tabular-nums text-slate-900 mt-1">
                        {{ $fmtN($k['total_ar'] ?? 0) }}
                        <span class="text-sm font-medium text-slate-500">{{ $cur }}</span>
                    </div>
                    @php
                        $p30 = $k['pct_over_30'] ?? 0;
                        $p60 = $k['pct_over_60'] ?? 0;
                        $cls30 = $p30 > 25 ? 'text-rose-700 bg-rose-50 border-rose-200' : ($p30 > 10 ? 'text-amber-700 bg-amber-50 border-amber-200' : 'text-emerald-700 bg-emerald-50 border-emerald-200');
                        $cls60 = $p60 > 15 ? 'text-rose-700 bg-rose-50 border-rose-200' : ($p60 > 5  ? 'text-amber-700 bg-amber-50 border-amber-200' : 'text-emerald-700 bg-emerald-50 border-emerald-200');
                    @endphp
                    <div class="flex flex-wrap items-center gap-1.5 mt-2">
                        <span class="inline-flex items-center gap-1 text-[10px] font-semibold uppercase tracking-wider px-2 py-0.5 rounded-full border {{ $cls30 }}">
                            &gt; 30 d · {{ number_format($p30, 1) }} %
                        </span>
                        <span class="inline-flex items-center gap-1 text-[10px] font-semibold uppercase tracking-wider px-2 py-0.5 rounded-full border {{ $cls60 }}">
                            &gt; 60 d · {{ number_format($p60, 1) }} %
                        </span>
                    </div>
                    <div class="text-[10px] text-slate-400 mt-2">{{ $k['sub'] ?? '' }}</div>
                </a>
            </div>
        </div>

        {{-- Theme B · Operational --}}
        <div class="mb-3">
            <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-2 px-1">Theme B · Operational Efficiency</div>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3">
                @foreach (['O1', 'O2', 'O3', 'O4'] as $code)
                    @php $k = $kpis[$code] ?? []; @endphp
                    <a href="{{ $k['drill'] ?? '#' }}" class="bg-white border border-slate-200 rounded-xl shadow-sm p-4 hover:shadow-md transition block">
                        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">{{ $k['label'] ?? $code }}</div>
                        <div class="text-2xl font-bold tabular-nums text-slate-900 mt-1">
                            {{ $k['value_fmt'] ?? '—' }}
                            <span class="text-sm font-medium text-slate-500">{{ ($k['unit'] ?? '') === 'count' ? '' : (($k['unit'] ?? '') === 'pct' ? '%' : ($k['unit'] ?? '')) }}</span>
                        </div>
                        <div class="flex flex-wrap items-center gap-1.5 mt-2">
                            @if (array_key_exists('vs_py', $k) && $k['vs_py'] !== null)
                                <span class="inline-flex items-center gap-1 text-[10px] font-semibold uppercase tracking-wider px-2 py-0.5 rounded-full border {{ $chipClass($k['vs_py']) }}">
                                    {{ $arrow($k['vs_py']) }} {{ $fmtPct($k['vs_py']) }} PY
                                </span>
                            @endif
                            @if (!empty($k['pending']))
                                <span class="inline-flex items-center gap-1 text-[10px] font-semibold uppercase tracking-wider px-2 py-0.5 rounded-full border text-slate-500 bg-slate-100 border-slate-200">pending</span>
                            @endif
                        </div>
                        <div class="text-[10px] text-slate-400 mt-2">{{ $k['sub'] ?? '' }}</div>
                    </a>
                @endforeach
            </div>
        </div>

        {{-- Theme C · Compliance --}}
        <div class="mb-3">
            <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-2 px-1">Theme C · Compliance &amp; Risk</div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                {{-- C1 --}}
                @php $k = $kpis['C1'] ?? []; @endphp
                <a href="{{ $k['drill'] ?? '#' }}" class="bg-white border border-slate-200 rounded-xl shadow-sm p-4 hover:shadow-md transition block">
                    <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">{{ $k['label'] ?? 'C1' }}</div>
                    <div class="text-2xl font-bold tabular-nums text-slate-900 mt-1">
                        {{ $k['value_fmt'] ?? '—' }}
                        <span class="text-sm font-medium text-slate-500">%</span>
                    </div>
                    <div class="flex flex-wrap items-center gap-1.5 mt-2">
                        @if (array_key_exists('vs_py', $k) && $k['vs_py'] !== null)
                            <span class="inline-flex items-center gap-1 text-[10px] font-semibold uppercase tracking-wider px-2 py-0.5 rounded-full border {{ $chipClass($k['vs_py']) }}">
                                {{ $arrow($k['vs_py']) }} {{ $fmtPct($k['vs_py']) }} PY
                            </span>
                        @endif
                    </div>
                    <div class="text-[10px] text-slate-400 mt-2">{{ $k['sub'] ?? '' }}</div>
                </a>

                {{-- C2 --}}
                @php $k = $kpis['C2'] ?? []; @endphp
                <a href="{{ $k['drill'] ?? '#' }}" class="bg-white border border-slate-200 rounded-xl shadow-sm p-4 hover:shadow-md transition block">
                    <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">{{ $k['label'] ?? 'C2' }}</div>
                    <div class="text-2xl font-bold tabular-nums text-slate-900 mt-1">
                        {{ $k['value_fmt'] ?? '—' }}
                    </div>
                    <div class="text-[10px] text-slate-400 mt-2">{{ $k['sub'] ?? '' }}</div>
                </a>

                {{-- C3 (wide_tax) --}}
                @php $k = $kpis['C3'] ?? []; @endphp
                <a href="{{ $k['drill'] ?? '#' }}" class="bg-white border border-slate-200 rounded-xl shadow-sm p-4 hover:shadow-md transition block">
                    <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">{{ $k['label'] ?? 'C3' }}</div>
                    <div class="grid grid-cols-2 gap-2 mt-1">
                        <div>
                            <div class="text-[9px] uppercase tracking-wider text-slate-500">Issues</div>
                            <div class="text-2xl font-bold tabular-nums {{ ($k['issues_count'] ?? 0) > 0 ? 'text-rose-700' : 'text-slate-900' }}">{{ $k['issues_count'] ?? 0 }}</div>
                        </div>
                        <div>
                            <div class="text-[9px] uppercase tracking-wider text-slate-500">Exposure ({{ $cur }})</div>
                            <div class="text-2xl font-bold tabular-nums text-slate-900">{{ $k['exposure_fmt'] ?? '—' }}</div>
                        </div>
                    </div>
                    <div class="text-[10px] text-slate-400 mt-2">{{ $k['sub'] ?? '' }}</div>
                </a>
            </div>
        </div>

        {{-- Methodology --}}
        <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm text-[11px] text-slate-500 leading-relaxed">
            <strong class="text-slate-700">11 KPIs · 3 themes.</strong>
            Sourced via <code class="text-[10.5px] bg-slate-100 px-1 py-0.5 rounded">DashboardKpiService</code> from existing services
            (Operations, Billing, OHADA Financials, Receivables, Procurement, GL Quality, VAT Invoice Compliance, Monthly Tax Return).
            Click any card to drill into its source module. Period toggle drives Financial and Operational rows; Compliance is point-in-time.
        </div>
    @endif
@endsection
