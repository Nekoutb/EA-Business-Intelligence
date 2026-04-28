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

    {{-- ── Proposed KPIs (preview) ──────────────────────────────────────── --}}
    @php
        // KPI plan — static metadata (title, icon, source-formula label, palette).
        // Each entry's `key` is looked up in $ops['kpis'][$key] for the live
        // value (or pending placeholder while OperationsService is in
        // schema-discovery). Adding a new KPI here is a 2-step: drop an
        // entry in this array + add the matching helper in
        // OperationsService::analyze().
        $proposedKpis = [
            [
                'group' => 'Container Throughput',
                'cards' => [
                    ['key' => 'containers_scanned', 'title' => 'Containers Scanned',  'source' => 'count(x_container_scan)', 'icon' => 'M3 7h18M3 12h18M3 17h18', 'color' => 'brand'],
                    ['key' => 'containers_per_day', 'title' => 'Containers per Day',  'source' => 'count ÷ working_days',    'icon' => 'M12 8v4l3 2M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20z', 'color' => 'sage'],
                    ['key' => 'avg_dwell_hours',    'title' => 'Avg Dwell Time',      'source' => 'avg(sortie - entrée)',    'icon' => 'M12 6v6l4 2', 'color' => 'warm'],
                ],
            ],
            [
                'group' => 'Container Mix',
                'cards' => [
                    ['key' => 'mix_by_type',  'title' => 'Mix by Container Type', 'source' => 'group_by(x_container_type)',           'icon' => 'M4 4h16v6H4zM4 14h16v6H4z', 'color' => 'brand'],
                    ['key' => 'reefer_share', 'title' => 'Reefer Share',          'source' => "x_container_type = 'reefer' ÷ total",  'icon' => 'M12 2l4 8h-3v8h-2v-8H8z',    'color' => 'sage'],
                ],
            ],
            [
                'group' => 'Revenue & Customers',
                'cards' => [
                    ['key' => 'revenue_per_container', 'title' => 'Revenue per Container',     'source' => 'sum(invoice) ÷ count(container)', 'icon' => 'M12 1v22M2 12h20',                                                                'color' => 'brand'],
                    ['key' => 'top_customers',         'title' => 'Top 5 Customers by Volume', 'source' => 'group_by(partner_id)',            'icon' => 'M3 12h6l3-9 3 18 3-9h3',                                                          'color' => 'coral'],
                    ['key' => 'top_destinations',      'title' => 'Top 5 Destinations',        'source' => 'group_by(destination)',           'icon' => 'M12 2C8 6 6 9 6 13a6 6 0 0 0 12 0c0-4-2-7-6-11zM12 13a2 2 0 1 1 0-4 2 2 0 0 1 0 4z', 'color' => 'slate'],
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

    {{-- ── Implementation roadmap ───────────────────────────────────────── --}}
    <div class="bg-white border border-[#e8e4de] rounded-xl p-5">
        <h3 class="text-sm font-semibold text-[#1e3d44] mb-3">Implementation roadmap</h3>
        <ol class="space-y-2 text-xs text-[#5a6a70] leading-relaxed list-decimal list-inside">
            <li><strong>Schema discovery</strong> — super admin runs <code class="text-[11px] bg-[#faf8f5] border border-[#ece7df] rounded px-1.5 py-0.5">/admin/tenants/&lt;id&gt;/odoo-probe</code>. JSON output identifies the actual logistics models and fields.</li>
            <li><strong>Service layer</strong> — <code class="text-[11px] bg-[#faf8f5] border border-[#ece7df] rounded px-1.5 py-0.5">App\Services\OperationsService</code> wraps the OdooClient, mirrors RevenueService's <code class="text-[11px] bg-[#faf8f5] border border-[#ece7df] rounded px-1.5 py-0.5">analyze($today, $period)</code> shape, returns CY/PY same-period comparisons.</li>
            <li><strong>Wire the cards</strong> — replace the placeholder cards above with <code class="text-[11px] bg-[#faf8f5] border border-[#ece7df] rounded px-1.5 py-0.5">&lt;x-kpi&gt;</code> instances backed by the analyzer; cache per period (<code class="text-[11px] bg-[#faf8f5] border border-[#ece7df] rounded px-1.5 py-0.5">ea_cached("ops_{$type}", …)</code>).</li>
            <li><strong>Per-tenant KPI toggles</strong> — register Operations KPIs in <code class="text-[11px] bg-[#faf8f5] border border-[#ece7df] rounded px-1.5 py-0.5">config/dashboard_kpis.php</code> (or a sibling <code class="text-[11px] bg-[#faf8f5] border border-[#ece7df] rounded px-1.5 py-0.5">operations_kpis.php</code>) so admins can hide individual cards per tenant.</li>
            <li><strong>Drill-downs</strong> — secondary pages for <em>Container detail</em>, <em>Scan log</em>, <em>Customer × destination matrix</em>. Same pattern as Revenue → Product Profitability.</li>
        </ol>
    </div>
@endsection
