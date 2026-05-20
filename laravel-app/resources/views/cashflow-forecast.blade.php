@extends('layouts.app')
@section('title', 'Cashflow Forecast')

@section('page-title', 'Cashflow Forecast')
@section('content')

{{-- ──────────────────────────────────────────────────────────────
     SECTION 1 — 16-Week Rolling Cash Flow Forecast (direct method)
     Operational surveillance tool — used daily by Treasury. Always
     renders, even on Odoo extraction failure (which surfaces as a
     warning banner above the section but doesn't blank it).
     ────────────────────────────────────────────────────────────── --}}
@php
    $data16w  = $data16w  ?? null;
    $error16w = $error16w ?? null;
    $has16w   = $data16w && !empty($data16w['weeks']);
@endphp

@if ($error16w)
    <div class="bg-coral-50 border border-coral-200 text-coral-800 rounded-xl p-4 text-sm mb-4">
        <strong>16-week forecast extraction failed:</strong> {{ $error16w }}
        <div class="mt-1 text-[11px] text-coral-700">
            The matrix below renders with zeros so you can see the page structure. Common causes: Odoo unreachable
            (check Settings → Odoo Connection), wrong credentials, or the underlying tables haven't been populated.
        </div>
    </div>
@endif

@if ($has16w)
    @php
        $ccy16   = $data16w['currency'] ?? 'XAF';
        $kpis16  = $data16w['kpis'] ?? [];
        $weeks16 = $data16w['weeks'] ?? [];
        $lines16 = $data16w['lines'] ?? [];
        $errs16  = $data16w['errors'] ?? [];

        $short16 = function ($n) {
            $n = (float) $n;
            $sign = $n < 0 ? '−' : '';
            $abs = abs($n);
            if ($abs >= 1_000_000_000) return $sign . number_format($abs / 1_000_000_000, 2) . ' B';
            if ($abs >= 1_000_000)     return $sign . number_format($abs / 1_000_000, 2) . ' M';
            if ($abs >= 1_000)         return $sign . number_format($abs / 1_000, 1) . ' K';
            return $sign . number_format($abs, 0);
        };
        $fmt16 = fn ($n) => number_format((float) $n, 0);

        $byBand16 = [];
        foreach ($lines16 as $l) { $byBand16[$l['band']][] = $l; }

        $weekLabels16 = array_column($weeks16, 'label');
        $closingS16   = array_column($weeks16, 'closing');
        $receiptS16   = array_column($weeks16, 'receipts');
        $disburS16    = array_map(fn ($v) => -1 * $v, array_column($weeks16, 'disbursements'));

        $bandLabels16 = [
            1 => 'Opening cash position',
            2 => 'Operating receipts',
            3 => 'Operating disbursements',
            4 => 'Financing & capital flows',
        ];
        $engineColors16 = [
            'A' => 'bg-emerald-100 text-emerald-700 border-emerald-200',
            'B' => 'bg-sky-100 text-sky-700 border-sky-200',
            'C' => 'bg-violet-100 text-violet-700 border-violet-200',
            'D' => 'bg-stone-200 text-stone-700 border-stone-300',
            '=' => 'bg-slate-800 text-white border-slate-900',
        ];
        $confColors16 = [
            'High'   => 'text-emerald-700',
            'Medium' => 'text-amber-700',
            'Low'    => 'text-rose-700',
            'Policy' => 'text-violet-700',
        ];
    @endphp

    {{-- KPI + chart card. Note: NO overflow-hidden on this outer
         panel — ECharts needs an unclipped container to compute its
         dimensions correctly on resize, and clipping here was causing
         the chart to not render on some viewports. Rounded corners
         still work because the children themselves don't overflow. --}}
    <div class="bg-white rounded-xl border border-stone-200 shadow-sm mb-5">
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
                <div class="text-[11px] text-slate-500 mono mt-0.5">Cut-off · {{ $data16w['cut_off'] }}</div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-4 gap-3 p-4">
            <div class="border border-stone-200 rounded-lg p-3">
                <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Opening cash · W1</div>
                <div class="text-2xl font-bold mono text-emerald-700 mt-1">{{ $short16($kpis16['opening_w1'] ?? 0) }}</div>
                <div class="text-[10px] text-slate-500 mt-1">vs minimum buffer {{ $short16($kpis16['min_buffer'] ?? 0) }}</div>
            </div>
            <div class="border border-stone-200 rounded-lg p-3">
                <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Projected closing · W{{ \App\Services\CashFlow16WService::HORIZON_WEEKS }}</div>
                <div class="text-2xl font-bold mono text-emerald-700 mt-1">{{ $short16($kpis16['projected_w16'] ?? 0) }}</div>
                <div class="text-[10px] text-slate-500 mt-1">{{ ($kpis16['horizon_delta'] ?? 0) >= 0 ? '+ ' : '− ' }}{{ $short16(abs($kpis16['horizon_delta'] ?? 0)) }} over horizon</div>
            </div>
            <div class="border border-stone-200 rounded-lg p-3">
                <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Lowest weekly balance</div>
                <div class="text-2xl font-bold mono text-amber-700 mt-1">{{ $short16($kpis16['lowest_balance'] ?? 0) }}</div>
                <div class="text-[10px] text-slate-500 mt-1">W{{ $kpis16['lowest_week_no'] ?? 0 }} · {{ $short16($kpis16['lowest_under_min'] ?? 0) }} {{ ($kpis16['lowest_under_min'] ?? 0) > 0 ? 'under buffer' : 'above buffer' }}</div>
            </div>
            <div class="border border-stone-200 rounded-lg p-3">
                <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Buffer breach weeks</div>
                <div class="text-2xl font-bold mono {{ ($kpis16['buffer_breaches'] ?? 0) > 0 ? 'text-rose-700' : 'text-emerald-700' }} mt-1">
                    {{ $kpis16['buffer_breaches'] ?? 0 }} <span class="text-base text-slate-500 font-normal">of {{ count($weeks16) }}</span>
                </div>
                <div class="text-[10px] text-slate-500 mt-1">
                    @if (($kpis16['buffer_breaches'] ?? 0) > 0)
                        Action: shift W{{ $kpis16['lowest_week_no'] ?? '?' }} supplier run
                    @else
                        No buffer breach forecast
                    @endif
                </div>
            </div>
        </div>

        <div class="px-5 pb-4 pt-2">
            <div id="cf16w-chart" class="w-full" style="height: 320px;"></div>
            <div class="flex items-center gap-4 text-[10px] text-slate-600 mt-2 flex-wrap">
                <span class="inline-flex items-center gap-1.5"><span class="w-3 h-0.5 bg-emerald-700"></span> Closing cash (projected)</span>
                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 bg-emerald-600"></span> Weekly receipts ▲</span>
                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 bg-rose-600"></span> Weekly disbursements ▼</span>
                <span class="inline-flex items-center gap-1.5"><span class="w-3 h-0.5 border-t border-dashed border-amber-600"></span> Minimum buffer</span>
            </div>
            <p class="text-[10px] text-slate-500 italic mt-3">Closing balance<sub>W</sub> = Opening<sub>W</sub> + Σ confirmed receipts − Σ committed disbursements. Direct-method weekly flows; bridges to monthly indirect view below.</p>
        </div>
    </div>

    @if (!empty($errs16))
        <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-lg px-3 py-2 text-[11px] mb-4">
            <strong>Data-source warnings ({{ count($errs16) }}):</strong>
            <ul class="list-disc pl-4 mt-1">
                @foreach ($errs16 as $e)<li class="mono">{{ $e }}</li>@endforeach
            </ul>
        </div>
    @endif

    <div class="bg-white rounded-xl border border-stone-200 shadow-sm overflow-hidden mb-5">
        <div class="px-4 py-3 border-b border-stone-100 flex items-center justify-between">
            <div>
                <h3 class="text-sm font-bold text-slate-900">Forecast matrix · {{ count($weeks16) }} weeks × {{ count($lines16) }} lines</h3>
                <p class="text-[11px] text-slate-500">Engine tags: A extraction · B behavioural · C scheduled · D manual.</p>
            </div>
            <a href="?refresh16w=1" class="text-[11px] font-medium text-rose-700 hover:text-rose-900 border border-rose-300 rounded-lg px-3 py-1.5">⟳ Refresh</a>
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
                        @foreach ($weeks16 as $w)
                            <th class="px-2 py-2 text-right font-semibold w-20 whitespace-nowrap">{{ $w['label'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @foreach ($byBand16 as $bandNo => $bandLines)
                        <tr class="bg-slate-900 text-white">
                            <td colspan="{{ 5 + count($weeks16) }}" class="px-3 py-1.5 text-[10px] uppercase tracking-widest font-bold">Band {{ $bandNo }} · {{ $bandLabels16[$bandNo] ?? '—' }}</td>
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
                                    <span class="inline-block text-[9px] font-bold mono border rounded px-1 py-0.5 {{ $engineColors16[$line['engine']] ?? '' }}">{{ $line['engine'] }}</span>
                                </td>
                                <td class="px-2 py-1.5 text-center text-[9.5px] {{ $confColors16[$line['confidence']] ?? 'text-slate-400' }}">{{ $line['confidence'] ?? '—' }}</td>
                                <td class="px-2 py-1.5 text-right mono {{ $line['total'] != 0 ? $signCls : 'text-slate-400' }}">
                                    {{ $line['total'] != 0 ? $fmt16($line['total']) : '—' }}
                                </td>
                                @foreach ($weeks16 as $w => $weekRow)
                                    @php $val = isset($line['by_week']) && is_array($line['by_week']) ? ($line['by_week'][$w] ?? 0) : null; @endphp
                                    <td class="px-2 py-1.5 text-right mono {{ $val ? $signCls : 'text-slate-300' }}">
                                        @if ($val === null && !$isTotal)<span class="text-slate-300">·</span>
                                        @elseif ($val == 0)<span class="text-slate-300">·</span>
                                        @else{{ $fmt16($val) }}
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    @endforeach

                    <tr class="bg-slate-900 text-white">
                        <td colspan="{{ 5 + count($weeks16) }}" class="px-3 py-1.5 text-[10px] uppercase tracking-widest font-bold">Band 5 · Closing position &amp; metrics</td>
                    </tr>
                    <tr class="bg-emerald-50/40 font-semibold">
                        <td class="sticky left-0 z-10 bg-emerald-50/40 px-3 py-1.5 mono text-slate-500">5.1</td>
                        <td class="sticky left-12 z-10 bg-emerald-50/40 px-2 py-1.5">
                            <div class="text-slate-900">Net cash movement</div>
                            <div class="text-[9.5px] text-slate-500 mono leading-tight">Σ B2 − Σ B3 + Σ B4 (signed)</div>
                        </td>
                        <td colspan="3"></td>
                        @foreach ($weeks16 as $w)
                            <td class="px-2 py-1.5 text-right mono {{ ($w['net'] ?? 0) >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">{{ ($w['net'] ?? 0) >= 0 ? '+' : '' }}{{ $fmt16($w['net']) }}</td>
                        @endforeach
                    </tr>
                    <tr class="bg-slate-50 font-bold">
                        <td class="sticky left-0 z-10 bg-slate-50 px-3 py-1.5 mono text-slate-500">5.2</td>
                        <td class="sticky left-12 z-10 bg-slate-50 px-2 py-1.5">
                            <div class="text-slate-900">Closing cash position</div>
                            <div class="text-[9.5px] text-slate-500 mono leading-tight">Prior closing + net movement</div>
                        </td>
                        <td colspan="3"></td>
                        @foreach ($weeks16 as $w)
                            <td class="px-2 py-1.5 text-right mono {{ ($w['closing'] ?? 0) >= ($kpis16['min_buffer'] ?? 0) ? 'text-emerald-700' : 'text-rose-700' }}">{{ $fmt16($w['closing']) }}</td>
                        @endforeach
                    </tr>
                    <tr class="bg-slate-50">
                        <td class="sticky left-0 z-10 bg-slate-50 px-3 py-1.5 mono text-slate-500">5.3</td>
                        <td class="sticky left-12 z-10 bg-slate-50 px-2 py-1.5">
                            <div class="text-slate-900">Buffer headroom</div>
                            <div class="text-[9.5px] text-slate-500 mono leading-tight">Closing − minimum policy</div>
                        </td>
                        <td colspan="3"></td>
                        @foreach ($weeks16 as $w)
                            @php $head = ($w['closing'] ?? 0) - ($kpis16['min_buffer'] ?? 0); @endphp
                            <td class="px-2 py-1.5 text-right mono {{ $head >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">{{ $head >= 0 ? '+' : '' }}{{ $fmt16($head) }}</td>
                        @endforeach
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="bg-slate-900 text-slate-300 rounded-xl p-5 text-[12px] leading-relaxed mb-6">
        <div class="text-[10px] uppercase tracking-widest text-amber-400 font-semibold mb-2">Method · 16-week forecast</div>
        <p>
            <strong class="text-white">Direct method</strong>, 16-week horizon. AR/AP timing uses
            <strong class="text-white">Method C (hybrid)</strong>: due date + per-partner historical pay-lag, capped at ±30 days.
            Engine tags —
            <span class="mono text-amber-200">A</span> extraction (live GL),
            <span class="mono text-sky-300">B</span> behavioural,
            <span class="mono text-violet-300">C</span> scheduled (fixed remittance dates),
            <span class="mono text-stone-400">D</span> manual (defaults to 0).
            Cache 5 min — append <span class="mono">?refresh16w=1</span> to force recomputation.
        </p>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/echarts@5.4.3/dist/echarts.min.js"></script>
    <script>
    (function () {
        function initChart() {
            const el = document.getElementById('cf16w-chart');
            if (!el) return;
            // Defensive: if the container has 0 width (Tailwind CDN
            // still loading), wait a tick and retry. ECharts will
            // render at 0x0 and never resize otherwise.
            if (el.clientWidth === 0) { setTimeout(initChart, 100); return; }
            if (typeof echarts === 'undefined') { setTimeout(initChart, 100); return; }
            const chart = echarts.init(el);
            chart.setOption({
            grid:    { top: 18, left: 70, right: 24, bottom: 32, containLabel: false },
            tooltip: { trigger: 'axis', axisPointer: { type: 'cross' },
                       valueFormatter: v => new Intl.NumberFormat('en-US').format(v) + ' XAF' },
            xAxis: {
                type: 'category', data: @json($weekLabels16),
                axisLine:  { lineStyle: { color: '#94a3b8' } },
                axisLabel: { color: '#64748b', fontSize: 11 },
            },
            yAxis: {
                type: 'value',
                axisLine: { show: false },
                axisLabel: { color: '#64748b', fontSize: 10,
                             formatter: v => Math.abs(v) >= 1e6 ? (v/1e6).toFixed(0) + ' M'
                                          : Math.abs(v) >= 1e3 ? (v/1e3).toFixed(0) + ' K' : v },
                splitLine: { lineStyle: { color: '#e2e8f0', type: 'dashed' } },
            },
            series: [
                { name: 'Receipts',      type: 'bar',  stack: 'flow', data: @json($receiptS16),
                  itemStyle: { color: '#059669', borderRadius: [3,3,0,0] }, barWidth: 14 },
                { name: 'Disbursements', type: 'bar',  stack: 'flow', data: @json($disburS16),
                  itemStyle: { color: '#dc2626', borderRadius: [0,0,3,3] }, barWidth: 14 },
                { name: 'Closing cash',  type: 'line', data: @json($closingS16),
                  smooth: true, lineStyle: { width: 2.5, color: '#065f46' },
                  itemStyle: { color: '#065f46' },
                  areaStyle: { color: 'rgba(16, 185, 129, 0.10)' }, z: 4 },
                { name: 'Min buffer',    type: 'line',
                  data: @json(array_fill(0, count($weeks16), $kpis16['min_buffer'] ?? 0)),
                  lineStyle: { type: 'dashed', width: 1.5, color: '#d97706' },
                  itemStyle: { color: '#d97706' }, symbol: 'none', z: 3 },
            ],
            });
            window.addEventListener('resize', () => chart.resize());
        }
        // Wait for DOM ready, then init.
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initChart);
        } else {
            initChart();
        }
    })();
    </script>
@endif

{{-- ──────────────────────────────────────────────────────────────
     SECTION 2 — Annual indirect-via-direct monthly forecast
     The existing analysis, unchanged. Strategic / board context.
     ────────────────────────────────────────────────────────────── --}}
<div class="mt-2 mb-3 flex items-center gap-3">
    <div class="bg-slate-900 text-white text-[10px] uppercase tracking-widest font-bold rounded px-2 py-1 shrink-0">02</div>
    <div>
        <h2 class="text-base font-bold text-slate-900">Annual Cashflow · {{ $year }}</h2>
        <p class="text-[11px] text-slate-500">Monthly direct method · SMLY forecast for forward months · strategic / board context</p>
    </div>
</div>

    @if ($error)
        <div class="bg-coral-50 border border-coral-200 text-coral-800 rounded-xl p-4 text-sm mb-6"><strong>Could not fetch cashflow:</strong> {{ $error }}</div>
    @endif

    @if (!$data || empty($data['lines']))
        <div class="bg-warm-50 border border-warm-200 rounded-xl p-5">
            <div class="text-sm font-semibold text-[#7a5a1a]">Awaiting data</div>
            <p class="text-xs text-[#7c7466] mt-1 leading-relaxed">No cashflow data — check that the GL is reachable and that posted moves exist for {{ $year }}.</p>
        </div>
    @else
        @php
            $months = $data['months'];
            $lines  = $data['lines'];
            $ccy    = $data['currency'] ?? 'XAF';
            $fmtM = function ($v) {
                if ($v === null) return '—';
                $m = ((float) $v) / 1_000_000.0;
                if (abs($m) < 0.5) return '0';
                return $m < 0 ? '(' . number_format(-$m, 0) . ')' : number_format($m, 0);
            };
            $actualText  = 'text-[#5d8067]';
            $budgetText  = 'text-[#6B2D2F]';
            $actualBadge = 'bg-[#e9f0eb] text-[#3d5e48] border-[#cbdbd0]';
            $budgetBadge = 'bg-[#f4e2e3] text-[#6B2D2F] border-[#e0c5c7]';
        @endphp

        <div class="bg-white border border-[#e8e4de] rounded-xl overflow-hidden">
            <div class="flex items-center justify-between gap-3 px-4 py-2.5 border-b border-[#e8e4de] bg-[#faf8f5]">
                <div class="text-[10.5px] text-[#7c7466] flex items-center gap-3 flex-wrap">
                    <span class="inline-flex items-center gap-1.5"><span class="inline-block w-3 h-3 rounded-sm bg-[#e9f0eb] border border-[#cbdbd0]"></span><span class="font-semibold {{ $actualText }}">Actual</span></span>
                    <span class="text-[#cbc7be]">·</span>
                    <span class="inline-flex items-center gap-1.5"><span class="inline-block w-3 h-3 rounded-sm bg-[#f4e2e3] border border-[#e0c5c7]"></span><span class="font-semibold {{ $budgetText }}">Forecast</span></span>
                </div>
                <div class="text-[10.5px] text-[#7c7466]">Opening cash {{ $year }}: <strong>{{ $fmtM($data['opening_cash'] ?? 0) }}</strong> million {{ $ccy }} · Direct method (SYSCOHADA Class 5)</div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-[11.5px]" style="min-width: 1100px;">
                    <thead class="bg-white">
                        <tr class="border-b border-[#e8e4de]">
                            <th class="text-left py-1.5 pl-4 pr-2 sticky left-0 bg-white z-10 w-[20rem]"><span class="text-[9.5px] uppercase tracking-wider text-[#8a8578] font-medium">Cashflow Line</span></th>
                            @foreach ($months as $m)
                                @php $isActual = !$m['is_forecast']; @endphp
                                <th class="py-1.5 px-2 text-center"><span class="inline-flex text-[9px] uppercase tracking-wider rounded px-1.5 py-0.5 border font-semibold {{ $isActual ? $actualBadge : $budgetBadge }}">{{ $isActual ? 'Actual' : 'Fcst' }}</span></th>
                            @endforeach
                            <th class="py-1.5 px-2 text-center"><span class="inline-flex text-[9px] uppercase tracking-wider rounded px-1.5 py-0.5 border font-semibold bg-slate-100 text-slate-700 border-slate-200">Year</span></th>
                        </tr>
                        <tr class="border-b border-[#cbc7be] bg-[#fcfbf8]">
                            <th class="text-left py-2 pl-4 pr-2 sticky left-0 bg-[#fcfbf8] z-10 w-[20rem]"><span class="text-[10px] uppercase tracking-wider text-[#5a6a70] font-semibold">Line · {{ $ccy }} (millions)</span></th>
                            @foreach ($months as $m)
                                @php $isActual = !$m['is_forecast']; @endphp
                                <th class="py-2 px-2 text-center text-[11px] tabular-nums {{ $isActual ? $actualText : $budgetText }} font-bold">{{ $m['label'] }}</th>
                            @endforeach
                            <th class="py-2 px-2 text-center text-[11px] font-bold text-[#1e3d44]">{{ $year }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#f1ede5]">
                        @foreach ($lines as $line)
                            @php
                                $isSubtotal = $line['kind'] === 'subtotal';
                                $bg   = $isSubtotal ? 'bg-[#fcfbf8]' : 'bg-white';
                                $font = $isSubtotal ? 'font-bold' : 'font-medium';
                                $total = array_sum($line['monthly']);
                            @endphp
                            <tr class="{{ $bg }} hover:bg-[#faf8f5] transition-colors">
                                <td class="text-left pl-4 pr-2 py-1.5 sticky left-0 {{ $bg }} z-10 w-[20rem] {{ $font }} text-[#1e3d44]">{{ $line['label'] }}</td>
                                @foreach ($months as $m)
                                    @php
                                        $isActual = !$m['is_forecast'];
                                        $colour   = $isActual ? $actualText : $budgetText;
                                        $val      = $line['monthly'][$m['num']] ?? null;
                                    @endphp
                                    <td class="text-right tabular-nums px-2 py-1.5 {{ $colour }} {{ $font }}">{{ $fmtM($val) }}</td>
                                @endforeach
                                <td class="text-right tabular-nums px-2 py-1.5 {{ $font }} text-[#1e3d44] border-l border-[#e8e4de]">{{ $fmtM($total) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="px-4 py-2.5 border-t border-[#e8e4de] bg-[#faf8f5] text-[10.5px] text-[#7c7466]">
                Direct-method cashflow. Each line tracks actual cash movements (Class 5 — banques + caisse) categorised by the dominant counter-account on the move: customers (41) / suppliers (40) / employees (42, 64) / taxes (43, 44) / income tax (89) / capex (Class 2) / financing (Class 1). Forecast = SMLY (same month last year) per bucket with trailing-3-month fallback.
            </div>
        </div>
    @endif
@endsection
