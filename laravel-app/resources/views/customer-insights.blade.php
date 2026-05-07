@extends('layouts.app')
@section('title', 'Customer Insights')

@section('page-title', 'Customer Insights')
@section('content')
    @if ($error)
        <div class="bg-coral-50 border border-coral-200 text-coral-800 rounded-xl p-4 text-sm">{{ $error }}</div>
    @elseif (!$insights)
        <div class="bg-warm-50 border border-warm-200 text-warm-800 rounded-xl p-4 text-sm">Customer insight data is not available yet.</div>
    @else
        @php
            $summary        = $insights['summary'] ?? [];
            $profitability  = $insights['profitability'] ?? [];
            $concentration  = $insights['concentration'] ?? [];
            $momentum       = $insights['momentum'] ?? [];
            $recommendations = $insights['recommendations'] ?? [];
            $currency       = $insights['currency'] ?? ($data['currency'] ?? 'USD');
            $activeSummary  = $insights['active_summary'] ?? [];
            $monthlySeries  = $insights['monthly_series'] ?? [];
            $avgSpend       = $insights['avg_spend'] ?? [];
            $fmtPct = function ($value, $precision = 1) {
                return $value === null ? '—' : number_format((float) $value, $precision) . '%';
            };
            $fmtCount = fn($value) => number_format((int) ($value ?? 0));
            $fmtMoney = fn($value) => ea_money_short((float) ($value ?? 0), $currency);
            $topCustomers   = $profitability['most_profitable']  ?? [];
            $leastCustomers = $profitability['least_profitable'] ?? [];
            $scatter        = $insights['scatter'] ?? null;
        @endphp

        {{-- Summary KPIs --}}
        <div class="mb-7">
            <div class="flex items-center gap-2 mb-3">
                <div class="w-1 h-4 rounded bg-brand-400"></div>
                <h2 class="text-xs font-semibold uppercase tracking-wider text-[#5a6a70]">Customer Growth & Value</h2>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-3">
                <x-kpi title="Active Customers"
                       :value="$fmtCount($summary['customers'] ?? 0)"
                       :delta="$summary['growth_pct'] ?? null"
                       :sub="'vs ' . $fmtCount($summary['customers_py'] ?? 0) . ' PY'"
                       color="brand"
                       icon="M3 3v18h18M7 14l4-4 3 3 5-5"/>

                <x-kpi title="Avg Revenue / Customer"
                       :value="$fmtMoney($summary['avg_revenue'] ?? 0)"
                       :sub="'Total ' . $fmtMoney($summary['total_revenue'] ?? 0)"
                       color="sage"
                       icon="M4 6h16M4 12h10M4 18h6"/>

                <x-kpi title="Repeat Customers"
                       :value="$fmtCount($summary['repeat_customers'] ?? 0)"
                       :sub="$fmtPct($summary['repeat_rate'] ?? null) . ' repeat mix'"
                       color="coral"
                       icon="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20z"/>

                <x-kpi title="One-time Customers"
                       :value="$fmtCount($summary['one_time'] ?? 0)"
                       :sub="'New ' . $fmtCount($summary['new_customers'] ?? 0) . ' · Lost ' . $fmtCount($summary['lost_customers'] ?? 0)"
                       prefer="down"
                       color="warm"
                       icon="M3 7h18M3 12h18M3 17h18"/>

                <x-kpi title="Active Customers"
                       :value="$fmtCount($activeSummary['active_customers'] ?? 0)"
                       :sub="'Inactive ' . $fmtCount($activeSummary['inactive_customers'] ?? 0)"
                       color="brand"
                       icon="M4 4h16v16H4z"/>
            </div>
        </div>

        {{-- Profitability Tables --}}

        {{-- Cohort & Concentration --}}
        <div class="grid lg:grid-cols-3 gap-4 mb-7">
            <div class="section-card p-5">
                <div class="flex items-center gap-2 mb-4">
                    <div class="w-1 h-4 rounded bg-sage-400"></div>
                    <div>
                        <h2 class="text-[13px] font-semibold text-[#1e3d44]">Repeat vs One-time</h2>
                        <p class="text-[11px] text-[#8a8578]">Gauge loyalty depth</p>
                    </div>
                </div>
                <div class="space-y-4">
                    <div>
                        <div class="flex items-center justify-between text-[12px] text-[#5a6a70]">
                            <span>Repeat customers</span>
                            <span>{{ $fmtCount($summary['repeat_customers'] ?? 0) }}</span>
                        </div>
                        <div class="mt-2 h-2 rounded-full bg-sage-100 overflow-hidden">
                            @php $repeatPct = min(max((float) ($summary['repeat_rate'] ?? 0), 0), 100); @endphp
                            <div class="h-full bg-sage-500" style="width: {{ $repeatPct }}%"></div>
                        </div>
                        <p class="text-[11px] text-[#8a8578] mt-1">{{ $fmtPct($summary['repeat_rate'] ?? null) }} of base</p>
                    </div>
                    <div>
                        <div class="flex items-center justify-between text-[12px] text-[#5a6a70]">
                            <span>One-time customers</span>
                            <span>{{ $fmtCount($summary['one_time'] ?? 0) }}</span>
                        </div>
                        <div class="mt-2 h-2 rounded-full bg-coral-100 overflow-hidden">
                            @php $oneTimePct = $summary['customers'] ? max(min((($summary['one_time'] ?? 0) / max($summary['customers'] ?? 1, 1)) * 100, 100), 0) : 0; @endphp
                            <div class="h-full bg-coral-500" style="width: {{ $oneTimePct }}%"></div>
                        </div>
                        <p class="text-[11px] text-[#8a8578] mt-1">{{ number_format($oneTimePct, 1) }}% of base</p>
                    </div>
                </div>
            </div>

            <div class="section-card p-5">
                <div class="flex items-center gap-2 mb-4">
                    <div class="w-1 h-4 rounded bg-warm-400"></div>
                    <div>
                        <h2 class="text-[13px] font-semibold text-[#1e3d44]">New vs Lost Accounts</h2>
                        <p class="text-[11px] text-[#8a8578]">Track acquisition churn balance</p>
                    </div>
                </div>
                <div class="space-y-3">
                    <div class="flex items-center justify-between text-[13px] font-semibold text-[#2d3748]">
                        <span>New customers</span>
                        <span>{{ $fmtCount($summary['new_customers'] ?? 0) }}</span>
                    </div>
                    <div class="flex items-center justify-between text-[13px] font-semibold text-[#2d3748]">
                        <span>Lost customers</span>
                        <span class="text-coral-600">{{ $fmtCount($summary['lost_customers'] ?? 0) }}</span>
                    </div>
                    <p class="text-[11px] text-[#8a8578]">Net change: {{ $fmtCount(($summary['new_customers'] ?? 0) - ($summary['lost_customers'] ?? 0)) }}</p>
                </div>
            </div>

            <div class="section-card p-5">
                <div class="flex items-center gap-2 mb-4">
                    <div class="w-1 h-4 rounded bg-brand-400"></div>
                    <div>
                        <h2 class="text-[13px] font-semibold text-[#1e3d44]">Revenue Concentration</h2>
                        <p class="text-[11px] text-[#8a8578]">Top accounts dependency</p>
                    </div>
                </div>
                <div class="space-y-3 text-[13px] text-[#2d3748]">
                    <div class="flex items-center justify-between">
                        <span>Top 5 share</span>
                        <span>{{ $fmtPct($concentration['top5_share_pct'] ?? null) }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span>Top 10 share</span>
                        <span>{{ $fmtPct($concentration['top10_share_pct'] ?? null) }}</span>
                    </div>
                </div>
                <div class="mt-4">
                    @php
                        $top5 = min(max((float) ($concentration['top5_share_pct'] ?? 0), 0), 100);
                    @endphp
                    <div class="h-2 rounded-full bg-brand-100 overflow-hidden">
                        <div class="h-full bg-brand-500" style="width: {{ $top5 }}%"></div>
                    </div>
                    <p class="text-[11px] text-[#8a8578] mt-1">Aim for &lt; 40% to reduce key-account risk.</p>
                </div>
            </div>
        </div>

        {{-- Trend chart --}}
        @if (!empty($monthlySeries))
        <div class="section-card p-5 mb-7">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h2 class="text-[13px] font-semibold text-[#1e3d44]">Active vs One-time Customers</h2>
                    <p class="text-[11px] text-[#8a8578]">Active = 3 consecutive purchasing months</p>
                </div>
                <div class="flex gap-3 text-[10px] text-[#8a8578]">
                    <div class="flex items-center gap-1.5"><span class="w-3 h-[3px] bg-brand-500 rounded"></span>Active</div>
                    <div class="flex items-center gap-1.5"><span class="w-3 h-[3px] bg-coral-500 rounded"></span>One-time</div>
                </div>
            </div>
            <div class="h-60">
                <canvas id="customerTrendChart"></canvas>
            </div>
        </div>
        @push('scripts')
        <script>
        (function(){
            const series = @json($monthlySeries);
            const labels = series.map(row => row.label);
            const active = series.map(row => row.active);
            const oneTime = series.map(row => row.one_time);
            const ctx = document.getElementById('customerTrendChart');
            if (!ctx) return;
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels,
                    datasets: [
                        {
                            label: 'Active',
                            data: active,
                            borderColor: '#2d7280',
                            backgroundColor: 'rgba(45,114,128,0.12)',
                            tension: 0.25,
                            fill: true,
                            pointRadius: 0,
                            borderWidth: 2,
                        },
                        {
                            label: 'One-time',
                            data: oneTime,
                            borderColor: '#d06a50',
                            backgroundColor: 'rgba(208,106,80,0.1)',
                            tension: 0.25,
                            fill: false,
                            pointRadius: 0,
                            borderWidth: 2,
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { intersect: false, mode: 'index' },
                    plugins: {
                        legend: { display: false },
                        tooltip: { backgroundColor: '#1c2637', titleFont: { size: 11 }, bodyFont: { size: 11 }, padding: 10, cornerRadius: 8 }
                    },
                    scales: {
                        x: { grid: { display: false }, ticks: { color: '#8a8578', maxTicksLimit: 8 } },
                        y: { grid: { color: '#f0ede7' }, ticks: { color: '#8a8578' } }
                    }
                }
            });
        })();
        </script>
        @endpush
        @endif

        {{-- Momentum & Recommendations --}}
        <div class="grid lg:grid-cols-2 gap-4 mb-7">
            <div class="section-card p-5">
                <div class="flex items-center gap-2 mb-4">
                    <div class="w-1 h-4 rounded bg-slate-400"></div>
                    <div>
                        <h2 class="text-[13px] font-semibold text-[#1e3d44]">Account Momentum</h2>
                        <p class="text-[11px] text-[#8a8578]">Year-on-year wallet share shifts</p>
                    </div>
                </div>
                <div class="grid grid-cols-3 gap-3 text-center">
                    <div class="p-3 rounded-lg bg-emerald-50 border border-emerald-100">
                        <p class="text-[10px] uppercase tracking-wider text-emerald-700">Accelerating</p>
                        <p class="text-xl font-semibold text-emerald-800 mt-1">{{ $fmtCount($momentum['accelerating'] ?? 0) }}</p>
                        <p class="text-[11px] text-[#5a6a70] mt-0.5">≥ +15% YoY</p>
                    </div>
                    <div class="p-3 rounded-lg bg-slate-50 border border-slate-100">
                        <p class="text-[10px] uppercase tracking-wider text-slate-600">Stable</p>
                        <p class="text-xl font-semibold text-slate-900 mt-1">{{ $fmtCount($momentum['stable'] ?? 0) }}</p>
                        <p class="text-[11px] text-[#5a6a70] mt-0.5">±15% YoY</p>
                    </div>
                    <div class="p-3 rounded-lg bg-coral-50 border border-coral-100">
                        <p class="text-[10px] uppercase tracking-wider text-coral-700">Declining</p>
                        <p class="text-xl font-semibold text-coral-800 mt-1">{{ $fmtCount($momentum['declining'] ?? 0) }}</p>
                        <p class="text-[11px] text-[#5a6a70] mt-0.5">≤ -15% YoY</p>
                    </div>
                </div>
            </div>

            <div class="section-card p-5 bg-brand-50/60 border-brand-100">
                <div class="flex items-center gap-2 mb-4">
                    <div class="w-1 h-4 rounded bg-brand-500"></div>
                    <div>
                        <h2 class="text-[13px] font-semibold text-[#1e3d44]">Action Plan</h2>
                        <p class="text-[11px] text-[#5a6a70]">Targeted moves to enhance revenue per day</p>
                    </div>
                </div>
                <ul class="space-y-3 text-[12px] text-[#1e3d44]">
                    @foreach ($recommendations as $tip)
                        <li class="flex gap-2">
                            <span class="mt-0.5 w-1.5 h-1.5 rounded-full bg-brand-500 shrink-0"></span>
                            <span>{{ $tip }}</span>
                        </li>
                    @endforeach
                </ul>
                <div class="mt-4 p-3 rounded-lg bg-white/60 border border-brand-100">
                    <p class="text-[11px] uppercase tracking-wider text-[#8a8578] font-medium mb-1">Avg Spend (Top vs Bottom 5)</p>
                    <p class="text-sm text-[#1e3d44]">
                        Top: <span class="font-semibold">{{ $fmtMoney($avgSpend['top5'] ?? 0) }}</span>
                        · Bottom: <span class="font-semibold text-coral-600">{{ $fmtMoney($avgSpend['bottom5'] ?? 0) }}</span>
                    </p>
                </div>
            </div>
        </div>

        {{-- ── Profitability scatter ──────────────────────────────────
             Replaces the legacy "Top 5 Most/Least Profitable" tables.
             X = revenue (spend) · Y = gross margin contribution
             Median split into 3 visual bands:
               • GREEN  — Stars (top-right): above median on both axes
               • RED    — Underperformers (bottom-left): below median on both
               • ORANGE — Mixed: high on one axis, low on the other
             Click any dot to highlight + populate the side panel with
             that customer's revenue, margin, margin %, orders, and
             share of total margin. ──────────────────────────────── --}}
        @if ($scatter && !empty($scatter['points']))
            @php
                $sumGreen  = $scatter['summary']['green']  ?? ['count' => 0];
                $sumOrange = $scatter['summary']['orange'] ?? ['count' => 0];
                $sumRed    = $scatter['summary']['red']    ?? ['count' => 0];
                $totRev    = (float) ($scatter['total_revenue'] ?? 0);
                $totMar    = (float) ($scatter['total_margin']  ?? 0);
                $thr       = $scatter['thresholds'] ?? ['revenue_median' => 0, 'margin_median' => 0];
            @endphp

            <div class="grid lg:grid-cols-3 gap-4 mb-4">
                {{-- Chart card spans 2 columns --}}
                <div class="section-card p-5 lg:col-span-2">
                    <div class="flex items-start justify-between mb-3 gap-3 flex-wrap">
                        <div>
                            <h2 class="text-[13px] font-semibold text-[#1e3d44]">Customer Profitability Map</h2>
                            <p class="text-[11px] text-[#8a8578]">
                                X = revenue · Y = gross margin contribution · click any dot for details
                            </p>
                        </div>
                        <div class="flex items-center gap-3 text-[11px]">
                            <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full" style="background:#16a34a"></span>Stars ({{ $sumGreen['count'] }})</span>
                            <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full" style="background:#ea580c"></span>Mixed ({{ $sumOrange['count'] }})</span>
                            <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full" style="background:#dc2626"></span>Underperformers ({{ $sumRed['count'] }})</span>
                            <span class="text-[#a09d95]">·</span>
                            <span class="text-[#a09d95] font-medium">Currency {{ $currency }}</span>
                        </div>
                    </div>
                    <div id="ec-customer-scatter" style="height: 420px;"></div>
                    <div class="mt-3 grid grid-cols-3 gap-3 text-[11px]">
                        <div class="rounded-lg border border-emerald-200 bg-emerald-50/60 p-2">
                            <div class="font-semibold text-emerald-800">Stars · {{ $sumGreen['count'] }}</div>
                            <div class="text-emerald-700/80">{{ number_format($sumGreen['share_revenue_pct'] ?? 0, 1) }}% of revenue · {{ number_format($sumGreen['share_margin_pct'] ?? 0, 1) }}% of margin</div>
                        </div>
                        <div class="rounded-lg border border-orange-200 bg-orange-50/60 p-2">
                            <div class="font-semibold text-orange-800">Mixed · {{ $sumOrange['count'] }}</div>
                            <div class="text-orange-700/80">{{ number_format($sumOrange['share_revenue_pct'] ?? 0, 1) }}% of revenue · {{ number_format($sumOrange['share_margin_pct'] ?? 0, 1) }}% of margin</div>
                        </div>
                        <div class="rounded-lg border border-rose-200 bg-rose-50/60 p-2">
                            <div class="font-semibold text-rose-800">Underperformers · {{ $sumRed['count'] }}</div>
                            <div class="text-rose-700/80">{{ number_format($sumRed['share_revenue_pct'] ?? 0, 1) }}% of revenue · {{ number_format($sumRed['share_margin_pct'] ?? 0, 1) }}% of margin</div>
                        </div>
                    </div>
                </div>

                {{-- Selected-customer detail panel — populated on click --}}
                <div class="section-card p-5">
                    <h2 class="text-[13px] font-semibold text-[#1e3d44]">Selected customer</h2>
                    <p class="text-[11px] text-[#8a8578] mb-3" id="cs-detail-hint">Click a dot on the chart to see details</p>
                    <div id="cs-detail-card" class="hidden">
                        <div class="border-l-4 pl-3 py-1 mb-3" id="cs-detail-band-bar" style="border-color:#16a34a;">
                            <div class="text-[14px] font-semibold text-[#1e3d44]" id="cs-detail-name">—</div>
                            <div class="text-[10px] text-[#a09d95] font-mono" id="cs-detail-id">ID: —</div>
                        </div>
                        <dl class="space-y-2.5">
                            <div class="flex items-baseline justify-between gap-3">
                                <dt class="text-[11px] uppercase tracking-wider text-[#8a8578]">Revenue</dt>
                                <dd class="text-[14px] font-semibold tabular-nums text-[#1e3d44]" id="cs-detail-revenue">—</dd>
                            </div>
                            <div class="flex items-baseline justify-between gap-3">
                                <dt class="text-[11px] uppercase tracking-wider text-[#8a8578]">Margin contribution</dt>
                                <dd class="text-[14px] font-semibold tabular-nums" id="cs-detail-margin">—</dd>
                            </div>
                            <div class="flex items-baseline justify-between gap-3">
                                <dt class="text-[11px] uppercase tracking-wider text-[#8a8578]">Margin %</dt>
                                <dd class="text-[13px] font-medium tabular-nums" id="cs-detail-marginpct">—</dd>
                            </div>
                            <div class="flex items-baseline justify-between gap-3">
                                <dt class="text-[11px] uppercase tracking-wider text-[#8a8578]">Orders</dt>
                                <dd class="text-[13px] font-medium tabular-nums" id="cs-detail-orders">—</dd>
                            </div>
                            <div class="flex items-baseline justify-between gap-3">
                                <dt class="text-[11px] uppercase tracking-wider text-[#8a8578]">Share of revenue</dt>
                                <dd class="text-[13px] font-medium tabular-nums" id="cs-detail-share-rev">—</dd>
                            </div>
                            <div class="flex items-baseline justify-between gap-3">
                                <dt class="text-[11px] uppercase tracking-wider text-[#8a8578]">Share of margin</dt>
                                <dd class="text-[13px] font-medium tabular-nums" id="cs-detail-share-mar">—</dd>
                            </div>
                            <div class="flex items-baseline justify-between gap-3">
                                <dt class="text-[11px] uppercase tracking-wider text-[#8a8578]">Band</dt>
                                <dd class="text-[11px] font-semibold uppercase tracking-wider" id="cs-detail-band">—</dd>
                            </div>
                        </dl>
                    </div>
                    <div class="mt-4 pt-3 border-t border-[#e8e4de] text-[10.5px] text-[#8a8578] leading-relaxed">
                        <strong class="text-[#1e3d44]">Methodology.</strong>
                        Margin = <code class="text-[10px] bg-[#f5f3ed] px-1 rounded">price_subtotal − qty × product.standard_price</code>
                        per posted out_invoice line. Manual lines without a product (discounts, freight) are excluded.
                        Products with no cost set in Odoo contribute their full subtotal as margin (treat as zero-cost services).
                        Median split: above-median revenue AND margin → Stars · below-median both → Underperformers · otherwise Mixed.
                        Median revenue = {{ $fmtMoney($thr['revenue_median']) }} · Median margin = {{ $fmtMoney($thr['margin_median']) }}.
                    </div>
                </div>
            </div>

            @push('scripts')
            <script>
                (function () {
                    const el = document.getElementById('ec-customer-scatter');
                    if (!el || typeof echarts === 'undefined') return;

                    const points     = @json($scatter['points']);
                    const revMedian  = {{ (float) ($thr['revenue_median'] ?? 0) }};
                    const marMedian  = {{ (float) ($thr['margin_median'] ?? 0) }};
                    const ccy        = @json($currency);

                    // Compact number formatter — same vibe as the dashboard's
                    // shortNum helper. Keeps axis ticks legible at any scale.
                    const fmt = (v) => {
                        const a = Math.abs(v);
                        if (a >= 1_000_000_000) return (v / 1_000_000_000).toFixed(2) + 'B';
                        if (a >= 1_000_000)     return (v / 1_000_000).toFixed(2) + 'M';
                        if (a >= 1_000)         return (v / 1_000).toFixed(0) + 'K';
                        return Math.round(v).toLocaleString();
                    };
                    const fmtFull = (v) =>
                        ccy + ' ' + Math.round(v).toLocaleString('en-US');

                    const COLORS = { green: '#16a34a', orange: '#ea580c', red: '#dc2626' };
                    const BAND_LABELS = { green: 'Star', orange: 'Mixed', red: 'Underperformer' };

                    // Build per-series data — one series per band so legend
                    // toggling and colour assignment work cleanly. Symbol
                    // size is order-count-driven so high-frequency
                    // customers visually pop without dominating the axes.
                    const series = ['green', 'orange', 'red'].map((band) => ({
                        name: { green: 'Stars', orange: 'Mixed', red: 'Underperformers' }[band],
                        type: 'scatter',
                        data: points.filter(p => p.band === band).map(p => ({
                            value: [p.revenue, p.margin],
                            name: p.name, _meta: p,
                        })),
                        itemStyle: { color: COLORS[band], opacity: 0.75, borderColor: '#fff', borderWidth: 1 },
                        emphasis: { scale: 1.5, itemStyle: { opacity: 1, shadowBlur: 10, shadowColor: COLORS[band] } },
                        symbolSize: (val, p) => {
                            const orders = (p.data && p.data._meta && p.data._meta.orders) || 1;
                            // sqrt scaling so 100-order customers don't drown 1-order ones
                            return Math.max(8, Math.min(28, 6 + Math.sqrt(orders) * 2.5));
                        },
                    }));

                    // Median quadrant divider lines drawn via markLine
                    // on the first series so coordinate translation is
                    // automatic (saves us from doing pixel math).
                    series[0].markLine = {
                        silent: true,
                        symbol: 'none',
                        lineStyle: { type: 'dashed', color: '#94a3b8', width: 1 },
                        label: { show: false },
                        data: [
                            { xAxis: revMedian },
                            { yAxis: marMedian },
                        ],
                    };

                    const chart = echarts.init(el);
                    chart.setOption({
                        grid:    { left: 60, right: 16, top: 16, bottom: 48 },
                        tooltip: {
                            trigger: 'item',
                            backgroundColor: '#1e3d44', borderWidth: 0, padding: [8, 12],
                            textStyle: { color: '#fff', fontSize: 12 },
                            formatter: (p) => {
                                const m = p.data._meta;
                                return [
                                    `<b>${m.name}</b>`,
                                    `Revenue: ${fmtFull(m.revenue)}`,
                                    `Margin: ${fmtFull(m.margin)} (${m.margin_pct.toFixed(1)}%)`,
                                    `Orders: ${m.orders.toLocaleString()}`,
                                    `Band: ${BAND_LABELS[m.band]}`,
                                ].join('<br>');
                            },
                        },
                        xAxis: {
                            type: 'value', name: 'Revenue (' + ccy + ')',
                            nameLocation: 'middle', nameGap: 30, nameTextStyle: { color: '#5a6a70', fontSize: 11 },
                            axisLabel: { formatter: fmt, color: '#8a8578', fontSize: 10 },
                            splitLine: { lineStyle: { color: '#f0ede7' } },
                            axisLine: { lineStyle: { color: '#cbd5e1' } },
                        },
                        yAxis: {
                            type: 'value', name: 'Margin (' + ccy + ')',
                            nameLocation: 'middle', nameGap: 50, nameTextStyle: { color: '#5a6a70', fontSize: 11 },
                            axisLabel: { formatter: fmt, color: '#8a8578', fontSize: 10 },
                            splitLine: { lineStyle: { color: '#f0ede7' } },
                            axisLine: { lineStyle: { color: '#cbd5e1' } },
                        },
                        series: series,
                    });

                    // Click handler — populate the side detail panel + visually
                    // emphasise the clicked point via dispatchAction.
                    let lastSelected = null;
                    chart.on('click', (params) => {
                        const m = params.data && params.data._meta;
                        if (!m) return;

                        // De-emphasise previous + emphasise current
                        if (lastSelected) chart.dispatchAction({ type: 'downplay', ...lastSelected });
                        chart.dispatchAction({ type: 'highlight', seriesIndex: params.seriesIndex, dataIndex: params.dataIndex });
                        lastSelected = { seriesIndex: params.seriesIndex, dataIndex: params.dataIndex };

                        // Populate detail panel
                        document.getElementById('cs-detail-hint').classList.add('hidden');
                        document.getElementById('cs-detail-card').classList.remove('hidden');
                        const bar = document.getElementById('cs-detail-band-bar');
                        bar.style.borderColor = COLORS[m.band];

                        document.getElementById('cs-detail-name').textContent       = m.name;
                        document.getElementById('cs-detail-id').textContent         = 'ID: ' + m.id;
                        document.getElementById('cs-detail-revenue').textContent    = fmtFull(m.revenue);
                        document.getElementById('cs-detail-margin').textContent     = fmtFull(m.margin);
                        document.getElementById('cs-detail-margin').style.color     = COLORS[m.band];
                        document.getElementById('cs-detail-marginpct').textContent  = m.margin_pct.toFixed(1) + '%';
                        document.getElementById('cs-detail-orders').textContent     = m.orders.toLocaleString();
                        document.getElementById('cs-detail-share-rev').textContent  = m.share_revenue.toFixed(1) + '%';
                        document.getElementById('cs-detail-share-mar').textContent  = m.share_margin.toFixed(1) + '%';
                        const bandEl = document.getElementById('cs-detail-band');
                        bandEl.textContent = BAND_LABELS[m.band];
                        bandEl.style.color = COLORS[m.band];
                    });

                    // Keep the chart responsive
                    window.addEventListener('resize', () => chart.resize());
                })();
            </script>
            @endpush
        @else
            <div class="bg-warm-50 border border-warm-200 rounded-xl p-5 mb-4 text-sm">
                <strong class="text-[#1e3d44]">Profitability map unavailable.</strong>
                <p class="text-xs text-[#7c7466] mt-1">
                    No posted out_invoices with product lines were found in the selected period, or product cost data
                    (<code class="bg-[#f5f3ed] px-1 rounded">standard_price</code>) is missing on every line.
                    Set product costs in Odoo and run a sync to enable this chart.
                </p>
            </div>
        @endif
    @endif
@endsection
