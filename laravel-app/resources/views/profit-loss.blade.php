@extends('layouts.app')
@section('title', ($profMode ?? false) ? 'Profitability' : 'Profit & Loss')
@section('page-title', ($profMode ?? false) ? 'Profitability' : 'Profit & Loss')
@section('content')
{{-- Logistics sector: unit economics + débours reconciliation, computed
     from the dossier warehouse. Rendered above the classic sections and
     independent of the Odoo-based analysis (which logistics tenants lack). --}}
@includeWhen(!empty($logistics), 'logistics.profitability-section', ['logistics' => $logistics ?? null])
@if ($profMode ?? false)
    {{-- ====================== PROFITABILITY ======================
         Rendered through this (already-compiled) P&L view on purpose: the
         host can't compile a brand-new template at runtime, so a dedicated
         profitability.blade.php 500s. Same markup, hosted here. --}}
    @php
        $ccy   = $data['currency'] ?? 'XAF';
        $money = fn ($v) => $v === null ? '—' : ea_money_short((float) $v, $ccy);
        $thisYear = (int) date('Y');
    @endphp

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

        <h2 class="text-[12px] font-semibold text-[#1e3d44] uppercase tracking-wide mb-2">Profit growth · vs prior year</h2>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4">
            <x-kpi title="Revenue" :value="$money($tot['revenue'])" :vsPy="$g['revenue_pct']" prefer="up" color="indigo" />
            <x-kpi title="EBITDA"  :value="$money($tot['ebitda'])"  :vsPy="$g['ebitda_pct']"  prefer="up" color="emerald" />
            <x-kpi title="EBIT"    :value="$money($tot['ebit'])"    :vsPy="$g['ebit_pct']"    prefer="up" color="amber" />
        </div>

        <div class="bg-white rounded-xl border border-[#e8e4de] p-5 shadow-sm mb-6">
            <h2 class="text-sm font-semibold text-[#1e3d44] mb-1">Revenue · EBITDA · EBIT — monthly trend</h2>
            <p class="text-[11px] text-[#7c7466] mb-3">Solid = actuals · dashed = forecast, read directly from the P&amp;L Forecast for months not yet closed. Figures in {{ $ccy }}.</p>
            <div class="h-80"><canvas id="profTrend"></canvas></div>
        </div>

        <div class="bg-white rounded-xl border border-[#e8e4de] p-5 shadow-sm mb-6">
            <div class="flex items-start justify-between gap-3 flex-wrap mb-1">
                <h2 class="text-sm font-semibold text-[#1e3d44]">Unit economics per scan — monthly</h2>
                <label class="inline-flex items-center gap-2 text-[11px] font-medium text-[#5a6a70] cursor-pointer select-none">
                    <input type="checkbox" id="profExEmpty" class="rounded border-[#cbc7be] text-brand-600 focus:ring-brand-500">
                    Exclude empty containers
                </label>
            </div>
            <p class="text-[11px] text-[#7c7466] mb-3">Revenue per scan and cost per scan, by month since January (closed months only — actual revenue/cost ÷ actual scans). Empty containers are scanned but earn no revenue; tick the box to exclude them from the denominator. Figures in {{ $ccy }}.</p>
            <div class="h-72"><canvas id="profUnitMonthly"></canvas></div>
        </div>

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

            const ups = @json($data['monthly_per_scan']);
            const upsChart = new Chart(document.getElementById('profUnitMonthly'), {
                type:'line',
                data:{ labels: ups.labels, datasets:[
                    { label:'Revenue / scan', data: ups.revenue, borderColor:'#2f6f76', backgroundColor:'#2f6f76', tension:0.3, borderWidth:2, pointRadius:3, pointHoverRadius:5, spanGaps:false, fill:false },
                    { label:'Cost / scan',    data: ups.cost,    borderColor:'#d98c3f', backgroundColor:'#d98c3f', tension:0.3, borderWidth:2, pointRadius:3, pointHoverRadius:5, spanGaps:false, fill:false },
                ]},
                options: baseOpts,
            });
            const exToggle = document.getElementById('profExEmpty');
            if (exToggle) exToggle.addEventListener('change', function () {
                const ex = this.checked;
                upsChart.data.datasets[0].data = ex ? ups.revenue_ex_empty : ups.revenue;
                upsChart.data.datasets[1].data = ex ? ups.cost_ex_empty    : ups.cost;
                upsChart.data.datasets[0].label = ex ? 'Revenue / scan (ex-empty)' : 'Revenue / scan';
                upsChart.data.datasets[1].label = ex ? 'Cost / scan (ex-empty)'    : 'Cost / scan';
                upsChart.update();
            });
        })();
        </script>
        @endpush
    @endif
@else
    {{-- ====================== PROFIT & LOSS (existing) ====================== --}}
    @if ($error)
        <div class="bg-rose-50 border border-rose-200 text-rose-700 rounded-xl p-4 text-sm">{{ $error }}</div>
    @elseif ($data)
        @php $cur = $data['current'] ?? []; $ccy = $data['currency'] ?? 'USD'; @endphp

        <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-4 mb-6">
            <x-kpi title="Revenue"      :value="ea_money_short((float)($cur['revenue'] ?? 0), $ccy)" color="indigo"/>
            <x-kpi title="Gross Profit" :value="ea_money_short((float)($cur['gross_profit'] ?? 0), $ccy)"
                   :sub="'GM ' . number_format((float)($cur['gross_margin_pct'] ?? $cur['gross_margin'] ?? 0), 1) . '%'" color="emerald"/>
            <x-kpi title="EBITDA"       :value="ea_money_short((float)($cur['ebitda'] ?? 0), $ccy)"
                   :sub="number_format((float)($cur['ebitda_margin_pct'] ?? $cur['ebitda_margin'] ?? 0), 1) . '%'" color="indigo"/>
            <x-kpi title="EBIT"         :value="ea_money_short((float)($cur['ebit'] ?? 0), $ccy)"
                   :sub="number_format((float)($cur['ebit_margin_pct'] ?? $cur['ebit_margin'] ?? 0), 1) . '%'" color="amber"/>
            <x-kpi title="Net Profit"   :value="ea_money_short((float)($cur['net_profit'] ?? 0), $ccy)"
                   :sub="number_format((float)($cur['net_margin_pct'] ?? $cur['net_margin'] ?? 0), 1) . '%'"
                   :color="($cur['net_profit'] ?? 0) >= 0 ? 'emerald' : 'rose'"/>
            <x-kpi title="OpEx"         :value="ea_money_short((float)($cur['opex'] ?? 0), $ccy)" color="slate"/>
        </div>

        <div class="bg-white rounded-xl border border-slate-200 p-5 shadow-sm">
            <h2 class="text-sm font-semibold text-slate-900 mb-3">P&amp;L waterfall</h2>
            <div class="h-72"><canvas id="plWaterfall"></canvas></div>
        </div>

        @push('scripts')
        <script>
        (function(){
            const c = @json($cur);
            const items = [
                ['Revenue',      +c.revenue      || 0, '#6366f1'],
                ['– COGS',       -(+c.cogs       || 0), '#f87171'],
                ['Gross Profit', +c.gross_profit || 0, '#10b981'],
                ['– OpEx',       -(+c.opex       || 0), '#fb923c'],
                ['EBITDA',       +c.ebitda       || 0, '#6366f1'],
                ['– D&A',        -(+(c.d_and_a ?? c.depreciation) || 0), '#f87171'],
                ['EBIT',         +c.ebit         || 0, '#f59e0b'],
                ['– Tax/Int',    -((+c.tax || 0)+(+c.interest || 0)), '#f87171'],
                ['Net Profit',   +c.net_profit   || 0, (+c.net_profit||0)>=0 ? '#10b981' : '#ef4444'],
            ];
            new Chart(document.getElementById('plWaterfall'), {
                type:'bar',
                data:{ labels:items.map(i=>i[0]),
                       datasets:[{ data:items.map(i=>i[1]), backgroundColor:items.map(i=>i[2]), borderRadius:4 }]},
                options:{responsive:true,maintainAspectRatio:false,
                    plugins:{legend:{display:false}, tooltip:{backgroundColor:'#0f172a'}},
                    scales:{x:{grid:{display:false},ticks:{font:{size:10}}},
                            y:{grid:{color:'#f1f5f9'},ticks:{font:{size:10},
                                callback:v=>{v=Math.abs(v);return v>=1e6?(v/1e6).toFixed(1)+'M':v>=1e3?(v/1e3).toFixed(0)+'K':v}}}}}
            });
        })();
        </script>
        @endpush
    @endif
@endif
@endsection
