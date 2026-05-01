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
            $tiao  = $data['tiao']              ?? [];
            $tax   = $data['income_tax']        ?? [];
            $src   = $data['data_source']       ?? [];
            $fxXe  = $data['fx_xe']             ?? [];
            $comp  = $data['trade_composition'] ?? [];
            $tps   = $data['top_partners']      ?? [];
        @endphp

        {{-- ── FX explainer + live USD/XAF card ───────────────────────────
             Educates the operator on how XAF/USD movement transmits to
             trade volumes, and shows the current spot rate from xe.com. --}}
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-6">
            <div class="md:col-span-2 bg-white border border-[#e8e4de] rounded-xl p-5">
                <div class="flex items-center gap-2 mb-2">
                    <div class="w-1 h-4 rounded bg-warm-500"></div>
                    <h2 class="text-xs font-semibold uppercase tracking-wider text-[#5a6a70]">XAF / USD — How exchange-rate moves transmit to trade volume</h2>
                </div>
                <div class="text-[12px] text-[#5a6a70] leading-relaxed space-y-2">
                    <div>
                        <strong class="text-sage-700">XAF appreciates vs USD</strong> → 1 USD buys fewer XAF.
                        Foreign goods become <strong>cheaper</strong> in local currency, so <strong>imports rise</strong>.
                        But exports priced in USD convert into fewer XAF, so <strong>exporters lose margin</strong> and outbound trade can soften.
                    </div>
                    <div>
                        <strong class="text-coral-700">XAF depreciates vs USD</strong> → 1 USD buys more XAF.
                        Imports get <strong>more expensive</strong>; volumes typically fall.
                        Exports become <strong>more competitive</strong> globally, exporters earn more XAF per USD shipped, so <strong>outbound trade benefits</strong>.
                    </div>
                    <div class="pt-2 border-t border-[#f1ede5]">
                        <strong class="text-[#1e3d44]">For a port scanning operation</strong>, both directions of trade pass through scans, so what matters most is <strong>total trade volume</strong>, not the direction of the imbalance. Sustained currency volatility usually depresses overall trade, so <strong>stability is more favourable to scan revenue than any particular direction of the rate</strong>.
                    </div>
                    <div class="pt-2 border-t border-[#f1ede5] text-[#7c7466]">
                        <strong class="text-[#1e3d44]">Note on XAF specifically:</strong> XAF is hard-pegged to EUR at <code class="text-[10px] bg-[#faf8f5] border border-[#ece7df] rounded px-1 py-0.5">655.957 XAF / EUR</code> since 1999, so XAF/USD movement is mechanically EUR/USD movement with a fixed multiplier. The effective driver is ECB / Fed monetary policy divergence, not local Cameroonian conditions.
                    </div>
                </div>
            </div>

            {{-- Live FX card --}}
            <div class="bg-white border border-[#e8e4de] rounded-xl p-5">
                <div class="flex items-center gap-2 mb-1">
                    <div class="w-1 h-4 rounded bg-sage-500"></div>
                    <h2 class="text-xs font-semibold uppercase tracking-wider text-[#5a6a70]">USD / XAF Live</h2>
                    @php
                        $fxLive = is_string($fxXe['source'] ?? '') && str_contains($fxXe['source'], 'xe.com');
                    @endphp
                    <span class="text-[9.5px] uppercase tracking-wider rounded px-1.5 py-0.5 border
                        {{ $fxLive ? 'bg-sage-50 text-sage-700 border-sage-200' : 'bg-warm-50 text-warm-700 border-warm-200' }}">
                        {{ $fxLive ? 'XE · LIVE' : ($fxXe['rate'] ?? null ? 'ECB · derived' : 'unavailable') }}
                    </span>
                </div>
                @if (($fxXe['rate'] ?? null) !== null)
                    <div class="flex items-baseline gap-2 mt-2">
                        <div class="text-3xl font-bold text-[#2d3748] tabular-nums">{{ number_format((float)$fxXe['rate'], 2) }}</div>
                        <div class="text-[11px] text-[#7c7466]">XAF per USD</div>
                    </div>
                    @if (!empty($fxXe['as_of']))
                        <div class="text-[10.5px] text-[#7c7466] mt-1">As of {{ \Carbon\Carbon::parse($fxXe['as_of'])->diffForHumans() }}</div>
                    @endif
                @else
                    <div class="text-2xl font-bold text-[#a09d95] mt-2 tabular-nums">— —</div>
                    <div class="text-[10.5px] text-coral-700 mt-1">Live FX unavailable</div>
                @endif
                <div class="text-[10px] text-[#a09d95] mt-2 italic leading-snug">
                    Source: <a href="{{ $fxXe['source_url'] ?? '#' }}" target="_blank" rel="noopener" class="text-brand-700 hover:text-brand-900 underline decoration-dotted">{{ parse_url($fxXe['source_url'] ?? '', PHP_URL_HOST) ?: 'xe.com' }}</a>.
                    {{ $fxXe['note'] ?? '' }}
                </div>
            </div>
        </div>

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

        {{-- ── Top 5 by nature of goods (imports + exports side by side) ── --}}
        @php
            $hasComp = !empty($comp['imports']) || !empty($comp['exports']);
        @endphp
        @if ($hasComp)
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-6">
            @foreach ([
                ['title' => 'Top 5 — Imports by nature of goods', 'rows' => $comp['imports'] ?? [], 'colour' => 'sage'],
                ['title' => 'Top 5 — Exports by nature of goods', 'rows' => $comp['exports'] ?? [], 'colour' => 'brand'],
            ] as $panel)
                @php
                    $rows = array_slice($panel['rows'], 0, 5);
                    $maxPct = empty($rows) ? 1 : max(array_map(fn($r) => $r['pct'], $rows));
                    $maxPct = $maxPct > 0 ? $maxPct : 1;
                    $barClass = $panel['colour'] === 'sage' ? 'bg-sage-400' : 'bg-brand-400';
                @endphp
                <div class="bg-white border border-[#e8e4de] rounded-xl p-5">
                    <div class="flex items-center justify-between mb-3 gap-2">
                        <h3 class="text-sm font-semibold text-[#1e3d44]">{{ $panel['title'] }}</h3>
                        @if (!empty($rows[0]['year']))
                            <span class="text-[10px] uppercase tracking-wider text-[#a09d95] font-medium">{{ $rows[0]['year'] }}</span>
                        @endif
                    </div>
                    @if (empty($rows))
                        <div class="text-[12px] text-[#7c7466] py-3">No composition data available for {{ $data['country_label'] }} in the recent window.</div>
                    @else
                        <div class="space-y-2">
                            @foreach ($rows as $r)
                                <div class="flex items-center gap-3 text-[12px]">
                                    <div class="w-44 text-[#2d3748] flex items-center gap-1.5 shrink-0">
                                        <span class="text-base">{{ $r['icon'] }}</span>
                                        <span>{{ $r['label'] }}</span>
                                    </div>
                                    <div class="flex-1 bg-[#faf8f5] rounded h-5 relative overflow-hidden border border-[#ece7df]">
                                        <div class="absolute inset-y-0 left-0 {{ $barClass }} opacity-70" style="width: {{ (int)round(100 * $r['pct'] / $maxPct) }}%"></div>
                                    </div>
                                    <div class="w-16 text-right tabular-nums font-medium text-[#2d3748]">{{ number_format((float)$r['pct'], 1) }}%</div>
                                </div>
                            @endforeach
                        </div>
                        <div class="text-[10.5px] text-[#7c7466] mt-3 italic">% of total {{ $panel['colour'] === 'sage' ? 'merchandise imports' : 'merchandise exports' }} (World Bank trade-composition indicators).</div>
                    @endif
                </div>
            @endforeach
        </div>
        @endif

        {{-- ── Top trading partners + per-partner sandbox ────────────────── --}}
        @php
            $impPartners = $tps['imports_from'] ?? [];
            $expPartners = $tps['exports_to']   ?? [];
            $hasPartners = !empty($impPartners) || !empty($expPartners);
        @endphp
        @if ($hasPartners)
        <div class="bg-white border border-[#e8e4de] rounded-xl p-5 mb-6">
            <div class="flex items-center justify-between mb-3 gap-2 flex-wrap">
                <div>
                    <h3 class="text-sm font-semibold text-[#1e3d44]">Top trading partners — {{ $data['country_label'] }}</h3>
                    @if (!empty($tps['as_of_year']))
                        <p class="text-[11px] text-[#7c7466] mt-0.5">As-of {{ $tps['as_of_year'] }} · Source: {{ $tps['source'] ?? 'config' }}</p>
                    @endif
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                @foreach ([
                    ['title' => 'Top 5 — Import sources (where goods come FROM)', 'rows' => $impPartners, 'badge' => 'IMPORT', 'colour' => 'sage'],
                    ['title' => 'Top 5 — Export destinations (where goods GO TO)', 'rows' => $expPartners, 'badge' => 'EXPORT', 'colour' => 'brand'],
                ] as $panel)
                    @php
                        $maxPct = empty($panel['rows']) ? 1 : max(array_map(fn($r) => $r['pct'], $panel['rows']));
                        $maxPct = $maxPct > 0 ? $maxPct : 1;
                        $barClass    = $panel['colour'] === 'sage' ? 'bg-sage-400' : 'bg-brand-400';
                        $badgeClass  = $panel['colour'] === 'sage' ? 'bg-sage-50 text-sage-700 border-sage-200' : 'bg-brand-50 text-brand-700 border-brand-200';
                    @endphp
                    <div>
                        <h4 class="text-xs font-semibold text-[#1e3d44] mb-2 flex items-center gap-2">
                            <span class="text-[9.5px] uppercase tracking-wider rounded px-1.5 py-0.5 border {{ $badgeClass }}">{{ $panel['badge'] }}</span>
                            {{ $panel['title'] }}
                        </h4>
                        @if (empty($panel['rows']))
                            <div class="text-[12px] text-[#7c7466] italic py-2">No partner data configured.</div>
                        @else
                            <div class="space-y-1.5">
                                @foreach ($panel['rows'] as $i => $r)
                                    <div class="flex items-center gap-2 text-[12px]">
                                        <div class="w-7 text-right tabular-nums text-[#a09d95] shrink-0">#{{ $i + 1 }}</div>
                                        <div class="w-32 text-[#2d3748] font-medium shrink-0 truncate" title="{{ $r['country_label'] ?? $r['name'] }}">{{ $r['country_label'] ?? $r['name'] }}</div>
                                        <div class="flex-1 bg-[#faf8f5] rounded h-4 relative overflow-hidden border border-[#ece7df]">
                                            <div class="absolute inset-y-0 left-0 {{ $barClass }} opacity-70" style="width: {{ (int)round(100 * $r['pct'] / $maxPct) }}%"></div>
                                        </div>
                                        <div class="w-12 text-right tabular-nums font-medium text-[#2d3748]">{{ number_format((float)$r['pct'], 1) }}%</div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>

            {{-- Per-partner sandbox: combined view of unique countries
                 (a country can be both top import source and top export
                 destination — China for Cameroon is a good example). --}}
            @php
                $allPartners = [];
                foreach ($impPartners as $r) {
                    $cc = $r['country'] ?? '';
                    if (!isset($allPartners[$cc])) $allPartners[$cc] = $r + ['as_import' => null, 'as_export' => null, 'goods_import' => '', 'goods_export' => ''];
                    $allPartners[$cc]['as_import']    = $r['pct']   ?? null;
                    $allPartners[$cc]['goods_import'] = $r['goods'] ?? '';
                }
                foreach ($expPartners as $r) {
                    $cc = $r['country'] ?? '';
                    if (!isset($allPartners[$cc])) $allPartners[$cc] = $r + ['as_import' => null, 'as_export' => null, 'goods_import' => '', 'goods_export' => ''];
                    $allPartners[$cc]['as_export']    = $r['pct']   ?? null;
                    $allPartners[$cc]['goods_export'] = $r['goods'] ?? '';
                }
                // Order: highest combined share first.
                usort($allPartners, function ($a, $b) {
                    $sa = (float)($a['as_import'] ?? 0) + (float)($a['as_export'] ?? 0);
                    $sb = (float)($b['as_import'] ?? 0) + (float)($b['as_export'] ?? 0);
                    return $sb <=> $sa;
                });
            @endphp

            @if (!empty($allPartners))
            <div class="border-t border-[#f1ede5] pt-4">
                <h4 class="text-xs font-semibold text-[#1e3d44] mb-3">Per-partner sandbox — pertinent trade context</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
                    @foreach ($allPartners as $p)
                        @php
                            $bothWays = $p['as_import'] !== null && $p['as_export'] !== null;
                        @endphp
                        <div class="bg-[#fcfbf8] border border-[#f1ede5] rounded-lg p-3">
                            <div class="flex items-start justify-between gap-2 mb-2">
                                <div>
                                    <div class="text-[13px] font-semibold text-[#1e3d44]">{{ $p['country_label'] ?? $p['name'] }}</div>
                                    <div class="flex items-center gap-1 mt-0.5 flex-wrap">
                                        @if ($p['as_import'] !== null)
                                            <span class="text-[9.5px] uppercase tracking-wider rounded px-1.5 py-0.5 border bg-sage-50 text-sage-700 border-sage-200">IMPORT · {{ number_format((float)$p['as_import'], 1) }}%</span>
                                        @endif
                                        @if ($p['as_export'] !== null)
                                            <span class="text-[9.5px] uppercase tracking-wider rounded px-1.5 py-0.5 border bg-brand-50 text-brand-700 border-brand-200">EXPORT · {{ number_format((float)$p['as_export'], 1) }}%</span>
                                        @endif
                                        @if ($bothWays)
                                            <span class="text-[9.5px] uppercase tracking-wider rounded px-1.5 py-0.5 border bg-warm-50 text-warm-700 border-warm-200">BOTH WAYS</span>
                                        @endif
                                    </div>
                                </div>
                                @if (($p['tax_rate'] ?? null) !== null)
                                    <div class="text-right">
                                        <div class="text-[9px] uppercase tracking-wider text-[#a09d95] font-medium">CIT</div>
                                        <div class="text-sm font-bold text-[#2d3748] tabular-nums">{{ number_format((float)$p['tax_rate'], 0) }}%</div>
                                    </div>
                                @endif
                            </div>

                            @if (!empty($p['goods_import']))
                                <div class="text-[11px] text-[#5a6a70] mb-1.5">
                                    <strong class="text-sage-700">Imports from:</strong>
                                    <span class="text-[#7c7466]">{{ $p['goods_import'] }}</span>
                                </div>
                            @endif
                            @if (!empty($p['goods_export']))
                                <div class="text-[11px] text-[#5a6a70]">
                                    <strong class="text-brand-700">Exports to:</strong>
                                    <span class="text-[#7c7466]">{{ $p['goods_export'] }}</span>
                                </div>
                            @endif

                            {{-- Quick-jump to the partner's own macro page --}}
                            @if (isset(\App\Services\MacroEconomicsService::COUNTRIES[$p['country'] ?? '']))
                                <div class="mt-2 pt-2 border-t border-[#f1ede5]">
                                    <a href="{{ tenant_url('/macro-economics?country=' . $p['country']) }}"
                                       class="text-[10.5px] font-medium text-brand-700 hover:text-brand-900 inline-flex items-center gap-1">
                                        View {{ $p['country_label'] ?? $p['name'] }} macro →
                                    </a>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
            @endif

            @if (!empty($tps['note']))
                <div class="text-[10.5px] text-[#a09d95] mt-3 italic">{{ $tps['note'] }}</div>
            @endif
        </div>
        @endif

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
                    <strong class="text-[#1e3d44]">USD/XAF live rate</strong> — Scraped from
                    <a href="https://www.xe.com" target="_blank" rel="noopener" class="text-brand-700 hover:text-brand-900 underline decoration-dotted">xe.com</a>
                    (cached 1 h). Falls back to ECB-derived rate via
                    <a href="https://www.frankfurter.app" target="_blank" rel="noopener" class="text-brand-700 hover:text-brand-900 underline decoration-dotted">frankfurter.app</a>
                    × hard 655.957 XAF/EUR peg if xe.com is unreachable.
                </div>
                <div>
                    <strong class="text-[#1e3d44]">Trade composition</strong> (top-5 by nature of goods) — World Bank
                    <code class="text-[10px] bg-[#faf8f5] border border-[#ece7df] rounded px-1 py-0.5">TX/TM.VAL.{FOOD,AGRI,FUEL,MMTL,MANF}.ZS.UN</code>
                    — % of merchandise total per category. SITC categories are coarse but cover all merchandise without double-counting.
                </div>
                <div>
                    <strong class="text-[#1e3d44]">Top trading partners</strong> — Curated from each country's national trade-statistics agency (most recent full year). Maintained in
                    <code class="text-[10px] bg-[#faf8f5] border border-[#ece7df] rounded px-1 py-0.5">config/macro.php</code>.
                    The free World Bank API doesn't expose bilateral trade flows ergonomically; UN Comtrade / WITS is the upgrade path for live data.
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
