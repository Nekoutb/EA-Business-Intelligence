@extends('layouts.app')
@section('title', '16-Week Cash Flow Forecast')

@section('page-title', '16-Week Rolling Cash Flow Forecast')
@section('content')

@if ($error)
    <div class="bg-rose-50 border border-rose-200 text-rose-700 rounded-xl p-4 text-sm mb-4">{{ $error }}</div>
@endif

@if ($data)
    @php
        $ccy   = $data['currency'] ?? 'XAF';
        $kpis  = $data['kpis'] ?? [];
        $weeks = $data['weeks'] ?? [];
        $lines = $data['lines'] ?? [];
        $errs  = $data['errors'] ?? [];
        $gov   = $data['governance'] ?? [];

        // Helper to format money short for KPI cards (M / K)
        $short = function ($n) {
            $n = (float) $n;
            $sign = $n < 0 ? '−' : '';
            $abs = abs($n);
            if ($abs >= 1_000_000_000) return $sign . number_format($abs / 1_000_000_000, 2) . ' B';
            if ($abs >= 1_000_000)     return $sign . number_format($abs / 1_000_000, 2) . ' M';
            if ($abs >= 1_000)         return $sign . number_format($abs / 1_000, 1) . ' K';
            return $sign . number_format($abs, 0);
        };
        $fmt = fn ($n) => number_format((float) $n, 0);

        // Group lines by band for table sections
        $byBand = [];
        foreach ($lines as $l) {
            $byBand[$l['band']][] = $l;
        }

        // Chart data
        $weekLabels    = array_column($weeks, 'label');
        $closingSeries = array_column($weeks, 'closing');
        $receiptSeries = array_column($weeks, 'receipts');
        $disburSeries  = array_map(fn ($v) => -1 * $v, array_column($weeks, 'disbursements'));

        $bandLabels = [
            1 => 'Opening cash position',
            2 => 'Operating receipts',
            3 => 'Operating disbursements',
            4 => 'Financing & capital flows',
        ];
        $engineColors = [
            'A' => 'bg-emerald-100 text-emerald-700 border-emerald-200',
            'B' => 'bg-sky-100 text-sky-700 border-sky-200',
            'C' => 'bg-violet-100 text-violet-700 border-violet-200',
            'D' => 'bg-stone-200 text-stone-700 border-stone-300',
            '=' => 'bg-slate-800 text-white border-slate-900',
        ];
        $confidenceColors = [
            'High'   => 'text-emerald-700',
            'Medium' => 'text-amber-700',
            'Low'    => 'text-rose-700',
            'Policy' => 'text-violet-700',
        ];
    @endphp

    {{-- ─────── Header strip ─────── --}}
    <div class="bg-white rounded-xl border border-stone-200 shadow-sm overflow-hidden mb-5">
        {{-- Title row --}}
        <div class="px-5 py-4 border-b border-stone-100 flex items-start justify-between gap-4">
            <div class="flex items-start gap-3">
                <div class="bg-slate-900 text-white text-[10px] uppercase tracking-widest font-bold rounded px-2 py-1 shrink-0 mt-1">01</div>
                <div>
                    <h2 class="text-base font-bold text-slate-900">16-Week Rolling Cash Flow Forecast</h2>
                    <p class="text-[11px] text-slate-500 mt-0.5">Direct method · weekly receipts &amp; disbursements · minimum buffer surveillance</p>
                </div>
            </div>
            <div class="text-right">
                <div class="text-[10px] uppercase tracking-widest text-amber-700 font-semibold">FLAGSHIP · TRP</div>
                <div class="text-[11px] text-slate-500 mono mt-0.5">Cut-off · {{ $data['cut_off'] }}</div>
            </div>
        </div>

        {{-- KPI cards row --}}
        <div class="grid grid-cols-1 md:grid-cols-4 gap-3 p-4">
            <div class="border border-stone-200 rounded-lg p-3">
                <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Opening cash · W1</div>
                <div class="text-2xl font-bold mono text-emerald-700 mt-1">{{ $short($kpis['opening_w1'] ?? 0) }}</div>
                <div class="text-[10px] text-slate-500 mt-1">vs minimum buffer {{ $short($kpis['min_buffer'] ?? 0) }}</div>
            </div>
            <div class="border border-stone-200 rounded-lg p-3">
                <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Projected closing · W{{ \App\Services\CashFlow16WService::HORIZON_WEEKS }}</div>
                <div class="text-2xl font-bold mono text-emerald-700 mt-1">{{ $short($kpis['projected_w16'] ?? 0) }}</div>
                <div class="text-[10px] text-slate-500 mt-1">{{ ($kpis['horizon_delta'] ?? 0) >= 0 ? '+ ' : '− ' }}{{ $short(abs($kpis['horizon_delta'] ?? 0)) }} over horizon</div>
            </div>
            <div class="border border-stone-200 rounded-lg p-3">
                <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Lowest weekly balance</div>
                <div class="text-2xl font-bold mono text-amber-700 mt-1">{{ $short($kpis['lowest_balance'] ?? 0) }}</div>
                <div class="text-[10px] text-slate-500 mt-1">W{{ $kpis['lowest_week_no'] ?? 0 }} · {{ $short($kpis['lowest_under_min'] ?? 0) }} {{ ($kpis['lowest_under_min'] ?? 0) > 0 ? 'under buffer' : 'above buffer' }}</div>
            </div>
            <div class="border border-stone-200 rounded-lg p-3">
                <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Buffer breach weeks</div>
                <div class="text-2xl font-bold mono {{ ($kpis['buffer_breaches'] ?? 0) > 0 ? 'text-rose-700' : 'text-emerald-700' }} mt-1">
                    {{ $kpis['buffer_breaches'] ?? 0 }} <span class="text-base text-slate-500 font-normal">of {{ count($weeks) }}</span>
                </div>
                <div class="text-[10px] text-slate-500 mt-1">
                    @if (($kpis['buffer_breaches'] ?? 0) > 0)
                        Action: shift W{{ $kpis['lowest_week_no'] ?? '?' }} supplier run
                    @else
                        No buffer breach forecast
                    @endif
                </div>
            </div>
        </div>

        {{-- Chart --}}
        <div class="px-5 pb-4 pt-2">
            <div id="cf16w-chart" class="w-full" style="height: 320px;"></div>
            <div class="flex items-center gap-4 text-[10px] text-slate-600 mt-2 flex-wrap">
                <span class="inline-flex items-center gap-1.5"><span class="w-3 h-0.5 bg-emerald-700"></span> Closing cash (projected)</span>
                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 bg-emerald-600"></span> Weekly receipts ▲</span>
                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 bg-rose-600"></span> Weekly disbursements ▼</span>
                <span class="inline-flex items-center gap-1.5"><span class="w-3 h-0.5 border-t border-dashed border-amber-600"></span> Minimum buffer</span>
            </div>
            <p class="text-[10px] text-slate-500 italic mt-3">Closing balance<sub>W</sub> = Opening<sub>W</sub> + Σ confirmed receipts (AR &amp; orders) − Σ committed disbursements (AP, payroll, tax, debt service). Forecast bridges direct-method weekly flows to month-end indirect cash position.</p>
        </div>
    </div>

    {{-- ─────── Errors (Phase 1 data-source gaps) ─────── --}}
    @if (!empty($errs))
        <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-lg px-3 py-2 text-[11px] mb-4">
            <strong>Data-source warnings ({{ count($errs) }}):</strong>
            <ul class="list-disc pl-4 mt-1">
                @foreach ($errs as $e)<li class="mono">{{ $e }}</li>@endforeach
            </ul>
            <div class="mt-1 text-[10px] text-amber-700">Lines without data fall back to zero — the engine doesn't crash. Populate the underlying Odoo records and refresh.</div>
        </div>
    @endif

    {{-- ─────── The big matrix table ─────── --}}
    <div class="bg-white rounded-xl border border-stone-200 shadow-sm overflow-hidden mb-5">
        <div class="px-4 py-3 border-b border-stone-100 flex items-center justify-between">
            <div>
                <h3 class="text-sm font-bold text-slate-900">Forecast matrix · {{ count($weeks) }} weeks × {{ count($lines) }} lines</h3>
                <p class="text-[11px] text-slate-500">Cells show forecast cash flow in XAF. Hover for line context. Engine tags: A extraction · B behavioural · C scheduled · D manual.</p>
            </div>
            <a href="?refresh=1" class="text-[11px] font-medium text-rose-700 hover:text-rose-900 border border-rose-300 rounded-lg px-3 py-1.5">⟳ Refresh</a>
        </div>
        <div class="overflow-x-auto">
            <table class="text-[11px] w-full">
                <thead class="bg-stone-50 text-[10px] uppercase tracking-widest text-slate-500">
                    <tr>
                        <th class="sticky left-0 z-10 bg-stone-50 px-3 py-2 text-left font-semibold w-12">#</th>
                        <th class="sticky left-12 z-10 bg-stone-50 px-2 py-2 text-left font-semibold min-w-[260px]">Line</th>
                        <th class="px-2 py-2 text-center font-semibold w-10">Eng</th>
                        <th class="px-2 py-2 text-center font-semibold w-12">Conf</th>
                        <th class="px-2 py-2 text-right font-semibold whitespace-nowrap">Total</th>
                        @foreach ($weeks as $w)
                            <th class="px-2 py-2 text-right font-semibold w-20 whitespace-nowrap">{{ $w['label'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @foreach ($byBand as $bandNo => $bandLines)
                        <tr class="bg-slate-900 text-white">
                            <td colspan="{{ 5 + count($weeks) }}" class="px-3 py-1.5 text-[10px] uppercase tracking-widest font-bold">
                                Band {{ $bandNo }} · {{ $bandLabels[$bandNo] ?? '—' }}
                            </td>
                        </tr>
                        @foreach ($bandLines as $line)
                            @php
                                $isTotal = $line['engine'] === '=';
                                $rowCls = $isTotal ? 'bg-slate-50 font-semibold' : 'hover:bg-stone-50/60';
                                $signCls = $line['sign'] >= 0 ? 'text-emerald-700' : 'text-rose-700';
                            @endphp
                            <tr class="{{ $rowCls }}">
                                <td class="sticky left-0 z-10 {{ $isTotal ? 'bg-slate-50' : 'bg-white' }} px-3 py-1.5 mono text-slate-500">{{ $line['line'] }}</td>
                                <td class="sticky left-12 z-10 {{ $isTotal ? 'bg-slate-50' : 'bg-white' }} px-2 py-1.5">
                                    <div class="text-slate-900">{{ $line['label'] }}</div>
                                    <div class="text-[9.5px] text-slate-500 mono leading-tight">{{ $line['source'] }}</div>
                                </td>
                                <td class="px-2 py-1.5 text-center">
                                    <span class="inline-block text-[9px] font-bold mono border rounded px-1 py-0.5 {{ $engineColors[$line['engine']] ?? '' }}">{{ $line['engine'] }}</span>
                                </td>
                                <td class="px-2 py-1.5 text-center text-[9.5px] {{ $confidenceColors[$line['confidence']] ?? 'text-slate-400' }}">{{ $line['confidence'] ?? '—' }}</td>
                                <td class="px-2 py-1.5 text-right mono {{ $line['total'] != 0 ? $signCls : 'text-slate-400' }}">
                                    {{ $line['total'] != 0 ? $fmt($line['total']) : '—' }}
                                </td>
                                @foreach ($weeks as $w => $weekRow)
                                    @php
                                        $val = isset($line['by_week']) && is_array($line['by_week']) ? ($line['by_week'][$w] ?? 0) : null;
                                    @endphp
                                    <td class="px-2 py-1.5 text-right mono {{ $val ? $signCls : 'text-slate-300' }}">
                                        @if ($val === null && !$isTotal)
                                            <span class="text-slate-300">·</span>
                                        @elseif ($val == 0)
                                            <span class="text-slate-300">·</span>
                                        @else
                                            {{ $fmt($val) }}
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    @endforeach

                    {{-- Band 5: Closing trajectory (computed) --}}
                    <tr class="bg-slate-900 text-white">
                        <td colspan="{{ 5 + count($weeks) }}" class="px-3 py-1.5 text-[10px] uppercase tracking-widest font-bold">Band 5 · Closing position &amp; metrics</td>
                    </tr>
                    <tr class="bg-emerald-50/40 font-semibold">
                        <td class="sticky left-0 z-10 bg-emerald-50/40 px-3 py-1.5 mono text-slate-500">5.1</td>
                        <td class="sticky left-12 z-10 bg-emerald-50/40 px-2 py-1.5">
                            <div class="text-slate-900">Net cash movement</div>
                            <div class="text-[9.5px] text-slate-500 mono leading-tight">Σ B2 − Σ B3 + Σ B4 (signed)</div>
                        </td>
                        <td colspan="3"></td>
                        @foreach ($weeks as $w)
                            <td class="px-2 py-1.5 text-right mono {{ ($w['net'] ?? 0) >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">
                                {{ ($w['net'] ?? 0) >= 0 ? '+' : '' }}{{ $fmt($w['net']) }}
                            </td>
                        @endforeach
                    </tr>
                    <tr class="bg-slate-50 font-bold">
                        <td class="sticky left-0 z-10 bg-slate-50 px-3 py-1.5 mono text-slate-500">5.2</td>
                        <td class="sticky left-12 z-10 bg-slate-50 px-2 py-1.5">
                            <div class="text-slate-900">Closing cash position</div>
                            <div class="text-[9.5px] text-slate-500 mono leading-tight">Prior closing + net movement</div>
                        </td>
                        <td colspan="3"></td>
                        @foreach ($weeks as $w)
                            <td class="px-2 py-1.5 text-right mono {{ ($w['closing'] ?? 0) >= ($kpis['min_buffer'] ?? 0) ? 'text-emerald-700' : 'text-rose-700' }}">
                                {{ $fmt($w['closing']) }}
                            </td>
                        @endforeach
                    </tr>
                    <tr class="bg-slate-50">
                        <td class="sticky left-0 z-10 bg-slate-50 px-3 py-1.5 mono text-slate-500">5.3</td>
                        <td class="sticky left-12 z-10 bg-slate-50 px-2 py-1.5">
                            <div class="text-slate-900">Buffer headroom</div>
                            <div class="text-[9.5px] text-slate-500 mono leading-tight">Closing − minimum policy</div>
                        </td>
                        <td colspan="3"></td>
                        @foreach ($weeks as $w)
                            @php $head = ($w['closing'] ?? 0) - ($kpis['min_buffer'] ?? 0); @endphp
                            <td class="px-2 py-1.5 text-right mono {{ $head >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">
                                {{ $head >= 0 ? '+' : '' }}{{ $fmt($head) }}
                            </td>
                        @endforeach
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    {{-- ─────── Band 6: Governance metrics ─────── --}}
    <div class="grid grid-cols-1 md:grid-cols-4 gap-3 mb-6">
        <div class="bg-white rounded-xl border border-stone-200 p-4">
            <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Forecast accuracy · 4w</div>
            <div class="text-xl font-bold mono text-slate-900 mt-1">{{ $gov['forecast_accuracy_4w'] ?? '—' }}</div>
            <div class="text-[10px] text-slate-500 mt-1">Phase 2 — needs forecast history</div>
        </div>
        <div class="bg-white rounded-xl border border-stone-200 p-4">
            <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Forecast bias · 12w</div>
            <div class="text-xl font-bold mono text-slate-900 mt-1">{{ $gov['forecast_bias_12w'] ?? '—' }}</div>
            <div class="text-[10px] text-slate-500 mt-1">+ = systematically optimistic</div>
        </div>
        <div class="bg-white rounded-xl border border-stone-200 p-4">
            <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Data freshness</div>
            <div class="text-xl font-bold mono text-emerald-700 mt-1">{{ $gov['data_freshness'] ?? '—' }}</div>
            <div class="text-[10px] text-slate-500 mt-1">Most stale source feeding forecast</div>
        </div>
        <div class="bg-white rounded-xl border border-stone-200 p-4">
            <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Manual overrides</div>
            <div class="text-xl font-bold mono text-slate-900 mt-1">{{ $gov['manual_override_count'] ?? 0 }}</div>
            <div class="text-[10px] text-slate-500 mt-1">Phase 2 — override UI pending</div>
        </div>
    </div>

    {{-- ─────── Method footer ─────── --}}
    <div class="bg-slate-900 text-slate-300 rounded-xl p-5 text-[12px] leading-relaxed">
        <div class="text-[10px] uppercase tracking-widest text-amber-400 font-semibold mb-2">Method</div>
        <p>
            <strong class="text-white">Direct method</strong>, 16-week horizon. AR/AP timing uses <strong class="text-white">Method C (hybrid)</strong> from
            the spec: due date as base, shifted by per-partner historical pay-lag, capped at ±30 days. Lines tagged
            <span class="mono text-amber-200">A</span> extract live from the Odoo GL,
            <span class="mono text-sky-300">B</span> project from historical patterns,
            <span class="mono text-violet-300">C</span> follow a fixed remittance schedule, and
            <span class="mono text-stone-400">D</span> default to zero pending tenant-side manual entry (Phase 2 override UI).
        </p>
        <p class="mt-2 text-slate-400 text-[11px]">
            Cut-off {{ $data['cut_off'] }} · {{ $gov['method'] ?? '' }} · cache 5 min — append <span class="mono">?refresh=1</span> to force recomputation.
        </p>
    </div>

    {{-- ─────── ECharts setup ─────── --}}
    <script src="https://cdn.jsdelivr.net/npm/echarts@5.4.3/dist/echarts.min.js"></script>
    <script>
    (function () {
        const el = document.getElementById('cf16w-chart');
        if (!el) return;
        const chart = echarts.init(el);
        const opt = {
            grid:    { top: 18, left: 70, right: 24, bottom: 32, containLabel: false },
            tooltip: { trigger: 'axis', axisPointer: { type: 'cross' },
                       valueFormatter: v => new Intl.NumberFormat('en-US').format(v) + ' XAF' },
            xAxis: {
                type: 'category',
                data: @json($weekLabels),
                axisLine:  { lineStyle: { color: '#94a3b8' } },
                axisLabel: { color: '#64748b', fontSize: 11 },
            },
            yAxis: {
                type: 'value',
                axisLine:   { show: false },
                axisLabel:  { color: '#64748b', fontSize: 10,
                              formatter: v => Math.abs(v) >= 1e6 ? (v/1e6).toFixed(0) + ' M'
                                            : Math.abs(v) >= 1e3 ? (v/1e3).toFixed(0) + ' K'
                                            : v },
                splitLine:  { lineStyle: { color: '#e2e8f0', type: 'dashed' } },
            },
            series: [
                // Receipts (positive bars)
                { name: 'Receipts', type: 'bar', stack: 'flow',
                  data: @json($receiptSeries),
                  itemStyle: { color: '#059669', borderRadius: [3,3,0,0] },
                  barWidth: 14 },
                // Disbursements (negative bars)
                { name: 'Disbursements', type: 'bar', stack: 'flow',
                  data: @json($disburSeries),
                  itemStyle: { color: '#dc2626', borderRadius: [0,0,3,3] },
                  barWidth: 14 },
                // Closing line
                { name: 'Closing cash', type: 'line',
                  data: @json($closingSeries),
                  smooth: true,
                  lineStyle: { width: 2.5, color: '#065f46' },
                  itemStyle: { color: '#065f46' },
                  areaStyle: { color: 'rgba(16, 185, 129, 0.10)' },
                  z: 4 },
                // Min buffer horizontal
                { name: 'Min buffer', type: 'line',
                  data: @json(array_fill(0, count($weeks), $kpis['min_buffer'] ?? 0)),
                  lineStyle: { type: 'dashed', width: 1.5, color: '#d97706' },
                  itemStyle: { color: '#d97706' },
                  symbol: 'none',
                  z: 3,
                  markLine: { silent: true, data: [],
                              label: { formatter: 'minimum buffer {{ number_format($kpis['min_buffer'] ?? 0, 0) }}',
                                       position: 'insideEndTop', color: '#d97706', fontSize: 10 } } },
            ],
        };
        chart.setOption(opt);
        window.addEventListener('resize', () => chart.resize());
    })();
    </script>

@else
    <div class="bg-stone-50 border border-stone-200 rounded-xl p-8 text-center text-slate-500">
        Loading forecast…
    </div>
@endif

@endsection
