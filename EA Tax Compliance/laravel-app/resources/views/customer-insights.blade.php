@extends('layouts.app')
@section('title', 'Customer Insights')

@section('header')
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <div class="flex items-center gap-2 mb-1">
                <span class="inline-block w-2 h-2 rounded-full bg-brand-400"></span>
                <span class="text-[10px] uppercase tracking-[0.35em] text-[#8a8578] font-medium">{{ $periodLabel }} · {{ ea_last_sync_label() }}</span>
            </div>
            <h1 class="text-2xl font-bold text-[#1e3d44]">Customer Insights</h1>
        </div>
        <form method="POST" action="/sync">@csrf
            <button class="inline-flex items-center gap-1.5 text-[11px] font-semibold text-white bg-brand-600 hover:bg-brand-700 rounded-lg px-3 py-1.5 shadow-sm transition">
                <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M23 4v6h-6"/><path d="M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                Refresh
            </button>
        </form>
    </div>
@endsection

@section('content')
    <x-period-filter :period="$period"/>

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

        {{-- Profitability Tables --}}
        <div class="grid lg:grid-cols-2 gap-4">
            <div class="section-card p-5">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h2 class="text-[13px] font-semibold text-[#1e3d44]">Top 5 Most Profitable Customers</h2>
                        <p class="text-[11px] text-[#8a8578]">Share of current-period revenue</p>
                    </div>
                    <span class="text-[10px] text-[#a09d95] font-medium">Currency {{ $currency }}</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-[10px] text-[#8a8578] uppercase tracking-wider border-b border-[#e8e4de]">
                                <th class="pb-2.5 font-semibold">Customer</th>
                                <th class="pb-2.5 text-right font-semibold">Revenue</th>
                                <th class="pb-2.5 text-right font-semibold">Share</th>
                                <th class="pb-2.5 text-right font-semibold">Orders</th>
                                <th class="pb-2.5 text-right font-semibold">Avg Order</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($topCustomers as $row)
                                <tr class="table-row-hover border-b border-[#f0ede7]">
                                    <td class="py-2.5">
                                        <div class="text-[13px] font-medium text-[#2d3748]">{{ $row['name'] }}</div>
                                        <div class="text-[10px] text-[#a09d95]">ID: {{ $row['id'] }}</div>
                                    </td>
                                    <td class="py-2.5 text-right tabular-nums text-[13px] font-semibold text-sage-700">{{ $fmtMoney($row['revenue']) }}</td>
                                    <td class="py-2.5 text-right tabular-nums text-[13px]">{{ $fmtPct($row['share']) }}</td>
                                    <td class="py-2.5 text-right tabular-nums text-[13px]">{{ $fmtCount($row['orders']) }}</td>
                                    <td class="py-2.5 text-right tabular-nums text-[13px]">{{ $fmtMoney($row['avg_order']) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="py-4 text-center text-xs text-[#8a8578]">No customer data.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="section-card p-5">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h2 class="text-[13px] font-semibold text-[#1e3d44]">Top 5 Least Profitable Customers</h2>
                        <p class="text-[11px] text-[#8a8578]">Focus for pricing / cost-to-serve reviews</p>
                    </div>
                    <span class="text-[10px] text-[#a09d95] font-medium">Currency {{ $currency }}</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-[10px] text-[#8a8578] uppercase tracking-wider border-b border-[#e8a8578]">
                                <th class="pb-2.5 font-semibold">Customer</th>
                                <th class="pb-2.5 text-right font-semibold">Revenue</th>
                                <th class="pb-2.5 text-right font-semibold">Share</th>
                                <th class="pb-2.5 text-right font-semibold">Orders</th>
                                <th class="pb-2.5 text-right font-semibold">Avg Order</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($leastCustomers as $row)
                                <tr class="table-row-hover border-b border-[#f0ede7]">
                                    <td class="py-2.5">
                                        <div class="text-[13px] font-medium text-[#2d3748]">{{ $row['name'] }}</div>
                                        <div class="text-[10px] text-[#a09d95]">ID: {{ $row['id'] }}</div>
                                    </td>
                                    <td class="py-2.5 text-right tabular-nums text-[13px] text-coral-700">{{ $fmtMoney($row['revenue']) }}</td>
                                    <td class="py-2.5 text-right tabular-nums text-[13px]">{{ $fmtPct($row['share']) }}</td>
                                    <td class="py-2.5 text-right tabular-nums text-[13px]">{{ $fmtCount($row['orders']) }}</td>
                                    <td class="py-2.5 text-right tabular-nums text-[13px]">{{ $fmtMoney($row['avg_order']) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="py-4 text-center text-xs text-[#8a8578]">No customer data.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif
@endsection
