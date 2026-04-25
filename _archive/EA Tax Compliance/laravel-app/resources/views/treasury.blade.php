@extends('layouts.app')
@section('title', 'Treasury')

@section('header')
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 mb-1">
                <span class="text-[10px] uppercase tracking-[0.35em] text-[#8a8578] font-medium">Liquidity Cockpit · {{ $receivables['as_of'] ?? '—' }}</span>
            </div>
            <h1 class="text-2xl font-bold text-[#1e3d44]">Treasury & Working Capital</h1>
        </div>
        <div class="section-card px-4 py-2.5 text-[11px] text-[#8a8578]">
            <span class="font-semibold text-[#5a6a70]">Data sources:</span>
            AR ({{ $receivables['as_of'] ?? '—' }}) · AP ({{ $payables['as_of'] ?? '—' }})
        </div>
    </div>
@endsection

@section('content')
    @if ($error)
        <div class="bg-coral-50 border border-coral-200 text-coral-800 rounded-xl p-4 text-sm">{{ $error }}</div>
        @return
    @endif

    @php
        $ccy = $summary['currency'] ?? 'USD';
    @endphp

    {{-- ── Section: Working Capital KPIs ──────────────────────────────── --}}
    <div class="mb-7">
        <div class="flex items-center gap-2 mb-3">
            <div class="w-1 h-4 rounded bg-brand-400"></div>
            <h2 class="text-xs font-semibold uppercase tracking-wider text-[#5a6a70]">Working Capital Status</h2>
        </div>
        <div class="grid grid-cols-2 xl:grid-cols-4 gap-3">
            <div class="kpi-card bg-white rounded-xl border border-[#e8e4de] shadow-sm overflow-hidden flex">
                <div class="accent-bar bg-brand-500 shrink-0"></div>
                <div class="p-4 flex-1">
                    <div class="text-[10px] uppercase tracking-wider text-[#8a8578] font-medium">Working Capital</div>
                    <div class="text-xl font-bold text-[#1e3d44] mt-1 tabular-nums">{{ ea_money_short((float)($summary['working_capital'] ?? 0), $ccy) }}</div>
                    <div class="text-[10px] text-[#a09d95] mt-0.5">AR – AP obligations</div>
                </div>
            </div>
            <div class="kpi-card bg-white rounded-xl border border-[#e8e4de] shadow-sm overflow-hidden flex">
                <div class="accent-bar bg-sage-500 shrink-0"></div>
                <div class="p-4 flex-1">
                    <div class="text-[10px] uppercase tracking-wider text-[#8a8578] font-medium">Net Cash Outcome</div>
                    <div class="text-xl font-bold text-[#1e3d44] mt-1 tabular-nums">{{ ea_money_short((float)($summary['net_cash'] ?? 0), $ccy) }}</div>
                    <div class="text-[10px] text-[#a09d95] mt-0.5">YTD net profit signal</div>
                </div>
            </div>
            <div class="kpi-card bg-white rounded-xl border border-[#e8e4de] shadow-sm overflow-hidden flex">
                <div class="accent-bar bg-warm-500 shrink-0"></div>
                <div class="p-4 flex-1">
                    <div class="text-[10px] uppercase tracking-wider text-[#8a8578] font-medium">Collection Health</div>
                    <div class="text-xl font-bold text-[#1e3d44] mt-1 tabular-nums">{{ number_format((float)($summary['collection_score'] ?? 0), 1) }}%</div>
                    <div class="text-[10px] text-[#a09d95] mt-0.5">100 − overdue rate</div>
                </div>
            </div>
            <div class="kpi-card bg-white rounded-xl border border-[#e8e4de] shadow-sm overflow-hidden flex">
                <div class="accent-bar bg-coral-500 shrink-0"></div>
                <div class="p-4 flex-1">
                    <div class="text-[10px] uppercase tracking-wider text-[#8a8578] font-medium">Runway</div>
                    <div class="text-xl font-bold text-[#1e3d44] mt-1 tabular-nums">
                        @if ($summary['runway_months'] ?? null)
                            {{ number_format((float) $summary['runway_months'], 1) }} mo
                        @else
                            —
                        @endif
                    </div>
                    <div class="text-[10px] text-[#a09d95] mt-0.5">AR coverage ÷ monthly burn</div>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Section: Cash Balance Evolution + Cashflow Breakdown ────────── --}}
    <div class="grid lg:grid-cols-2 gap-4 mb-7">
        {{-- Cash Balance Evolution Chart --}}
        <div class="section-card p-5">
            <div class="flex items-center gap-2 mb-4">
                <div class="w-1 h-4 rounded bg-sage-400"></div>
                <div>
                    <h2 class="text-[13px] font-semibold text-[#1e3d44]">Cash Balance Evolution</h2>
                    <p class="text-[11px] text-[#8a8578]">Cumulative net cash position YTD ({{ $ccy }})</p>
                </div>
            </div>
            <div class="h-52"><canvas id="cashEvolutionChart"></canvas></div>
        </div>

        {{-- Cashflow Breakdown Chart --}}
        <div class="section-card p-5">
            <div class="flex items-center gap-2 mb-4">
                <div class="w-1 h-4 rounded bg-warm-400"></div>
                <div>
                    <h2 class="text-[13px] font-semibold text-[#1e3d44]">Where Cash Goes</h2>
                    <p class="text-[11px] text-[#8a8578]">YTD inflows, outflows and locked positions ({{ $ccy }})</p>
                </div>
            </div>
            <div class="h-52"><canvas id="cashflowBreakdownChart"></canvas></div>
        </div>
    </div>

    {{-- ── Section: Conversion Cycle ──────────────────────────────────── --}}
    <div class="mb-7">
        <div class="flex items-center gap-2 mb-3">
            <div class="w-1 h-4 rounded bg-brand-400"></div>
            <h2 class="text-xs font-semibold uppercase tracking-wider text-[#5a6a70]">Cash Conversion Cycle</h2>
        </div>
        <div class="grid grid-cols-3 gap-3">
            <div class="section-card p-4 text-center">
                <div class="text-[10px] uppercase tracking-wider text-[#8a8578] font-medium">DSO</div>
                <div class="text-2xl font-bold text-brand-600 mt-1">{{ number_format((float)($summary['dso'] ?? 0), 0) }}</div>
                <div class="text-[10px] text-[#a09d95]">days to collect</div>
            </div>
            <div class="section-card p-4 text-center">
                <div class="text-[10px] uppercase tracking-wider text-[#8a8578] font-medium">DPO</div>
                <div class="text-2xl font-bold text-sage-600 mt-1">{{ number_format((float)($summary['dpo'] ?? 0), 0) }}</div>
                <div class="text-[10px] text-[#a09d95]">days to pay</div>
            </div>
            <div class="section-card p-4 text-center">
                <div class="text-[10px] uppercase tracking-wider text-[#8a8578] font-medium">Net Cycle</div>
                @php $cycle = (float)($summary['conversion_cycle'] ?? 0); @endphp
                <div class="text-2xl font-bold {{ $cycle > 0 ? 'text-coral-600' : 'text-sage-600' }} mt-1">{{ number_format($cycle, 0) }}</div>
                <div class="text-[10px] text-[#a09d95]">days gap (DSO − DPO)</div>
            </div>
        </div>
    </div>

    {{-- ── Section: Receivables & Payables Posture ────────────────────── --}}
    <div class="grid lg:grid-cols-2 gap-4 mb-7">
        <div class="section-card p-5">
            <div class="flex items-center gap-2 mb-4">
                <div class="w-1 h-4 rounded bg-brand-400"></div>
                <div>
                    <h2 class="text-[13px] font-semibold text-[#1e3d44]">Receivables Posture</h2>
                    <p class="text-[11px] text-[#8a8578]">DSO {{ number_format((float)($summary['dso'] ?? 0), 0) }}d · overdue {{ number_format((float)($receivables['overdue_rate'] ?? 0), 1) }}%</p>
                </div>
            </div>
            <dl class="grid grid-cols-2 gap-3 text-sm">
                <div class="rounded-lg bg-brand-50 p-3 border border-brand-100">
                    <dt class="text-[10px] uppercase tracking-wider text-[#8a8578] font-medium">Total AR</dt>
                    <dd class="text-lg font-bold text-[#1e3d44] mt-1">{{ ea_money_short((float)($receivables['total_ar'] ?? 0), $ccy) }}</dd>
                </div>
                <div class="rounded-lg bg-coral-50 p-3 border border-coral-100">
                    <dt class="text-[10px] uppercase tracking-wider text-[#8a8578] font-medium">Overdue</dt>
                    <dd class="text-lg font-bold text-[#1e3d44] mt-1">{{ ea_money_short((float)($receivables['overdue_ar'] ?? 0), $ccy) }}</dd>
                </div>
                <div class="rounded-lg bg-warm-50 p-3 border border-warm-100">
                    <dt class="text-[10px] uppercase tracking-wider text-[#8a8578] font-medium">Avg Age</dt>
                    <dd class="text-lg font-bold text-[#1e3d44] mt-1">{{ number_format((float)($receivables['avg_age'] ?? 0), 0) }} d</dd>
                </div>
                <div class="rounded-lg bg-sage-50 p-3 border border-sage-100">
                    <dt class="text-[10px] uppercase tracking-wider text-[#8a8578] font-medium">Top-5 Share</dt>
                    <dd class="text-lg font-bold text-[#1e3d44] mt-1">{{ number_format((float)($receivables['top5_pct'] ?? 0), 1) }}%</dd>
                </div>
            </dl>
        </div>

        <div class="section-card p-5">
            <div class="flex items-center gap-2 mb-4">
                <div class="w-1 h-4 rounded bg-coral-400"></div>
                <div>
                    <h2 class="text-[13px] font-semibold text-[#1e3d44]">Payables Commitments</h2>
                    <p class="text-[11px] text-[#8a8578]">DPO {{ number_format((float)($summary['dpo'] ?? 0), 0) }} days</p>
                </div>
            </div>
            <dl class="grid grid-cols-2 gap-3 text-sm">
                <div class="rounded-lg bg-coral-50 p-3 border border-coral-100">
                    <dt class="text-[10px] uppercase tracking-wider text-[#8a8578] font-medium">Total AP</dt>
                    <dd class="text-lg font-bold text-[#1e3d44] mt-1">{{ ea_money_short((float)($payables['kpis']['total_ap'] ?? 0), $ccy) }}</dd>
                </div>
                <div class="rounded-lg bg-brand-50 p-3 border border-brand-100">
                    <dt class="text-[10px] uppercase tracking-wider text-[#8a8578] font-medium">Overdue AP</dt>
                    <dd class="text-lg font-bold text-[#1e3d44] mt-1">{{ ea_money_short((float)($payables['kpis']['overdue_ap'] ?? 0), $ccy) }}</dd>
                </div>
                <div class="rounded-lg bg-sage-50 p-3 border border-sage-100">
                    <dt class="text-[10px] uppercase tracking-wider text-[#8a8578] font-medium">Avg Age</dt>
                    <dd class="text-lg font-bold text-[#1e3d44] mt-1">{{ number_format((float)($payables['kpis']['avg_age_days'] ?? 0), 0) }} d</dd>
                </div>
                <div class="rounded-lg bg-warm-50 p-3 border border-warm-100">
                    <dt class="text-[10px] uppercase tracking-wider text-[#8a8578] font-medium">Top-5 Share</dt>
                    <dd class="text-lg font-bold text-[#1e3d44] mt-1">{{ number_format((float)($payables['kpis']['top5_concentration_pct'] ?? 0), 1) }}%</dd>
                </div>
            </dl>
        </div>
    </div>

    {{-- ── Section: Top Debtors & Suppliers ───────────────────────────── --}}
    @if (!empty($receivables['top_partners']) || !empty($payables['top_creditors']))
        <div class="grid lg:grid-cols-2 gap-4">
            @if (!empty($receivables['top_partners']))
                <div class="section-card p-5">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-2">
                            <div class="w-1 h-4 rounded bg-brand-400"></div>
                            <h3 class="text-[13px] font-semibold text-[#1e3d44]">Top Debtors</h3>
                        </div>
                        <span class="text-[10px] text-[#a09d95] font-medium">Worst 6 by AR</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-[10px] text-[#8a8578] uppercase tracking-wider border-b border-[#e8e4de]">
                                    <th class="pb-2.5 font-semibold">Customer</th>
                                    <th class="pb-2.5 text-right font-semibold">AR</th>
                                    <th class="pb-2.5 text-right font-semibold">Overdue</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach (array_slice($receivables['top_partners'], 0, 6) as $partner)
                                    <tr class="table-row-hover border-b border-[#f0ede7]">
                                        <td class="py-2.5">
                                            <div class="text-[13px] font-medium text-[#2d3748]">{{ $partner['name'] ?? '—' }}</div>
                                            <div class="text-[10px] text-[#a09d95]">{{ $partner['rating_label'] ?? '' }}</div>
                                        </td>
                                        <td class="py-2.5 text-right tabular-nums text-[13px]">{{ ea_money((float)($partner['ar'] ?? 0), $ccy) }}</td>
                                        <td class="py-2.5 text-right tabular-nums text-[13px] {{ ($partner['overdue'] ?? 0) > 0 ? 'text-coral-600' : 'text-[#a09d95]' }}">
                                            {{ ($partner['overdue'] ?? 0) > 0 ? ea_money((float) $partner['overdue'], $ccy) : '—' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            @if (!empty($payables['top_creditors']))
                <div class="section-card p-5">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-2">
                            <div class="w-1 h-4 rounded bg-coral-400"></div>
                            <h3 class="text-[13px] font-semibold text-[#1e3d44]">Top Suppliers Payable</h3>
                        </div>
                        <span class="text-[10px] text-[#a09d95] font-medium">Greatest leverage</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-[10px] text-[#8a8578] uppercase tracking-wider border-b border-[#e8e4de]">
                                    <th class="pb-2.5 font-semibold">Supplier</th>
                                    <th class="pb-2.5 text-right font-semibold">AP</th>
                                    <th class="pb-2.5 text-right font-semibold">Overdue</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach (array_slice($payables['top_creditors'], 0, 6) as $cred)
                                    <tr class="table-row-hover border-b border-[#f0ede7]">
                                        <td class="py-2.5">
                                            <div class="text-[13px] font-medium text-[#2d3748]">{{ $cred['name'] ?? '—' }}</div>
                                            <div class="text-[10px] text-[#a09d95]">Invoices: {{ $cred['invoices'] ?? 0 }}</div>
                                        </td>
                                        <td class="py-2.5 text-right tabular-nums text-[13px]">{{ ea_money((float)($cred['residual'] ?? 0), $ccy) }}</td>
                                        <td class="py-2.5 text-right tabular-nums text-[13px] {{ ($cred['overdue'] ?? 0) > 0 ? 'text-coral-600' : 'text-[#a09d95]' }}">
                                            {{ ($cred['overdue'] ?? 0) > 0 ? ea_money((float) $cred['overdue'], $ccy) : '—' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>
    @endif

@endsection

@push('scripts')
<script>
(function () {
    // ── Cash Balance Evolution (Area Chart) ──────────────────────────
    const evoData = @json($cashEvolution ?? []);
    const evoCtx = document.getElementById('cashEvolutionChart');
    if (evoCtx && evoData.length) {
        new Chart(evoCtx, {
            type: 'line',
            data: {
                labels: evoData.map(d => d.label),
                datasets: [{
                    label: 'Cash Balance',
                    data: evoData.map(d => d.balance),
                    borderColor: '#3b8f9c',
                    backgroundColor: 'rgba(59,143,156,.1)',
                    fill: true,
                    tension: 0.35,
                    pointRadius: 3,
                    pointBackgroundColor: '#3b8f9c',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    borderWidth: 2,
                }],
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                interaction: { intersect: false, mode: 'index' },
                plugins: {
                    legend: { display: false },
                    tooltip: { backgroundColor: '#1c2637', titleFont: { size: 11 }, bodyFont: { size: 11 }, padding: 10, cornerRadius: 8 }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { font: { size: 10 }, color: '#8a8578' } },
                    y: { grid: { color: '#f0ede7' }, ticks: { font: { size: 10 }, color: '#8a8578',
                          callback: v => v >= 1e6 ? (v/1e6).toFixed(1)+'M' : v >= 1e3 ? (v/1e3).toFixed(0)+'K' : v } },
                },
            },
        });
    }

    // ── Cashflow Breakdown (Horizontal Bar Chart) ────────────────────
    const cfData = @json($cashflowBreakdown ?? []);
    const cfCtx = document.getElementById('cashflowBreakdownChart');
    if (cfCtx && cfData.length) {
        const colorMap = {
            inflow:  '#5a815e',
            outflow: '#d06a50',
            locked:  '#dfa05e',
            owed:    '#4da8b4',
        };
        new Chart(cfCtx, {
            type: 'bar',
            data: {
                labels: cfData.map(d => d.label),
                datasets: [{
                    data: cfData.map(d => d.amount),
                    backgroundColor: cfData.map(d => colorMap[d.type] || '#94a3b8'),
                    borderRadius: 4,
                    barThickness: 18,
                }],
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#1c2637', titleFont: { size: 11 }, bodyFont: { size: 11 }, padding: 10, cornerRadius: 8,
                        callbacks: { label: ctx => {
                            const v = ctx.raw;
                            return v >= 1e6 ? (v/1e6).toFixed(1)+'M' : v >= 1e3 ? (v/1e3).toFixed(0)+'K' : v.toFixed(0);
                        }}
                    }
                },
                scales: {
                    x: { grid: { color: '#f0ede7' }, ticks: { font: { size: 10 }, color: '#8a8578',
                          callback: v => v >= 1e6 ? (v/1e6).toFixed(1)+'M' : v >= 1e3 ? (v/1e3).toFixed(0)+'K' : v } },
                    y: { grid: { display: false }, ticks: { font: { size: 10, weight: 500 }, color: '#5a6a70' } },
                },
            },
        });
    }
})();
</script>
@endpush
