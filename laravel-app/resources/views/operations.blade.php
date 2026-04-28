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
    <x-period-filter :period="$period" :options="[['ytd','YTD'],['mtd','MTD']]" />

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
                          <th class="text-right py-2 px-3 font-medium">Containers</th>
                          <th class="text-right py-2 px-3 font-medium">Price / unit</th>
                          <th class="text-right py-2 pl-3 font-medium">Revenue HT</th>
                      </tr>
                  </thead>
                  <tbody class="divide-y divide-[#f1ede5]">
                      @foreach ($breakdown['categories'] as $cat)
                          <tr class="{{ $cat['price'] === 0 ? 'text-[#9b958a]' : 'text-[#2d3748]' }}">
                              <td class="py-2 pr-3">{{ $cat['label'] }}</td>
                              <td class="py-2 px-3 text-right tabular-nums">{{ number_format($cat['count']) }}</td>
                              <td class="py-2 px-3 text-right tabular-nums">{{ $cat['price'] === 0 ? '— free —' : number_format($cat['price']) }}</td>
                              <td class="py-2 pl-3 text-right tabular-nums font-medium">{{ $cat['price'] === 0 ? '—' : number_format($cat['revenue']) }}</td>
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
          <div class="text-[11px] text-[#7c7466] mt-3 leading-relaxed">
              <strong>Note:</strong> Empty (vide) and Transit containers are scanned systematically and free of charge per the price grid. 45' containers are billed at the 40' tariff (the grid only enumerates 20"/40"). Period anchor for revenue is <code class="text-[10px] bg-[#faf8f5] border border-[#ece7df] rounded px-1 py-0.5">scanned_date</code> — the moment the fee is earned.
          </div>
      </div>
    @endif

    {{-- ── Schema notes & next steps ────────────────────────────────────── --}}
    <div class="bg-white border border-[#e8e4de] rounded-xl p-5">
        <h3 class="text-sm font-semibold text-[#1e3d44] mb-3">Data sources &amp; design notes</h3>
        <div class="text-xs text-[#5a6a70] leading-relaxed space-y-3">
            <div>
                <span class="font-semibold text-[#1e3d44]">Models in use</span> —
                <code class="text-[11px] bg-[#faf8f5] border border-[#ece7df] rounded px-1.5 py-0.5">aw_cargo.stopover</code> (vessel calls),
                <code class="text-[11px] bg-[#faf8f5] border border-[#ece7df] rounded px-1.5 py-0.5">aw_cargo.cargo</code> (bills of lading),
                <code class="text-[11px] bg-[#faf8f5] border border-[#ece7df] rounded px-1.5 py-0.5">aw_cargo.equipment</code> (containers).
            </div>
            <div>
                <span class="font-semibold text-[#1e3d44]">Period anchor</span> — every KPI filters by
                <code class="text-[11px] bg-[#faf8f5] border border-[#ece7df] rounded px-1.5 py-0.5">create_date</code>
                because <code class="text-[11px] bg-[#faf8f5] border border-[#ece7df] rounded px-1.5 py-0.5">eta</code> is sparsely populated. Containers Scanned uses
                <code class="text-[11px] bg-[#faf8f5] border border-[#ece7df] rounded px-1.5 py-0.5">scanned_date</code>.
            </div>
            <div>
                <span class="font-semibold text-[#1e3d44]">Working-day basis</span> — Mon–Sat (consistent with the Dashboard's RpD &amp; Run-Rate).
            </div>
            <div>
                <span class="font-semibold text-[#1e3d44]">Revenue model</span> — <em>Expected Revenue</em> applies the customer's official Grille Tarifaire to the scanned-container mix (per-row pricing in
                <code class="text-[11px] bg-[#faf8f5] border border-[#ece7df] rounded px-1.5 py-0.5">App\Services\OperationsService::PRICE_HT_*</code> constants). To switch to actual invoiced revenue, traverse <code class="text-[11px] bg-[#faf8f5] border border-[#ece7df] rounded px-1.5 py-0.5">cargo.invoice_ids</code> → <code class="text-[11px] bg-[#faf8f5] border border-[#ece7df] rounded px-1.5 py-0.5">account.move</code> in a v2 — the expected vs actual delta then becomes a leakage metric.
            </div>
            <div>
                <span class="font-semibold text-[#1e3d44]">Deferred to v2</span> —
                <em>Top Vessels by Volume</em> (drill-down list page),
                <em>Single-container detail page</em>,
                <em>Customer / Destination breakdowns</em> (the <code class="text-[11px] bg-[#faf8f5] border border-[#ece7df] rounded px-1.5 py-0.5">partner_id</code> field is mostly empty in the source data — needs upstream data quality work).
            </div>
        </div>
    </div>
@endsection
