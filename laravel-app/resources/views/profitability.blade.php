@extends('layouts.app')
@section('title', 'Profitability')
@section('page-title', 'Profitability')

@section('content')
    @php
        $ccy   = $data['currency'] ?? 'XAF';
        $money = fn ($v) => $v === null ? '—' : ea_money_short((float) $v, $ccy);
        $thisYear = (int) date('Y');
    @endphp

    {{-- Year selector --}}
    <div class="flex items-center justify-between gap-3 flex-wrap mb-4">
        <div class="text-[11px] text-[#7c7466]">
            @if ($data){{ $data['period']['label'] ?? '' }} · unit economics on actuals; trend shows the full year @endif
        </div>
        <div class="inline-flex items-center gap-1 bg-white border border-[#e8e4de] rounded-lg p-1 shadow-sm">
            @foreach (range($thisYear - 2, $thisYear) as $y)
                <a href="{{ tenant_url('/profit-loss?view=profitability&year=' . $y) }}"
                   class="px-2.5 py-1 text-[11px] font-semibold rounded {{ (int) $year === $y ? 'bg-brand-600 text-white' : 'text-[#7c7466] hover:bg-[#f5f2ec]' }}">{{ $y }}</a>
            @endforeach
        </div>
    </div>

    @if ($error)
        <div class="bg-coral-50 border border-coral-200 text-coral-800 rounded-xl p-4 text-sm mb-6">
            <strong>Could not load profitability:</strong> {{ $error }}
        </div>
    @elseif (!$data)
        <div class="bg-warm-50 border border-warm-200 rounded-xl p-5">
            <div class="text-sm font-semibold text-[#7a5a1a]">Awaiting data</div>
            <p class="text-xs text-[#7c7466] mt-1">No profitability data is available yet.</p>
        </div>
    @else
        @php
            $tot = $data['totals']; $scans = $data['scans']; $ps = $data['per_scan']; $g = $data['growth'];
            $n = (int) ($scans['total'] ?? 0);
            $scanSub = number_format($n) . ' scans' . ($n > 0
                ? ' · ' . number_format($scans['containers']) . ' cont · ' . number_format($scans['vehicles']) . ' veh · ' . number_format($scans['wood']) . ' wood'
                : '');
        @endphp

        {{-- ── Unit economics (per scan) ───────────────────────────── --}}
        <h2 class="text-[12px] font-semibold text-[#1e3d44] uppercase tracking-wide mb-2">Unit economics · per scan</h2>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-3">
            <x-kpi title="Revenue / scan" :value="$money($ps['revenue'])"
                   :sub="$money($tot['revenue']) . ' ÷ ' . number_format($n) . ' scans'"
                   :vsPy="$ps['revenue_vs_py']" prefer="up" color="indigo"
                   hint="Total revenue divided by the total number of scans (containers + vehicles + wood) in the period." />
            <x-kpi title="Cost / scan" :value="$money($ps['cost'])"
                   :sub="$money($tot['total_cost']) . ' ÷ ' . number_format($n) . ' scans'"
                   :vsPy="$ps['cost_vs_py']" prefer="down" color="amber"
                   hint="Total operating cost (COGS + OpEx + D&A) divided by total scans. Lower is better." />
            <x-kpi title="Employee cost / scan" :value="$money($ps['fte'])"
                   :sub="$money($tot['employee_cost']) . ' ÷ ' . number_format($n) . ' scans'"
                   :vsPy="$ps['fte_vs_py']" prefer="down" color="slate"
                   hint="SYSCOHADA class-66 personnel (FTE) cost divided by total scans. Lower is better." />
        </div>

        @if (!($scans['available'] ?? false))
            <div class="bg-warm-50 border border-warm-200 text-[#7a5a1a] rounded-lg p-3 text-[11px] mb-4">
                No scan-count source is available for this workspace, so per-scan figures can't be computed.
                The growth and revenue-per-day sections below are unaffected.
            </div>
        @endif

        {{-- Context totals --}}
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6 text-[11px]">
            <div class="bg-white border border-[#e8e4de] rounded-lg px-3 py-2">
                <div class="text-[#8a8578] uppercase tracking-wide">Total revenue</div>
                <div class="text-[15px] font-bold text-[#2d3748] tabular-nums">{{ $money($tot['revenue']) }}</div>
            </div>
            <div class="bg-white border border-[#e8e4de] rounded-lg px-3 py-2">
                <div class="text-[#8a8578] uppercase tracking-wide">Total cost</div>
                <div class="text-[15px] font-bold text-[#2d3748] tabular-nums">{{ $money($tot['total_cost']) }}</div>
            </div>
            <div class="bg-white border border-[#e8e4de] rounded-lg px-3 py-2">
                <div class="text-[#8a8578] uppercase tracking-wide">Employee cost</div>
                <div class="text-[15px] font-bold text-[#2d3748] tabular-nums">{{ $money($tot['employee_cost']) }}</div>
            </div>
            <div class="bg-white border border-[#e8e4de] rounded-lg px-3 py-2">
                <div class="text-[#8a8578] uppercase tracking-wide">Total scans</div>
                <div class="text-[15px] font-bold text-[#2d3748] tabular-nums">{{ number_format($n) }}</div>
                <div class="text-[10px] text-[#a09d95] truncate">{{ $scanSub }}</div>
            </div>
        </div>

        {{-- ── Profit growth (YoY) ─────────────────────────────────── --}}
        <h2 class="text-[12px] font-semibold text-[#1e3d44] uppercase tracking-wide mb-2">Profit growth · vs prior year</h2>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4">
            <x-kpi title="Revenue" :value="$money($tot['revenue'])" :vsPy="$g['revenue_pct']" prefer="up" color="indigo" />
            <x-kpi title="EBITDA"  :value="$money($tot['ebitda'])"  :vsPy="$g['ebitda_pct']"  prefer="up" color="emerald" />
            <x-kpi title="EBIT"    :value="$money($tot['ebit'])"    :vsPy="$g['ebit_pct']"    prefer="up" color="amber" />
        </div>

        {{-- ── Trend chart: Revenue / EBITDA / EBIT (monthly) ──────── --}}
        <div class="bg-white rounded-xl border border-[#e8e4de] p-5 shadow-sm mb-6">
            <h2 class="text-sm font-semibold text-[#1e3d44] mb-1">Revenue · EBITDA · EBIT — monthly trend</h2>
            <p class="text-[11px] text-[#7c7466] mb-3">Solid = actuals, dashed = forecast. Figures in {{ $ccy }}.</p>
            <div class="h-80"><canvas id="profTrend"></canvas></div>
        </div>

        {{-- ── Revenue per day (monthly) ──────────────────────────── --}}
        <div class="bg-white rounded-xl border border-[#e8e4de] p-5 shadow-sm mb-6">
            <h2 class="text-sm font-semibold text-[#1e3d44] mb-1">Revenue per day — monthly</h2>
            <p class="text-[11px] text-[#7c7466] mb-3">Monthly revenue ÷ calendar days in the month. Solid = actuals, dashed = forecast.</p>
            <div class="h-72"><canvas id="profRpd"></canvas></div>
        </div>

        @if (!empty($data['notes']))
            <div class="bg-[#f5f2ec] border border-[#e8e4de] text-[#7c7466] rounded-lg p-3 text-[11px]">
                <span class="font-semibold">Notes:</span> {{ implode(' ', $data['notes']) }}
            </div>
        @endif

        @push('scripts')
        <script>
        (function () {
            const trend = @json($data['trend']);
            const rpd   = @json($data['rev_per_day']);
            const thru  = +(@json($data['trend']['thru_month'] ?? 0)) || 0;

            const fmt = v => { v = Math.abs(+v || 0);
                return v >= 1e9 ? (v/1e9).toFixed(2)+'B' : v >= 1e6 ? (v/1e6).toFixed(1)+'M' : v >= 1e3 ? (v/1e3).toFixed(0)+'K' : Math.round(v); };
            // Dash the segment once it crosses into forecast months (index >= thru).
            const dashForecast = { borderDash: ctx => ctx.p0DataIndex >= thru - 1 ? [5,4] : undefined };
            const baseOpts = {
                responsive:true, maintainAspectRatio:false,
                interaction:{ mode:'index', intersect:false },
                plugins:{ legend:{ labels:{ font:{size:11}, usePointStyle:true } },
                          tooltip:{ backgroundColor:'#1e3d44', callbacks:{ label: c => c.dataset.label + ': ' + fmt(c.parsed.y) } } },
                scales:{ x:{ grid:{display:false}, ticks:{font:{size:10}} },
                         y:{ grid:{color:'#f1ede5'}, ticks:{font:{size:10}, callback:fmt} } }
            };

            const line = (label, data, color) => ({
                label, data, borderColor:color, backgroundColor:color,
                tension:0.3, borderWidth:2, pointRadius:2, pointHoverRadius:4, fill:false, segment: dashForecast,
            });

            new Chart(document.getElementById('profTrend'), {
                type:'line',
                data:{ labels: trend.labels, datasets:[
                    line('Revenue', trend.revenue, '#2f6f76'),
                    line('EBITDA',  trend.ebitda,  '#6a994e'),
                    line('EBIT',    trend.ebit,    '#d98c3f'),
                ]},
                options: baseOpts,
            });

            new Chart(document.getElementById('profRpd'), {
                type:'line',
                data:{ labels: rpd.labels, datasets:[
                    Object.assign(line('Revenue / day', rpd.values, '#2f6f76'), { fill:true, backgroundColor:'rgba(47,111,118,0.08)' }),
                ]},
                options: Object.assign({}, baseOpts, { plugins: Object.assign({}, baseOpts.plugins, { legend:{display:false} }) }),
            });
        })();
        </script>
        @endpush
    @endif
@endsection
