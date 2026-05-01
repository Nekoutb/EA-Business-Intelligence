@extends('layouts.app')
@section('title', 'Macro Economics')

@section('header')
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 mb-1">
                <span class="text-[10px] uppercase tracking-[0.35em] text-[#8a8578] font-medium">
                    {{ $data['country_label'] ?? '—' }} · {{ ea_last_sync_label() }}
                </span>
            </div>
            <h1 class="text-2xl font-bold text-[#1e3d44]">Macro Economics</h1>
            <div class="text-xs text-[#7c7466] mt-1">
                Six trade-focused indicators relevant to scanning operations — last
                {{ ($data['history_window']['to'] ?? 0) - ($data['history_window']['from'] ?? 0) + 1 }}
                years of actuals plus a 2-year linear projection. Plus the BEAC TIAO policy rate.
            </div>
        </div>

        {{-- Country picker. Reloads the page; values keyed by ISO 3166-1
             alpha-3 (CMR / TCD / etc) — what the World Bank API expects. --}}
        <form method="get" class="flex items-end gap-2">
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[#8a8578] font-medium mb-1">Country</label>
                <select name="country"
                        class="text-xs border border-[#e8e4de] rounded-lg px-3 py-2 bg-white text-[#1e3d44] focus:ring-2 focus:ring-brand-300 focus:border-brand-300 outline-none min-w-[10rem]"
                        onchange="this.form.submit()">
                    @foreach ($countries as $code => $label)
                        <option value="{{ $code }}" {{ $code === $country ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </form>
    </div>
@endsection

@section('content')
    @if ($error)
        <div class="bg-coral-50 border border-coral-200 text-coral-800 rounded-xl p-4 text-sm mb-6">
            <strong>Could not fetch macro data:</strong> {{ $error }}
        </div>
    @endif

    @if (!$data)
        <div class="bg-warm-50 border border-warm-200 rounded-xl p-5 mb-6">
            <div class="text-sm font-semibold text-[#7a5a1a]">Awaiting data</div>
            <p class="text-xs text-[#7c7466] mt-1 leading-relaxed">No macro indicators returned. Try a different country, or check that outbound HTTPS to api.worldbank.org is allowed.</p>
        </div>
    @else

        @php
            $tiao = $data['tiao'] ?? [];
            $src  = $data['data_source'] ?? [];
        @endphp

        {{-- ── BEAC TIAO Card ──────────────────────────────────────────────
             Prominent, full-width because it's a single live number with
             policy-rate semantics that affect the whole region. --}}
        <div class="bg-white border border-[#e8e4de] rounded-xl p-5 mb-6">
            <div class="flex items-start justify-between gap-4 flex-wrap">
                <div class="flex-1 min-w-[14rem]">
                    <div class="flex items-center gap-2 mb-1">
                        <div class="w-1 h-4 rounded bg-brand-500"></div>
                        <h2 class="text-xs font-semibold uppercase tracking-wider text-[#5a6a70]">BEAC TIAO — Central-Bank Policy Rate</h2>
                        <span class="text-[9.5px] uppercase tracking-wider rounded px-1.5 py-0.5 border
                            {{ ($tiao['source'] ?? 'config') === 'live'
                                ? 'bg-sage-50 text-sage-700 border-sage-200'
                                : 'bg-warm-50 text-warm-700 border-warm-200' }}">
                            {{ ($tiao['source'] ?? 'config') === 'live' ? 'LIVE · scraped' : 'CONFIG · manual' }}
                        </span>
                    </div>
                    <div class="text-3xl font-bold text-[#2d3748] mt-2 tabular-nums">
                        {{ number_format((float)($tiao['rate'] ?? 0), 2) }}<span class="text-lg ml-0.5">%</span>
                    </div>
                    <div class="text-[11px] text-[#7c7466] mt-1">
                        As of <strong>{{ $tiao['as_of'] ?? '—' }}</strong> ·
                        {{ $tiao['description'] ?? '' }}
                    </div>
                </div>

                <div class="text-[11px] text-[#7c7466] max-w-md leading-relaxed">
                    <strong class="text-[#1e3d44]">Source:</strong>
                    <a href="{{ $tiao['source_url'] ?? '#' }}" target="_blank" rel="noopener"
                       class="text-brand-700 hover:text-brand-900 underline decoration-dotted">{{ parse_url($tiao['source_url'] ?? '', PHP_URL_HOST) ?: 'beac.int' }}</a>.
                    <div class="mt-1 text-[#a09d95] italic">{{ $tiao['note'] ?? '' }}</div>
                </div>
            </div>
        </div>

        {{-- ── Six indicator cards with mini line charts ─────────────────── --}}
        <div class="mb-6">
            <div class="flex items-center gap-2 mb-3">
                <div class="w-1 h-4 rounded bg-sage-500"></div>
                <h2 class="text-xs font-semibold uppercase tracking-wider text-[#5a6a70]">Trade-focused Indicators · {{ $data['country_label'] }}</h2>
            </div>

            @php
                $palettes = [
                    'sage'  => ['bar' => 'bg-sage-500',  'line' => '#7eaa8e', 'proj' => '#bcd2c3'],
                    'brand' => ['bar' => 'bg-brand-500', 'line' => '#5a7e8a', 'proj' => '#a7bdc6'],
                    'warm'  => ['bar' => 'bg-warm-500',  'line' => '#c89e58', 'proj' => '#e3c897'],
                    'coral' => ['bar' => 'bg-coral-500', 'line' => '#c66f5d', 'proj' => '#e3a698'],
                    'slate' => ['bar' => 'bg-slate-400', 'line' => '#7d8b95', 'proj' => '#b6bfc7'],
                ];

                $fmtCompact = function ($v, string $unit): string {
                    if ($v === null || !is_numeric($v)) return '—';
                    $av = abs((float) $v);
                    if ($unit === '%') return number_format((float)$v, 2) . '%';
                    if ($unit === 'LCU/USD') return number_format((float)$v, 1);
                    if ($av >= 1e9) return number_format($v / 1e9, 2) . ' B';
                    if ($av >= 1e6) return number_format($v / 1e6, 1) . ' M';
                    if ($av >= 1e3) return number_format($v / 1e3, 0) . ' K';
                    return number_format((float)$v, 0);
                };
            @endphp

            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
                @foreach ($data['indicators'] as $key => $ind)
                    @php
                        $meta    = $ind['meta'];
                        $palette = $palettes[$meta['colour']] ?? $palettes['slate'];
                        $points  = $ind['all_points'] ?? [];
                        $proj    = $ind['projection'] ?? [];

                        // Build the chart payload — labels and the series of
                        // values, marking which points are projected vs actual.
                        $chartLabels = [];
                        $chartValues = [];
                        $chartIsProj = [];
                        foreach ($points as $year => $value) {
                            $chartLabels[] = (string) $year;
                            $chartValues[] = (float) $value;
                            $chartIsProj[] = isset($proj[$year]);
                        }
                        $chartId = 'macro-chart-' . $key;
                        $chartPayload = [
                            'labels'       => $chartLabels,
                            'values'       => $chartValues,
                            'is_projected' => $chartIsProj,
                            'color'        => $palette['line'],
                            'proj_color'   => $palette['proj'],
                            'unit'         => $meta['unit'],
                        ];

                        // YoY pill colouring follows "better_when": green
                        // when moving in the favourable direction, red
                        // when moving the wrong way, neutral when mixed.
                        $yoy = $ind['yoy_pct'];
                        $yoyTone = 'text-[#7c7466]';
                        if ($yoy !== null) {
                            if ($meta['better_when'] === 'higher') {
                                $yoyTone = $yoy >= 0 ? 'text-sage-700' : 'text-coral-700';
                            } elseif ($meta['better_when'] === 'lower') {
                                $yoyTone = $yoy >= 0 ? 'text-coral-700' : 'text-sage-700';
                            }
                        }
                    @endphp

                    <div class="bg-white rounded-xl border border-[#e8e4de] shadow-sm flex flex-col overflow-hidden">
                        <div class="flex items-stretch">
                            <div class="w-1 {{ $palette['bar'] }} shrink-0"></div>
                            <div class="flex-1 p-4">
                                <div class="text-[11px] uppercase tracking-wider text-[#8a8578] font-medium">{{ $meta['label'] }}</div>
                                @if ($ind['pending'])
                                    <div class="text-2xl font-bold text-[#a09d95] mt-1 tabular-nums">— —</div>
                                    <div class="text-[10.5px] text-warm-700 mt-1">No data returned for this indicator</div>
                                @else
                                    <div class="flex items-baseline gap-2 mt-1">
                                        <div class="text-2xl font-bold text-[#2d3748] tabular-nums">{{ $fmtCompact($ind['latest_value'], $meta['unit']) }}</div>
                                        <div class="text-[10px] text-[#7c7466]">in {{ $ind['latest_year'] }}</div>
                                    </div>
                                    @if ($yoy !== null)
                                        <div class="mt-1 inline-flex items-center text-[10.5px] font-semibold {{ $yoyTone }} gap-1">
                                            <svg class="w-2.5 h-2.5" viewBox="0 0 24 24" fill="currentColor">
                                                <path d="{{ $yoy >= 0 ? 'M12 6l5 5H7z' : 'M12 18l5-5H7z' }}"/>
                                            </svg>
                                            {{ ($yoy >= 0 ? '+' : '') . number_format($yoy, 1) }}% YoY
                                        </div>
                                    @endif
                                    <div class="text-[10.5px] text-[#7c7466] mt-1 leading-snug">{{ $meta['sub'] }}</div>
                                @endif
                            </div>
                        </div>

                        @if (!$ind['pending'] && count($points) > 1)
                            <div id="{{ $chartId }}" class="px-4 pb-4 pt-1" data-chart='@json($chartPayload)'></div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Source attribution + methodology --}}
        <div class="bg-white border border-[#e8e4de] rounded-xl p-5">
            <h3 class="text-sm font-semibold text-[#1e3d44] mb-2">Sources & methodology</h3>
            <div class="text-[11.5px] text-[#7c7466] leading-relaxed space-y-1.5">
                <div>
                    <strong class="text-[#1e3d44]">{{ $src['name'] ?? 'World Bank Open Data' }}</strong> —
                    <a href="{{ $src['url'] ?? '#' }}" target="_blank" rel="noopener" class="text-brand-700 hover:text-brand-900 underline decoration-dotted">
                        {{ parse_url($src['url'] ?? '', PHP_URL_HOST) ?: 'data.worldbank.org' }}
                    </a>
                    — {{ $src['note'] ?? '' }}
                </div>
                <div>
                    <strong class="text-[#1e3d44]">BEAC</strong> — central-bank rate (TIAO) sourced from
                    <a href="https://www.beac.int" target="_blank" rel="noopener" class="text-brand-700 hover:text-brand-900 underline decoration-dotted">beac.int</a>.
                    Best-effort scrape with config fallback (env <code class="text-[10px] bg-[#faf8f5] border border-[#ece7df] rounded px-1 py-0.5">BEAC_TIAO_RATE</code> /
                    <code class="text-[10px] bg-[#faf8f5] border border-[#ece7df] rounded px-1 py-0.5">BEAC_TIAO_AS_OF</code>).
                </div>
                <div>
                    <strong class="text-[#1e3d44]">Projection</strong> — linear regression on the 5 historical points; future years shown with a dashed line.
                    Linear projection is intentionally simple — for richer forecasts, connect TradingEconomics or IMF WEO.
                </div>
                <div>
                    Data refreshed: {{ \Carbon\Carbon::parse($data['fetched_at'])->diffForHumans() }} ·
                    Indicator series cached 24 h server-side · BEAC scrape cached 6 h.
                </div>
            </div>
        </div>

        {{-- Vanilla SVG mini line-chart renderer. Pure JS, no library. --}}
        <script>
        (function () {
            document.querySelectorAll('[id^="macro-chart-"]').forEach(function (root) {
                let payload;
                try { payload = JSON.parse(root.dataset.chart || '{}'); }
                catch (e) { return; }

                const labels   = payload.labels || [];
                const values   = payload.values || [];
                const isProj   = payload.is_projected || [];
                if (labels.length < 2) return;

                const W = 320, H = 90, padL = 30, padR = 8, padT = 8, padB = 18;
                const innerW = W - padL - padR, innerH = H - padT - padB;

                // Y-axis bounds with a small padding so the line doesn't
                // touch the top/bottom edge.
                const yMin = Math.min.apply(null, values);
                const yMax = Math.max.apply(null, values);
                const yPad = (yMax - yMin) * 0.1 || (Math.abs(yMax) * 0.1 || 1);
                const yLo = yMin - yPad, yHi = yMax + yPad;
                const yRange = (yHi - yLo) || 1;

                const xScale = i => padL + (i * innerW) / (labels.length - 1);
                const yScale = v => padT + innerH - ((v - yLo) / yRange) * innerH;

                let svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${W} ${H}" preserveAspectRatio="xMidYMid meet" class="w-full h-auto" style="font-family:inherit;">`;

                // Y-axis tick labels (just min, mid, max — keep it sparse)
                const ticks = [yMin, (yMin + yMax) / 2, yMax];
                ticks.forEach(function (t) {
                    const y = yScale(t);
                    svg += `<line x1="${padL}" y1="${y}" x2="${W - padR}" y2="${y}" stroke="#e8e4de" stroke-width="1" stroke-dasharray="2,3"/>`;
                    let label;
                    const av = Math.abs(t);
                    if (payload.unit === '%') label = t.toFixed(1) + '%';
                    else if (payload.unit === 'LCU/USD') label = t.toFixed(0);
                    else if (av >= 1e9) label = (t / 1e9).toFixed(1) + 'B';
                    else if (av >= 1e6) label = (t / 1e6).toFixed(0) + 'M';
                    else if (av >= 1e3) label = (t / 1e3).toFixed(0) + 'K';
                    else label = t.toFixed(0);
                    svg += `<text x="${padL - 4}" y="${y + 3}" text-anchor="end" font-size="8" fill="#a09d95">${label}</text>`;
                });

                // Build path segments: actuals (solid) and projection (dashed).
                // Find the boundary between actual and projection — first
                // index where is_projected is true.
                let firstProjIdx = -1;
                for (let i = 0; i < isProj.length; i++) {
                    if (isProj[i]) { firstProjIdx = i; break; }
                }
                const actualEnd = firstProjIdx === -1 ? values.length : firstProjIdx;

                // Solid actual line
                if (actualEnd > 1) {
                    let d = '';
                    for (let i = 0; i < actualEnd; i++) {
                        d += (i === 0 ? 'M' : 'L') + xScale(i).toFixed(1) + ',' + yScale(values[i]).toFixed(1) + ' ';
                    }
                    svg += `<path d="${d.trim()}" stroke="${payload.color}" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/>`;
                }

                // Dashed projection line — connects from last actual through projections
                if (firstProjIdx >= 0 && firstProjIdx > 0) {
                    let d = 'M' + xScale(firstProjIdx - 1).toFixed(1) + ',' + yScale(values[firstProjIdx - 1]).toFixed(1) + ' ';
                    for (let i = firstProjIdx; i < values.length; i++) {
                        d += 'L' + xScale(i).toFixed(1) + ',' + yScale(values[i]).toFixed(1) + ' ';
                    }
                    svg += `<path d="${d.trim()}" stroke="${payload.proj_color}" stroke-width="2" stroke-dasharray="4,3" fill="none" stroke-linecap="round" stroke-linejoin="round"/>`;
                }

                // Dots — actuals filled, projections hollow
                for (let i = 0; i < values.length; i++) {
                    const cx = xScale(i), cy = yScale(values[i]);
                    if (isProj[i]) {
                        svg += `<circle cx="${cx}" cy="${cy}" r="2.5" fill="white" stroke="${payload.proj_color}" stroke-width="1.5"><title>${labels[i]} (proj.): ${values[i].toFixed(2)}</title></circle>`;
                    } else {
                        svg += `<circle cx="${cx}" cy="${cy}" r="2.5" fill="${payload.color}"><title>${labels[i]}: ${values[i].toFixed(2)}</title></circle>`;
                    }
                }

                // X-axis year labels — first, middle, last only to keep tidy
                const xTickIdx = [0, Math.floor(labels.length / 2), labels.length - 1];
                xTickIdx.forEach(function (i) {
                    const cx = xScale(i);
                    svg += `<text x="${cx}" y="${H - 5}" text-anchor="middle" font-size="9" fill="#5a6a70">${labels[i]}</text>`;
                });

                // Vertical separator at the actual/projection boundary
                if (firstProjIdx > 0) {
                    const xSep = (xScale(firstProjIdx - 1) + xScale(firstProjIdx)) / 2;
                    svg += `<line x1="${xSep}" y1="${padT}" x2="${xSep}" y2="${padT + innerH}" stroke="#cbc7be" stroke-width="1" stroke-dasharray="1,2"/>`;
                    svg += `<text x="${xSep + 2}" y="${padT + 8}" font-size="7.5" fill="#a09d95" font-style="italic">proj.</text>`;
                }

                svg += '</svg>';
                root.innerHTML = svg;
            });
        })();
        </script>
    @endif
@endsection
