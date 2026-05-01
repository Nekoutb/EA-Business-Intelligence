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
            $tiao = $data['tiao']        ?? [];
            $tax  = $data['income_tax']  ?? [];
            $src  = $data['data_source'] ?? [];
        @endphp

        {{-- ── Sandbox row: BEAC TIAO + Country Income Tax Rate ───────────
             Two side-by-side cards, both pulled from the same physical
             country selection. TIAO is region-wide (CEMAC); income tax
             switches per country. --}}
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-6">

            {{-- BEAC TIAO --}}
            <div class="bg-white border border-[#e8e4de] rounded-xl p-5">
                <div class="flex items-center gap-2 mb-1">
                    <div class="w-1 h-4 rounded bg-brand-500"></div>
                    <h2 class="text-xs font-semibold uppercase tracking-wider text-[#5a6a70]">BEAC TIAO Rate</h2>
                    <span class="text-[9.5px] uppercase tracking-wider rounded px-1.5 py-0.5 border
                        {{ ($tiao['source'] ?? 'config') === 'live'
                            ? 'bg-sage-50 text-sage-700 border-sage-200'
                            : 'bg-warm-50 text-warm-700 border-warm-200' }}">
                        {{ ($tiao['source'] ?? 'config') === 'live' ? 'LIVE · scraped' : 'CONFIG · fallback' }}
                    </span>
                </div>
                <div class="text-3xl font-bold text-[#2d3748] mt-2 tabular-nums">
                    {{ number_format((float)($tiao['rate'] ?? 0), 2) }}<span class="text-lg ml-0.5">%</span>
                </div>
                <div class="text-[11px] text-[#7c7466] mt-1">
                    As of <strong>{{ $tiao['as_of'] ?? '—' }}</strong> ·
                    Central-bank policy rate · CEMAC-wide
                </div>
                <div class="text-[10.5px] text-[#7c7466] mt-2 leading-relaxed">
                    {{ $tiao['description'] ?? '' }}
                </div>
                <div class="text-[10px] text-[#a09d95] mt-2 italic leading-snug">
                    {{ $tiao['note'] ?? '' }}
                    Source: <a href="{{ $tiao['source_url'] ?? '#' }}" target="_blank" rel="noopener" class="text-brand-700 hover:text-brand-900 underline decoration-dotted">{{ parse_url($tiao['source_url'] ?? '', PHP_URL_HOST) ?: 'beac.int' }}</a>.
                </div>
            </div>

            {{-- Statutory Country Income Tax Rate --}}
            <div class="bg-white border border-[#e8e4de] rounded-xl p-5">
                <div class="flex items-center gap-2 mb-1">
                    <div class="w-1 h-4 rounded bg-coral-500"></div>
                    <h2 class="text-xs font-semibold uppercase tracking-wider text-[#5a6a70]">
                        Corporate Income Tax — {{ $data['country_label'] }}
                    </h2>
                    <span class="text-[9.5px] uppercase tracking-wider rounded px-1.5 py-0.5 border bg-warm-50 text-warm-700 border-warm-200">
                        STATUTORY
                    </span>
                </div>
                <div class="text-3xl font-bold text-[#2d3748] mt-2 tabular-nums">
                    @if (($tax['rate'] ?? null) !== null)
                        {{ number_format((float)$tax['rate'], 1) }}<span class="text-lg ml-0.5">%</span>
                    @else
                        <span class="text-[#a09d95]">—</span>
                    @endif
                </div>
                <div class="text-[11px] text-[#7c7466] mt-1">
                    @if (!empty($tax['year']))
                        For tax year <strong>{{ $tax['year'] }}</strong> · Source: {{ $tax['source'] ?? 'config' }}
                    @else
                        Not configured for this country
                    @endif
                </div>
                <div class="text-[10.5px] text-[#7c7466] mt-2 leading-relaxed">
                    {{ $tax['note'] ?? '' }}
                </div>
                <div class="text-[10px] text-[#a09d95] mt-2 italic leading-snug">
                    Statutory rate maintained in <code class="text-[10px] bg-[#faf8f5] border border-[#ece7df] rounded px-1 py-0.5">config/macro.php</code> — no free public API for global tax rates. Update when each country's CGI changes.
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
                            'unit_short'   => $meta['unit_short'] ?? '',
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

                                @if (!$ind['pending'] && !empty($ind['projection_source']))
                                    @php
                                        $projSrc = $ind['projection_source'];
                                        $projTone = $projSrc === 'worldbank'
                                            ? 'bg-sage-50 text-sage-700 border-sage-200'
                                            : 'bg-warm-50 text-warm-700 border-warm-200';
                                        $projLabel = $projSrc === 'worldbank'
                                            ? 'Projection: World Bank'
                                            : 'Projection: linear (no WB forecast)';
                                    @endphp
                                    <div class="mt-1.5 inline-flex items-center text-[9.5px] uppercase tracking-wider rounded px-1.5 py-0.5 border {{ $projTone }} font-semibold">
                                        {{ $projLabel }}
                                    </div>
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
                    <strong class="text-[#1e3d44]">Goods (ex-fuel) derivation</strong> — Imports = total merchandise (<code class="text-[10px] bg-[#faf8f5] border border-[#ece7df] rounded px-1 py-0.5">TM.VAL.MRCH.CD.WT</code>) × (1 − fuel-share <code class="text-[10px] bg-[#faf8f5] border border-[#ece7df] rounded px-1 py-0.5">TM.VAL.FUEL.ZS.UN</code>); Exports = the same with <code class="text-[10px] bg-[#faf8f5] border border-[#ece7df] rounded px-1 py-0.5">TX.</code> codes. Petroleum is excluded because crude oil moves through dedicated terminals / pipelines, bypassing the customer's scanning operation. Services are excluded entirely — they don't produce physical scans.
                </div>
                <div>
                    <strong class="text-[#1e3d44]">BEAC TIAO</strong> — Sourced live from
                    <a href="https://www.beac.int" target="_blank" rel="noopener" class="text-brand-700 hover:text-brand-900 underline decoration-dotted">beac.int</a>.
                    The scrape anchors on the full French label "Taux d'intérêt des appels d'offres" in the homepage interest-rate widget (verified Apr 2026). Cached 6 h, so a Comité de Politique Monétaire change reflects within at most 6 hours of BEAC updating their site. On scrape failure, falls back to <code class="text-[10px] bg-[#faf8f5] border border-[#ece7df] rounded px-1 py-0.5">BEAC_TIAO_RATE</code> /
                    <code class="text-[10px] bg-[#faf8f5] border border-[#ece7df] rounded px-1 py-0.5">BEAC_TIAO_AS_OF</code> in env.
                </div>
                <div>
                    <strong class="text-[#1e3d44]">Projection</strong> — Each indicator first asks World Bank for forward years (some series have GEP forecasts attached). If the World Bank has no forecast, the chart falls back to a linear extrapolation of the 5-year trend; the badge on each card says which path was used. The dashed line + hollow markers always mark forward / projected points.
                </div>
                <div>
                    <strong class="text-[#1e3d44]">Statutory income tax</strong> — Maintained in
                    <code class="text-[10px] bg-[#faf8f5] border border-[#ece7df] rounded px-1 py-0.5">config/macro.php</code>
                    because no free public API exists for global tax rates. Each entry references the source CGI / tax code.
                </div>
                <div>
                    Data refreshed: {{ \Carbon\Carbon::parse($data['fetched_at'])->diffForHumans() }} ·
                    Indicator series cached 24 h server-side · BEAC scrape cached 6 h.
                </div>
            </div>
        </div>

        {{-- Vanilla SVG mini line-chart renderer with visible value
             labels at every point. Pure JS, no library dependency. --}}
        <script>
        (function () {
            // Format a value compactly enough to fit above a data point
            // without overlapping the next point's label. Currency-style
            // uses K/M/B; percent shows one decimal; LCU/USD as integer.
            function fmtValue(v, unit) {
                const av = Math.abs(v);
                if (unit === '%')         return v.toFixed(1) + '%';
                if (unit === 'LCU/USD')   return v.toFixed(0);
                if (av >= 1e9)            return (v / 1e9).toFixed(1) + 'B';
                if (av >= 1e6)            return (v / 1e6).toFixed(0) + 'M';
                if (av >= 1e3)            return (v / 1e3).toFixed(0) + 'K';
                return v.toFixed(0);
            }

            document.querySelectorAll('[id^="macro-chart-"]').forEach(function (root) {
                let payload;
                try { payload = JSON.parse(root.dataset.chart || '{}'); }
                catch (e) { return; }

                const labels = payload.labels || [];
                const values = payload.values || [];
                const isProj = payload.is_projected || [];
                if (labels.length < 2) return;

                // Slightly taller (110 vs 90) to give the value labels
                // breathing room above the data points; also wider left
                // padding for axis numbers.
                const W = 340, H = 120, padL = 32, padR = 12, padT = 18, padB = 26;
                const innerW = W - padL - padR, innerH = H - padT - padB;

                const yMin = Math.min.apply(null, values);
                const yMax = Math.max.apply(null, values);
                // Extra top headroom so the value-label tags don't clip.
                const yPad = (yMax - yMin) * 0.18 || (Math.abs(yMax) * 0.18 || 1);
                const yLo = yMin - yPad * 0.4;
                const yHi = yMax + yPad;
                const yRange = (yHi - yLo) || 1;

                const xScale = i => padL + (i * innerW) / (labels.length - 1);
                const yScale = v => padT + innerH - ((v - yLo) / yRange) * innerH;

                let svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${W} ${H}" preserveAspectRatio="xMidYMid meet" class="w-full h-auto" style="font-family:inherit;">`;

                // Y-axis grid (min, mid, max) with axis-tick labels
                const ticks = [yMin, (yMin + yMax) / 2, yMax];
                ticks.forEach(function (t) {
                    const y = yScale(t);
                    svg += `<line x1="${padL}" y1="${y}" x2="${W - padR}" y2="${y}" stroke="#e8e4de" stroke-width="1" stroke-dasharray="2,3"/>`;
                    svg += `<text x="${padL - 4}" y="${y + 3}" text-anchor="end" font-size="8" fill="#a09d95">${fmtValue(t, payload.unit)}</text>`;
                });

                // Find boundary between actual and projection
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

                // Dashed projection line, anchored to last actual
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
                        svg += `<circle cx="${cx}" cy="${cy}" r="3" fill="white" stroke="${payload.proj_color}" stroke-width="1.5"><title>${labels[i]} (proj.): ${fmtValue(values[i], payload.unit)}</title></circle>`;
                    } else {
                        svg += `<circle cx="${cx}" cy="${cy}" r="3" fill="${payload.color}"><title>${labels[i]}: ${fmtValue(values[i], payload.unit)}</title></circle>`;
                    }
                }

                // ── Visible value labels at every data point ────────────
                // Position above the point unless the label would clip the
                // top of the chart, in which case flip below. White stroke
                // around the text gives a "halo" effect against any line
                // that passes underneath.
                for (let i = 0; i < values.length; i++) {
                    const cx = xScale(i);
                    const cy = yScale(values[i]);
                    const labelText = fmtValue(values[i], payload.unit);
                    const above = cy > padT + 14;
                    const ly = above ? cy - 6 : cy + 12;
                    const fill = isProj[i] ? '#7c7466' : '#1e3d44';
                    const fontStyle = isProj[i] ? 'italic' : 'normal';
                    // White halo / outline first, then the actual label on top.
                    svg += `<text x="${cx}" y="${ly}" text-anchor="middle" font-size="9" font-weight="600" fill="white" stroke="white" stroke-width="3" stroke-linejoin="round" paint-order="stroke" font-style="${fontStyle}">${labelText}</text>`;
                    svg += `<text x="${cx}" y="${ly}" text-anchor="middle" font-size="9" font-weight="600" fill="${fill}" font-style="${fontStyle}">${labelText}</text>`;
                }

                // X-axis year labels at every point (now that the chart
                // is wider, all 7 fit cleanly).
                for (let i = 0; i < labels.length; i++) {
                    const cx = xScale(i);
                    const isProjLabel = isProj[i];
                    const fill = isProjLabel ? '#a09d95' : '#5a6a70';
                    const style = isProjLabel ? 'italic' : 'normal';
                    svg += `<text x="${cx}" y="${H - 8}" text-anchor="middle" font-size="9" fill="${fill}" font-style="${style}">${labels[i]}</text>`;
                }

                // Vertical separator at the actual/projection boundary
                if (firstProjIdx > 0) {
                    const xSep = (xScale(firstProjIdx - 1) + xScale(firstProjIdx)) / 2;
                    svg += `<line x1="${xSep}" y1="${padT}" x2="${xSep}" y2="${padT + innerH}" stroke="#cbc7be" stroke-width="1" stroke-dasharray="1,2"/>`;
                    svg += `<text x="${xSep + 3}" y="${padT - 4}" font-size="7.5" fill="#a09d95" font-style="italic">proj.</text>`;
                }

                svg += '</svg>';
                root.innerHTML = svg;
            });
        })();
        </script>
    @endif
@endsection
