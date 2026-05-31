@extends('admin.layout')
@section('title', 'Revenue Report · ' . $tenant->name)

@section('content')
<div class="mb-4 flex items-center justify-between">
    <a href="/admin/business-insights/{{ $tenant->id }}/inventory" class="text-[12px] text-slate-500 hover:text-slate-900">
        ← Data Inventory
    </a>
    <form method="GET" class="flex items-center gap-2 text-[11px]">
        <label class="text-slate-500">Year:</label>
        <select name="year" onchange="this.form.submit()"
                class="border border-stone-300 rounded px-2 py-1 mono text-[11px]">
            @for ($y = (int) date('Y'); $y >= 2018; $y--)
                <option value="{{ $y }}" @selected($year === $y)>{{ $y }}</option>
            @endfor
        </select>
    </form>
</div>

<div class="bg-white border border-stone-200 rounded-xl p-5 mb-5">
    <div class="text-[10px] uppercase tracking-widest text-amber-700 font-semibold mb-1">Management Report · Revenue</div>
    <h1 class="text-xl font-bold text-slate-900">{{ $tenant->brand ?: $tenant->name }} · FY {{ $year }}</h1>
    @if ($report)
        <p class="text-[11px] text-slate-500 mt-1 mono">Generated {{ $report['generated_at'] }} · {{ $report['currency'] }}</p>
    @endif
</div>

@if ($error)
    <div class="bg-rose-50 border border-rose-200 text-rose-800 rounded-lg p-4 text-sm mb-5">
        <strong>Report generation failed:</strong> {{ $error }}
    </div>
@endif

@if ($report)
    @php
        $h = $report['headline'] ?? [];
        $fmt  = fn ($v) => is_numeric($v) ? number_format((float) $v, 0) : ($v ?? '—');
        $fmtPct = fn ($v) => is_numeric($v) ? (($v >= 0 ? '+' : '') . number_format($v, 1) . '%') : '—';
    @endphp

    {{-- Headline KPIs --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
        <div class="bg-white border border-stone-200 rounded-lg p-3">
            <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">YTD Revenue</div>
            <div class="text-2xl font-bold mono text-emerald-700 mt-1">{{ $fmt($h['ytd_revenue'] ?? 0) }}</div>
            <div class="text-[10px] text-slate-500 mt-1">XAF · {{ $h['months_elapsed'] ?? 0 }} month(s)</div>
        </div>
        <div class="bg-white border border-stone-200 rounded-lg p-3">
            <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">YoY Growth</div>
            <div class="text-2xl font-bold mono mt-1 {{ ($h['yoy_pct'] ?? 0) >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">
                {{ $fmtPct($h['yoy_pct'] ?? 0) }}
            </div>
            <div class="text-[10px] text-slate-500 mt-1">vs PY same window {{ $fmt($h['py_revenue_same'] ?? 0) }}</div>
        </div>
        <div class="bg-white border border-stone-200 rounded-lg p-3">
            <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Annual Run-Rate</div>
            <div class="text-2xl font-bold mono text-slate-900 mt-1">{{ $fmt($h['run_rate_annual'] ?? 0) }}</div>
            <div class="text-[10px] text-slate-500 mt-1">extrapolated from YTD</div>
        </div>
        <div class="bg-white border border-stone-200 rounded-lg p-3">
            <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Avg Invoice</div>
            <div class="text-2xl font-bold mono text-slate-900 mt-1">{{ $fmt($h['avg_invoice'] ?? 0) }}</div>
            <div class="text-[10px] text-slate-500 mt-1">{{ number_format($h['invoice_count'] ?? 0) }} invoice(s)</div>
        </div>
    </div>

    {{-- Monthly trend chart --}}
    <div class="bg-white border border-stone-200 rounded-xl p-5 mb-5">
        <h2 class="text-sm font-bold text-slate-900 mb-3">Monthly revenue trend · FY {{ $year }}</h2>
        <div id="rev-monthly-chart" style="height: 280px;"></div>
    </div>

    {{-- YoY comparison table --}}
    @if (!empty($report['yoy']))
        <div class="bg-white border border-stone-200 rounded-xl overflow-hidden mb-5">
            <div class="px-4 py-3 border-b border-stone-100">
                <h2 class="text-sm font-bold text-slate-900">YoY comparison · {{ $year }} vs {{ $year - 1 }}</h2>
            </div>
            <table class="w-full text-[12.5px]">
                <thead class="bg-stone-50 text-[10px] uppercase tracking-widest text-slate-500">
                    <tr>
                        <th class="text-left py-2 px-3 font-semibold">Month</th>
                        <th class="text-right py-2 px-3 font-semibold">CY {{ $year }}</th>
                        <th class="text-right py-2 px-3 font-semibold">PY {{ $year - 1 }}</th>
                        <th class="text-right py-2 px-3 font-semibold">Δ Amount</th>
                        <th class="text-right py-2 px-3 font-semibold">Δ %</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @foreach ($report['yoy'] as $row)
                        <tr>
                            <td class="py-1.5 px-3 mono text-slate-700">{{ $row['label'] }}</td>
                            <td class="py-1.5 px-3 text-right mono">{{ $fmt($row['cy']) }}</td>
                            <td class="py-1.5 px-3 text-right mono text-slate-500">{{ $fmt($row['py']) }}</td>
                            <td class="py-1.5 px-3 text-right mono {{ $row['delta'] >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">
                                {{ $row['delta'] >= 0 ? '+' : '' }}{{ $fmt($row['delta']) }}
                            </td>
                            <td class="py-1.5 px-3 text-right mono {{ $row['delta_pct'] >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">
                                {{ $fmtPct($row['delta_pct']) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Customer concentration --}}
    @php $c = $report['customers'] ?? []; @endphp
    @if (!empty($c['top']))
        <div class="bg-white border border-stone-200 rounded-xl overflow-hidden mb-5">
            <div class="px-4 py-3 border-b border-stone-100 flex items-center justify-between">
                <h2 class="text-sm font-bold text-slate-900">Top 10 customers by revenue · YTD {{ $year }}</h2>
                <span class="text-[11px] text-slate-500">
                    Top 10 = <strong class="text-slate-900">{{ $fmtPct($c['top10_pct']) }}</strong> of total · {{ $c['unique_count'] }} unique customers
                </span>
            </div>
            <table class="w-full text-[12.5px]">
                <thead class="bg-stone-50 text-[10px] uppercase tracking-widest text-slate-500">
                    <tr>
                        <th class="text-left py-2 px-3 font-semibold">#</th>
                        <th class="text-left py-2 px-3 font-semibold">Customer</th>
                        <th class="text-right py-2 px-3 font-semibold">Revenue</th>
                        <th class="text-right py-2 px-3 font-semibold">Invoices</th>
                        <th class="text-right py-2 px-3 font-semibold">Share</th>
                        <th class="text-right py-2 px-3 font-semibold">Cumul.</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @foreach ($c['top'] as $i => $row)
                        <tr>
                            <td class="py-1.5 px-3 mono text-slate-500">{{ $i + 1 }}</td>
                            <td class="py-1.5 px-3 text-slate-900">{{ $row['name'] }}</td>
                            <td class="py-1.5 px-3 text-right mono">{{ $fmt($row['revenue']) }}</td>
                            <td class="py-1.5 px-3 text-right mono text-slate-500">{{ $row['invoices'] }}</td>
                            <td class="py-1.5 px-3 text-right mono">{{ $row['share_pct'] }}%</td>
                            <td class="py-1.5 px-3 text-right mono text-slate-500">{{ $row['cumul_pct'] }}%</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Quality flags --}}
    @php $q = $report['quality'] ?? []; @endphp
    @if (!empty($q))
        <div class="bg-white border border-stone-200 rounded-xl p-5 mb-5">
            <h2 class="text-sm font-bold text-slate-900 mb-3">Data quality flags · YTD {{ $year }}</h2>
            <div class="grid grid-cols-3 gap-3 text-[12px]">
                <div class="border border-stone-200 rounded-lg p-3">
                    <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Invoices without PO</div>
                    <div class="text-xl mono font-bold {{ ($q['invoices_without_po'] ?? 0) > 0 ? 'text-amber-700' : 'text-emerald-700' }} mt-1">
                        {{ number_format($q['invoices_without_po'] ?? 0) }}
                    </div>
                </div>
                <div class="border border-stone-200 rounded-lg p-3">
                    <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Draft invoices</div>
                    <div class="text-xl mono font-bold {{ ($q['draft_invoices'] ?? 0) > 0 ? 'text-rose-700' : 'text-emerald-700' }} mt-1">
                        {{ number_format($q['draft_invoices'] ?? 0) }}
                    </div>
                </div>
                <div class="border border-stone-200 rounded-lg p-3">
                    <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Refunds</div>
                    <div class="text-xl mono font-bold text-slate-700 mt-1">
                        {{ number_format($q['refund_count'] ?? 0) }}
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Errors --}}
    @if (!empty($report['errors']))
        <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 text-[11px] text-amber-800">
            <strong>{{ count($report['errors']) }} section warning(s):</strong>
            <ul class="list-disc pl-4 mt-1">
                @foreach ($report['errors'] as $e)<li class="mono">{{ $e }}</li>@endforeach
            </ul>
        </div>
    @endif

    {{-- ECharts --}}
    <script src="https://cdn.jsdelivr.net/npm/echarts@5.4.3/dist/echarts.min.js"></script>
    <script>
    (function () {
        var data = @json($report['monthly'] ?? []);
        if (!data.length) return;
        function init() {
            var el = document.getElementById('rev-monthly-chart');
            if (!el || typeof echarts === 'undefined') { setTimeout(init, 100); return; }
            var chart = echarts.init(el);
            chart.setOption({
                grid: { top: 18, left: 70, right: 20, bottom: 32 },
                tooltip: { trigger: 'axis', valueFormatter: v => new Intl.NumberFormat('en-US').format(v) + ' XAF' },
                xAxis: {
                    type: 'category', data: data.map(d => d.label),
                    axisLine: { lineStyle: { color: '#94a3b8' } },
                    axisLabel: { color: '#64748b', fontSize: 11 },
                },
                yAxis: {
                    type: 'value', axisLine: { show: false },
                    axisLabel: { color: '#64748b', fontSize: 10,
                        formatter: v => Math.abs(v) >= 1e6 ? (v/1e6).toFixed(1) + ' M' : Math.abs(v) >= 1e3 ? (v/1e3).toFixed(0) + ' K' : v },
                    splitLine: { lineStyle: { color: '#e2e8f0', type: 'dashed' } },
                },
                series: [{
                    name: 'Revenue', type: 'bar',
                    data: data.map(d => d.value),
                    itemStyle: { color: '#059669', borderRadius: [3,3,0,0] }
                }],
            });
            window.addEventListener('resize', () => chart.resize());
        }
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
    })();
    </script>
@endif

@endsection
