@extends('layouts.app')
@section('title', 'Operations')

@section('header')
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 mb-1">
                <span class="text-[10px] uppercase tracking-[0.35em] text-[#8a8578] font-medium">{{ $periodLabel ?? 'Operations' }} · {{ ea_last_sync_label() }}</span>
            </div>
            <h1 class="text-2xl font-bold text-[#1e3d44]">Operations</h1>
            <div class="text-xs text-[#7c7466] mt-1">Container &amp; logistics analytics — sourced from your Odoo <em>Logistique</em> module.</div>
        </div>
    </div>
@endsection

@section('content')
    <x-period-filter :period="$period" :options="[['ytd','YTD'],['mtd','MTD'],['month','Month']]" />

    @if ($error)
        {{-- Service-layer failure — usually a connection issue (Odoo
             unreachable, creds missing). The cards still render below in
             pending state so the page doesn't 500. --}}
        <div class="bg-coral-50 border border-coral-200 text-coral-800 rounded-xl p-4 text-sm mb-6">
            <strong>Could not reach Odoo:</strong> {{ $error }}
            <div class="text-xs mt-1 text-coral-600">Verify credentials in <a href="{{ tenant_url('/settings') }}" class="underline">Settings</a> or run the schema probe at <code>/admin/tenants/&lt;id&gt;/odoo-probe</code>.</div>
        </div>
    @endif

    @if (!$ops || !empty($ops['pending_overall']))
        {{-- Scope-in-progress notice — shown until at least one KPI helper
             flips pending=false. Disappears automatically as the analyzer
             gets wired up KPI by KPI. --}}
        <div class="bg-warm-50 border border-warm-200 rounded-xl p-5 mb-6">
            <div class="flex items-start gap-3">
                <div class="w-8 h-8 rounded-lg bg-warm-100 border border-warm-200 flex items-center justify-center shrink-0">
                    <svg class="w-4 h-4 text-warm-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8v4l3 2M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20z"/></svg>
                </div>
                <div class="flex-1">
                    <div class="text-sm font-semibold text-[#7a5a1a]">Scope in progress</div>
                    <p class="text-xs text-[#7c7466] mt-1 leading-relaxed">
                        The KPIs below are being mapped against your Odoo <strong>Logistique</strong> schema (containers, scans, manifestes, EDI). Once the schema introspection is back, each card will be wired up to live data and the placeholder badges will go away.
                    </p>
                </div>
            </div>
        </div>
    @endif

    {{-- ── KPI metadata ────────────────────────────────────────────────── --}}
    @php
        // KPI plan — static metadata (title, icon, source-formula label, palette).
        // Each entry's `key` is looked up in $ops['kpis'][$key] for the live
        // value. Adding a new KPI here is a 2-step: drop an entry in this
        // array + add the matching helper in OperationsService::analyze().
        //
        // Source formulas reflect the actual AW Cargo schema discovered via
        // the /admin/tenants/{id}/odoo-probe diagnostic.
        $proposedKpis = [
            [
                'group' => 'Throughput',
                'cards' => [
                    ['key' => 'vessel_calls',       'title' => 'Vessel Calls',       'source' => 'count(aw_cargo.stopover)',                       'icon' => 'M3 18l3-3 3 3 3-3 3 3 3-3 3 3M3 12c4-2 6-2 9 0s5 2 9 0M5 8h14',     'color' => 'brand'],
                    ['key' => 'containers_handled', 'title' => 'Containers Handled', 'source' => 'count(aw_cargo.equipment) in period',            'icon' => 'M4 7v10a2 2 0 002 2h12a2 2 0 002-2V7M4 7l8-4 8 4M4 7h16M9 11h6M9 15h6', 'color' => 'sage'],
                    ['key' => 'containers_per_day', 'title' => 'Containers per Day', 'source' => 'handled ÷ working_days · 6-day basis',           'icon' => 'M12 8v4l3 2M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20z',                'color' => 'warm'],
                ],
            ],
            [
                'group' => 'Scan & Quality',
                'cards' => [
                    ['key' => 'containers_scanned',  'title' => 'Containers Scanned',  'source' => 'count(scanned=true) by scanned_date',            'icon' => 'M4 6h16M4 10h16M4 14h16M4 18h16',                                                'color' => 'brand'],
                    ['key' => 'scan_rate',           'title' => 'Scan Rate',           'source' => 'scanned ÷ handled in period',                    'icon' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',                                  'color' => 'sage'],
                    ['key' => 'inspection_rate',     'title' => 'Inspection Rate',     'source' => "count(inspected != 'not_done') ÷ handled",       'icon' => "M11 17a2 2 0 100-4 2 2 0 000 4zM10 12V8m1 9h.01M5 4h14a2 2 0 012 2v12a2 2 0 01-2 2H5a2 2 0 01-2-2V6a2 2 0 012-2z", 'color' => 'coral'],
                    ['key' => 'median_time_to_scan', 'title' => 'Median Time-to-Scan', 'source' => 'median(scanned_date - create_date)',             'icon' => 'M12 8v4l3 2M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20z',                            'color' => 'warm'],
                    ['key' => 'same_day_scan_rate',  'title' => '% Scanned Same-Day',  'source' => 'count(time_to_scan ≤ 24h) ÷ scanned',            'icon' => 'M19 14l-7 7m0 0l-7-7m7 7V3',                                                     'color' => 'sage'],
                ],
            ],
            [
                'group' => 'Mix',
                'cards' => [
                    ['key' => 'mix_by_size',    'title' => "Mix by Size (20'/40'/45')", 'source' => 'group_by(equipment.size)',                       'icon' => 'M4 4h16v6H4zM4 14h16v6H4z',          'color' => 'brand'],
                    ['key' => 'full_empty_mix', 'title' => 'Laden vs Empty',            'source' => "group_by(equipment.full = 'yes')",               'icon' => 'M5 5h14v14H5zM5 12h14',              'color' => 'sage'],
                    ['key' => 'direction_mix',  'title' => 'Import vs Export',          'source' => 'group_by(cargo.direction) weighted by containers', 'icon' => 'M3 12h18M12 5l7 7-7 7M5 5l-2 7 2 7',  'color' => 'warm'],
                ],
            ],
            [
                'group' => 'Scan Revenue',
                'cards' => [
                    ['key' => 'scan_revenue', 'title' => 'Expected Revenue (HT)', 'source' => 'price grid × scanned containers · FCFA',         'icon' => 'M12 1v22M2 12h20',                  'color' => 'brand'],
                ],
            ],
        ];

        // Service output. May be null if OdooFactory threw (no creds yet,
        // or unreachable). Treat null and missing keys as pending so the
        // page never 500s — it shows the placeholder UI instead.
        $opsKpis = $ops['kpis'] ?? [];
    @endphp

    @foreach ($proposedKpis as $section)
        <div class="mb-7">
            <div class="flex items-center gap-2 mb-3">
                <div class="w-1 h-4 rounded bg-brand-400"></div>
                <h2 class="text-xs font-semibold uppercase tracking-wider text-[#5a6a70]">{{ $section['group'] }}</h2>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
                @foreach ($section['cards'] as $card)
                    @php
                        $palettes = [
                            'brand' => ['bg-brand-500',  'bg-brand-50',  'border-brand-100',  'text-brand-600'],
                            'sage'  => ['bg-sage-500',   'bg-sage-50',   'border-sage-100',   'text-sage-600'],
                            'warm'  => ['bg-warm-500',   'bg-warm-50',   'border-warm-100',   'text-warm-600'],
                            'coral' => ['bg-coral-500',  'bg-coral-50',  'border-coral-100',  'text-coral-600'],
                            'slate' => ['bg-slate-400',  'bg-slate-50',  'border-slate-100',  'text-slate-500'],
                        ];
                        [$bar, $bg, $bdr, $txt] = $palettes[$card['color']] ?? $palettes['brand'];

                        // Resolve live data from the service. Treat any of
                        // these as "pending" → render placeholder UI:
                        //   - service unavailable (Odoo down / no creds)
                        //   - no entry for this key (KPI not yet helper'd)
                        //   - service explicitly flagged pending=true
                        $live    = $opsKpis[$card['key']] ?? null;
                        $pending = !$live || !empty($live['pending']);
                        $value   = $pending ? '— —' : ($live['value'] ?? '— —');
                        $sub     = $live['sub'] ?? '';
                        $vsPy    = !$pending && isset($live['vsPy']) && is_numeric($live['vsPy']) ? (float) $live['vsPy'] : null;
                        // vs-PY semantics: positive = up vs prior year; for
                        // most ops KPIs that's good (more throughput, more
                        // scans), but Scan Rate / Inspection Rate already
                        // express improvement as positive too. So all cards
                        // can use the same "up = good" colouring.
                        $vsPyColor = $vsPy === null ? 'text-slate-400'
                                  : ($vsPy >= 0 ? 'text-sage-700' : 'text-coral-700');
                        $vsPyArrow = $vsPy === null ? null
                                  : ($vsPy >= 0 ? 'M12 6l5 5H7z' : 'M12 18l5-5H7z');
                    @endphp
                    <div class="kpi-card bg-white rounded-xl border border-[#e8e4de] shadow-sm relative overflow-hidden flex">
                        <div class="accent-bar {{ $bar }} shrink-0 {{ $pending ? 'opacity-60' : '' }}"></div>
                        <div class="flex-1 min-w-0 p-4">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-1.5 mb-1">
                                        <span class="text-[11px] uppercase tracking-wider text-[#8a8578] font-medium truncate">{{ $card['title'] }}</span>
                                        @if ($pending)
                                          <span class="inline-flex items-center text-[9px] font-semibold uppercase tracking-wider px-1.5 py-0.5 rounded bg-warm-100 text-warm-700 border border-warm-200">Pending data</span>
                                        @endif
                                    </div>
                                    <div class="text-xl font-bold {{ $pending ? 'text-[#a09d95]' : 'text-[#2d3748]' }} mt-1 tabular-nums truncate">{{ $value }}</div>
                                    @if ($sub)
                                      <div class="text-[11px] {{ $pending ? 'text-[#a09d95]' : 'text-[#7c7466]' }} mt-0.5">{!! $sub !!}</div>
                                    @endif
                                    @if ($vsPy !== null)
                                      <div class="mt-2 text-[11px] font-semibold flex items-center gap-1 {{ $vsPyColor }}">
                                          <svg class="w-3 h-3" viewBox="0 0 24 24" fill="currentColor">
                                              <path d="{{ $vsPyArrow }}"/>
                                          </svg>
                                          {{ ($vsPy >= 0 ? '+' : '') . number_format($vsPy, 1) . '%' }}
                                          <span class="font-normal text-[#7c7466]">vs PY</span>
                                      </div>
                                    @endif
                                </div>
                                <div class="w-7 h-7 rounded-md {{ $bg }} {{ $bdr }} border flex items-center justify-center shrink-0">
                                    <svg class="w-3.5 h-3.5 {{ $txt }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="{{ $card['icon'] }}"/>
                                    </svg>
                                </div>
                            </div>

                            {{-- Source-formula chip — kept visible for both pending
                                 and live cards so the user always sees how the KPI
                                 is computed. Tasteful slate styling when live. --}}
                            <div class="mt-2 text-[11px] font-mono text-[#7c7466] bg-[#faf8f5] border border-[#ece7df] rounded px-2 py-1 truncate" title="{{ $card['source'] }}">
                                {{ $card['source'] }}
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach

    {{-- ── Trend chart ──────────────────────────────────────────────────── --}}
    @php
        $trend = $ops['trend'] ?? null;
        $hasTrend = $trend && empty($trend['error']) && !empty($trend['buckets']) && !empty($trend['series']);
    @endphp
    @if ($hasTrend)
      @php
          // Default selection — Containers Scanned is the most operationally
          // meaningful starting point. User can toggle to any other series.
          $defaultKey = 'containers_scanned';
          $granularityLabel = $trend['granularity'] === 'week' ? 'Weekly' : 'Monthly';
      @endphp
      <div class="bg-white border border-[#e8e4de] rounded-xl p-5 mb-6"
           data-trend='@json($trend, JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG)'
           id="ops-trend-card">
          <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
              <div>
                  <h3 class="text-sm font-semibold text-[#1e3d44]">Trend</h3>
                  <div class="text-[11px] text-[#7c7466] mt-0.5">
                      {{ $granularityLabel }} buckets · {{ count($trend['buckets']) }} points · click a tab to switch series
                  </div>
              </div>

              {{-- KPI tabs — vanilla JS toggles `data-active` and the chart redraws. --}}
              <div class="flex flex-wrap gap-1 text-[11px]" id="ops-trend-tabs">
                  @foreach ($trend['meta'] as $key => $meta)
                      <button type="button"
                              data-trend-key="{{ $key }}"
                              class="px-3 py-1.5 rounded-md border transition
                                     {{ $key === $defaultKey
                                        ? 'bg-brand-600 text-white border-brand-600 shadow-sm'
                                        : 'bg-white text-[#5a6a70] border-[#e8e4de] hover:bg-[#faf8f5]' }}">
                          {{ $meta['label'] }}
                      </button>
                  @endforeach
              </div>
          </div>

          <div class="relative">
              <svg id="ops-trend-svg" viewBox="0 0 800 220" preserveAspectRatio="none"
                   class="w-full h-[220px] block">
                  {{-- The JS below populates this SVG. SSR-empty is fine. --}}
              </svg>
              <div id="ops-trend-readout"
                   class="absolute top-0 right-0 text-[11px] text-[#5a6a70] bg-white/80 backdrop-blur-sm rounded px-2 py-1 hidden">
              </div>
          </div>
      </div>

      {{-- The script below is intentionally vanilla — Alpine isn't loaded
           in this codebase yet. Self-contained so removing the chart is a
           one-element delete. --}}
      <script>
      (function () {
          const card = document.getElementById('ops-trend-card');
          if (!card) return;
          const svg  = document.getElementById('ops-trend-svg');
          const tabs = document.getElementById('ops-trend-tabs');
          const readout = document.getElementById('ops-trend-readout');
          let trend;
          try { trend = JSON.parse(card.dataset.trend); } catch (e) { return; }
          if (!trend || !trend.series) return;

          const W = 800, H = 220;
          const PAD_L = 56, PAD_R = 16, PAD_T = 16, PAD_B = 32;
          const PLOT_W = W - PAD_L - PAD_R;
          const PLOT_H = H - PAD_T - PAD_B;

          const formatValue = (v, fmt, unit) => {
              if (typeof v !== 'number' || !isFinite(v)) return '—';
              if (fmt === 'pct')   return v.toFixed(1) + '%';
              if (fmt === 'money') {
                  if (Math.abs(v) >= 1e9) return (v / 1e9).toFixed(2) + 'B ' + (unit||'');
                  if (Math.abs(v) >= 1e6) return (v / 1e6).toFixed(2) + 'M ' + (unit||'');
                  if (Math.abs(v) >= 1e3) return (v / 1e3).toFixed(1) + 'K ' + (unit||'');
                  return Math.round(v).toLocaleString() + ' ' + (unit||'');
              }
              return Math.round(v).toLocaleString();
          };

          const draw = (key) => {
              const series = trend.series[key] || [];
              const buckets = trend.buckets || [];
              const meta = (trend.meta || {})[key] || {label: key, format: 'count', unit: ''};
              if (!series.length) return;

              // Y-axis bounds — pad by 10% above max for breathing room.
              let maxV = Math.max(...series, 0);
              let minV = Math.min(...series, 0);
              if (maxV === minV) maxV = minV + 1;
              const range = maxV - minV;
              const yMax = maxV + range * 0.1;
              const yMin = minV < 0 ? minV - range * 0.1 : 0;

              const xFor = i => PAD_L + (series.length === 1 ? PLOT_W / 2 : (i / (series.length - 1)) * PLOT_W);
              const yFor = v => PAD_T + PLOT_H - ((v - yMin) / (yMax - yMin)) * PLOT_H;

              // Build path d-string for the line.
              const linePath = series.map((v, i) => `${i === 0 ? 'M' : 'L'} ${xFor(i).toFixed(1)} ${yFor(v).toFixed(1)}`).join(' ');

              // Soft area fill below the line.
              const areaPath = linePath + ` L ${xFor(series.length - 1).toFixed(1)} ${yFor(yMin).toFixed(1)} L ${xFor(0).toFixed(1)} ${yFor(yMin).toFixed(1)} Z`;

              // Gridlines — 4 horizontal levels.
              const gridLines = [];
              for (let i = 0; i <= 4; i++) {
                  const y = PAD_T + (PLOT_H * i / 4);
                  const v = yMax - (yMax - yMin) * i / 4;
                  gridLines.push(`<line x1="${PAD_L}" y1="${y}" x2="${W - PAD_R}" y2="${y}" stroke="#f1ede5" stroke-width="1"/>`);
                  gridLines.push(`<text x="${PAD_L - 6}" y="${y + 3}" text-anchor="end" font-size="10" fill="#a09d95" font-family="ui-sans-serif, system-ui">${formatValue(v, meta.format, meta.unit).replace(' ' + (meta.unit||''), '')}</text>`);
              }

              // X-axis bucket labels.
              const xLabels = buckets.map((b, i) => {
                  const x = xFor(i);
                  return `<text x="${x.toFixed(1)}" y="${H - 10}" text-anchor="middle" font-size="10" fill="#7c7466" font-family="ui-sans-serif, system-ui">${b.label}</text>`;
              }).join('');

              // Dots — one per bucket. Each carries data attrs for hover.
              const dots = series.map((v, i) => {
                  return `<circle cx="${xFor(i).toFixed(1)}" cy="${yFor(v).toFixed(1)}" r="3.5" fill="white" stroke="#0d6e6e" stroke-width="2" data-i="${i}" data-v="${v}" class="cursor-pointer"/>`;
              }).join('');

              svg.innerHTML = [
                  `<defs><linearGradient id="ops-trend-grad" x1="0" x2="0" y1="0" y2="1">
                      <stop offset="0%"   stop-color="#0d6e6e" stop-opacity="0.18"/>
                      <stop offset="100%" stop-color="#0d6e6e" stop-opacity="0"/>
                  </linearGradient></defs>`,
                  gridLines.join(''),
                  `<path d="${areaPath}" fill="url(#ops-trend-grad)"/>`,
                  `<path d="${linePath}" fill="none" stroke="#0d6e6e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>`,
                  dots,
                  xLabels,
              ].join('');

              // Hover handler — show point readout in the corner badge.
              svg.querySelectorAll('circle').forEach(c => {
                  c.addEventListener('mouseenter', (e) => {
                      const i = parseInt(c.dataset.i, 10);
                      const v = parseFloat(c.dataset.v);
                      readout.textContent = (buckets[i] ? buckets[i].label : '') + ' · ' + formatValue(v, meta.format, meta.unit);
                      readout.classList.remove('hidden');
                  });
                  c.addEventListener('mouseleave', () => readout.classList.add('hidden'));
              });
          };

          // Tab click — toggle styling, redraw.
          tabs.addEventListener('click', (e) => {
              const btn = e.target.closest('[data-trend-key]');
              if (!btn) return;
              tabs.querySelectorAll('[data-trend-key]').forEach(b => {
                  b.className = 'px-3 py-1.5 rounded-md border transition bg-white text-[#5a6a70] border-[#e8e4de] hover:bg-[#faf8f5]';
              });
              btn.className = 'px-3 py-1.5 rounded-md border transition bg-brand-600 text-white border-brand-600 shadow-sm';
              draw(btn.dataset.trendKey);
          });

          // Initial render.
          draw('{{ $defaultKey }}');
      })();
      </script>
    @endif

    {{-- ── Scan revenue breakdown (per official price grid) ─────────────── --}}
    @php
        $breakdown = $ops['revenue_breakdown'] ?? null;
    @endphp
    @if ($breakdown && empty($breakdown['error']) && ($breakdown['total_count'] ?? 0) > 0)
      <div class="bg-white border border-[#e8e4de] rounded-xl p-5 mb-6">
          <div class="flex items-center justify-between mb-3">
              <h3 class="text-sm font-semibold text-[#1e3d44]">Scan Revenue Breakdown <span class="text-[10px] uppercase tracking-wider text-[#a09d95] font-medium ml-1">FCFA · HT · {{ $period['type'] === 'mtd' ? 'MTD' : 'YTD' }}</span></h3>
              <span class="text-[11px] text-[#7c7466]">Per the customer's official <em>Grille Tarifaire</em></span>
          </div>
          <div class="overflow-x-auto">
              <table class="w-full text-xs">
                  <thead>
                      <tr class="text-[10px] uppercase tracking-wider text-[#8a8578] border-b border-[#e8e4de]">
                          <th class="text-left  py-2 pr-3 font-medium">Category</th>
                          <th class="text-right py-2 px-3 font-medium">Count</th>
                          <th class="text-right py-2 px-3 font-medium">Price / unit</th>
                          <th class="text-right py-2 pl-3 font-medium">Revenue HT</th>
                      </tr>
                  </thead>
                  <tbody class="divide-y divide-[#f1ede5]">
                      @foreach ($breakdown['categories'] as $cat)
                          @php
                              $priceVal   = $cat['price'] ?? null;
                              $revenueVal = $cat['revenue'] ?? null;
                              $priceLabel = $priceVal === null
                                                ? 'rate pending *'
                                                : ($priceVal === 0 ? '— free —' : number_format($priceVal));
                              $revLabel   = $revenueVal === null
                                                ? 'pending *'
                                                : ($revenueVal === 0 ? '—' : number_format($revenueVal));
                              $rowMuted   = ($priceVal === 0 || $priceVal === null);
                          @endphp
                          <tr class="{{ $rowMuted ? 'text-[#9b958a]' : 'text-[#2d3748]' }}">
                              <td class="py-2 pr-3">{{ $cat['label'] }}</td>
                              <td class="py-2 px-3 text-right tabular-nums">{{ number_format($cat['count']) }}</td>
                              <td class="py-2 px-3 text-right tabular-nums">{{ $priceLabel }}</td>
                              <td class="py-2 pl-3 text-right tabular-nums font-medium">{{ $revLabel }}</td>
                          </tr>
                      @endforeach
                  </tbody>
                  <tfoot>
                      <tr class="border-t-2 border-[#e8e4de] font-semibold text-[#1e3d44]">
                          <td class="py-2 pr-3">Total</td>
                          <td class="py-2 px-3 text-right tabular-nums">{{ number_format($breakdown['total_count']) }}</td>
                          <td class="py-2 px-3 text-right text-[#7c7466] font-normal">— blended —</td>
                          <td class="py-2 pl-3 text-right tabular-nums">{{ number_format($breakdown['total_revenue']) }}</td>
                      </tr>
                  </tfoot>
              </table>
          </div>
          <div class="text-[11px] text-[#7c7466] mt-3 leading-relaxed space-y-1.5">
              <div><strong>Note:</strong> Empty (vide) and Transit containers are scanned systematically and free of charge per the price grid. 45' containers are billed at the 40' tariff (the grid only enumerates 20"/40"). Vehicles are classified via <code class="text-[10px] bg-[#faf8f5] border border-[#ece7df] rounded px-1 py-0.5">aw_vehicule.vehicule.cylinders</code> (4/6 → Voiture, 8 → Camion, 12 → Bulldoze); rates per the Grille Tarifaire. Period anchor for revenue is <code class="text-[10px] bg-[#faf8f5] border border-[#ece7df] rounded px-1 py-0.5">scanned_date</code> — the moment the fee is earned.</div>
              <div>
                <strong>*</strong> <em>Other / unclassified (assumed Voiture)</em>: scanned vehicles whose
                <code class="text-[10px] bg-[#faf8f5] border border-[#ece7df] rounded px-1 py-0.5">cylinders</code>
                field on
                <code class="text-[10px] bg-[#faf8f5] border border-[#ece7df] rounded px-1 py-0.5">aw_vehicule.vehicule</code>
                wasn't filled in at scan time (mostly <code class="text-[10px] bg-[#faf8f5] border border-[#ece7df] rounded px-1 py-0.5">cylinders=0</code>, plus a small tail at 9). Sample names confirm these are real cars (Toyota Corolla, Avensis, Yaris …), so they're billed at the Voiture rate (the cheapest of the four) to project revenue without over-claiming. Categorise them in Odoo for an exact figure.
              </div>
              <div>
                <strong>**</strong> <em>Bois</em>: tariff is 35,000 HT <em>per truck or wagon</em>, not per piece of wood. Approximated by counting distinct cargos with at least one scanned bois record in the period (each cargo ≈ one truckload). The
                <code class="text-[10px] bg-[#faf8f5] border border-[#ece7df] rounded px-1 py-0.5">im_vehicule</code>
                field on bois would give a sharper count but is mostly empty in the live data.
              </div>
          </div>
      </div>
    @endif

    {{-- ── Expected Revenue Simulation (what-if scenarios) ──────────────── --}}
    @php
        $sim = null;
        if ($breakdown && empty($breakdown['error'])) {
            $billableKeys = [
                'import_full_40', 'import_full_20',
                'export_full_40', 'export_full_20',
                'voiture', 'camion', 'bulldoze',
                'vehicles_unclassified',  // assumed Voiture (see footnote *)
                'wood_per_truckload',
            ];
            $simRows = [];
            foreach ($billableKeys as $k) {
                if (!isset($breakdown['categories'][$k])) continue;
                $cat = $breakdown['categories'][$k];
                $price = $cat['price'] ?? null;
                if ($price === null || $price === 0) continue;
                $simRows[] = [
                    'key'   => $k,
                    'label' => $cat['label'],
                    'count' => (int) ($cat['count'] ?? 0),
                    'price' => (int) $price,
                ];
            }
            if (!empty($simRows)) {
                $sim = $simRows;
            }
        }
    @endphp
    @if ($sim)
      <div class="bg-white border border-[#e8e4de] rounded-xl p-5 mb-6">
          <div class="flex items-start justify-between mb-3 gap-3 flex-wrap">
              <div>
                  <h3 class="text-sm font-semibold text-[#1e3d44]">
                      Expected Revenue Simulation
                      <span class="text-[10px] uppercase tracking-wider text-[#a09d95] font-medium ml-1">FCFA · HT · what-if · {{ $period['type'] === 'mtd' ? 'MTD' : 'YTD' }}</span>
                  </h3>
                  <p class="text-[11px] text-[#7c7466] mt-1.5 leading-relaxed max-w-3xl">
                      Volumes default to the current period's scanned counts; unit prices default to the Grille Tarifaire. Edit any field to model a scenario — subtotals and the grand total recompute instantly. Empty / Transit (free) are excluded by design. Unclassified vehicles (see footnote *) are included at the Voiture rate — adjust below if your scenario differs.
                  </p>
              </div>
              <button type="button" id="ops-sim-reset"
                      class="text-[11px] text-[#7c7466] hover:text-[#1e3d44] border border-[#e8e4de] rounded px-3 py-1.5 transition whitespace-nowrap">
                  ↻ Reset to scanned baseline
              </button>
          </div>

          <div class="overflow-x-auto">
              <table class="w-full text-xs">
                  <thead>
                      <tr class="text-[10px] uppercase tracking-wider text-[#8a8578] border-b border-[#e8e4de]">
                          <th class="text-left  py-2 pr-3 font-medium">Category</th>
                          <th class="text-right py-2 px-3 font-medium">Volume</th>
                          <th class="text-right py-2 px-3 font-medium">Unit Price (HT)</th>
                          <th class="text-right py-2 pl-3 font-medium">Subtotal</th>
                      </tr>
                  </thead>
                  <tbody class="divide-y divide-[#f1ede5]" id="ops-sim-body">
                      @foreach ($sim as $row)
                          <tr data-row="{{ $row['key'] }}" class="text-[#2d3748]">
                              <td class="py-2 pr-3">{{ $row['label'] }}</td>
                              <td class="py-2 px-3 text-right">
                                  <input type="number" min="0" step="1"
                                         value="{{ $row['count'] }}"
                                         data-default="{{ $row['count'] }}"
                                         data-field="count"
                                         class="w-24 text-right tabular-nums bg-[#faf8f5] border border-[#ece7df] rounded px-2 py-1 focus:outline-none focus:border-[#1e3d44]" />
                              </td>
                              <td class="py-2 px-3 text-right">
                                  <input type="number" min="0" step="500"
                                         value="{{ $row['price'] }}"
                                         data-default="{{ $row['price'] }}"
                                         data-field="price"
                                         class="w-28 text-right tabular-nums bg-[#faf8f5] border border-[#ece7df] rounded px-2 py-1 focus:outline-none focus:border-[#1e3d44]" />
                              </td>
                              <td class="py-2 pl-3 text-right tabular-nums font-medium" data-subtotal>—</td>
                          </tr>
                      @endforeach
                  </tbody>
                  <tfoot>
                      <tr class="border-t-2 border-[#e8e4de] font-semibold text-[#1e3d44]">
                          <td class="py-2.5 pr-3" colspan="3">Total Expected Revenue</td>
                          <td class="py-2.5 pl-3 text-right tabular-nums" id="ops-sim-total">—</td>
                      </tr>
                      <tr class="text-[11px] text-[#7c7466]">
                          <td class="py-1 pr-3" colspan="2">
                              <span class="font-medium">Δ vs. baseline:</span>
                              <span id="ops-sim-delta" class="ml-1">—</span>
                          </td>
                          <td class="py-1 px-3 text-right">Baseline</td>
                          <td class="py-1 pl-3 text-right tabular-nums" id="ops-sim-baseline">—</td>
                      </tr>
                  </tfoot>
              </table>
          </div>

          <div class="text-[11px] text-[#7c7466] mt-3 leading-relaxed">
              <strong>Tip:</strong> set Voiture price to <code class="text-[10px] bg-[#faf8f5] border border-[#ece7df] rounded px-1 py-0.5">0</code> to remove it from the model, bump container volumes to project a busy month, or stress-test what a 10% rate increase across all categories does to the top line. Nothing here is persisted — this is purely a planning sandbox.
          </div>
      </div>

      <script>
      (function () {
          var body = document.getElementById('ops-sim-body');
          if (!body) return;
          var totalEl = document.getElementById('ops-sim-total');
          var deltaEl = document.getElementById('ops-sim-delta');
          var baseEl  = document.getElementById('ops-sim-baseline');
          var resetBtn = document.getElementById('ops-sim-reset');

          var fmt = function (n) {
              return Math.round(n).toLocaleString('en-US');
          };

          function recompute() {
              var total = 0;
              var base  = 0;
              body.querySelectorAll('tr[data-row]').forEach(function (tr) {
                  var cI = tr.querySelector('input[data-field="count"]');
                  var pI = tr.querySelector('input[data-field="price"]');
                  var c = parseFloat(cI.value) || 0;
                  var p = parseFloat(pI.value) || 0;
                  var sub = c * p;
                  total += sub;
                  tr.querySelector('[data-subtotal]').textContent = fmt(sub);
                  var dC = parseFloat(cI.dataset.default) || 0;
                  var dP = parseFloat(pI.dataset.default) || 0;
                  base += dC * dP;
              });
              totalEl.textContent = fmt(total);
              baseEl.textContent  = fmt(base);
              var diff = total - base;
              var pct  = base > 0 ? (diff / base * 100) : 0;
              var sign = diff >= 0 ? '+' : '';
              var color = diff > 0 ? '#3a7d44' : (diff < 0 ? '#b35a35' : '#7c7466');
              deltaEl.innerHTML = '<span style="color:' + color + ';font-weight:500">' +
                  sign + fmt(diff) + ' FCFA (' + sign + pct.toFixed(1) + '%)</span>';
          }

          body.addEventListener('input', recompute);
          body.addEventListener('change', recompute);
          if (resetBtn) {
              resetBtn.addEventListener('click', function () {
                  body.querySelectorAll('input[data-default]').forEach(function (i) {
                      i.value = i.dataset.default;
                  });
                  recompute();
              });
          }
          recompute();
      })();
      </script>
    @endif

@endsection
