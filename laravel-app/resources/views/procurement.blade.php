@extends('layouts.app')
@section('title', ($dashMode ?? false) ? 'Procurement Dashboard' : 'Procurement')

@section('page-title', ($dashMode ?? false) ? 'Procurement Control Dashboard' : 'Procurement')
@section('content')
@if ($dashMode ?? false)
{{-- ═══ Procurement Control Dashboard — one-page governance view. 8 KPIs vs
     admin-set targets (operator + value), cumulative YTD as at the last
     closed month-end. Approved mockup v3. Hosted here behind dashMode
     because this host cannot compile brand-new views. ═══ --}}
@php
    $OPTXT = ['lt' => '<', 'lte' => '≤', 'gt' => '>', 'gte' => '≥'];
    $meets = function (string $op, float $actual, float $target): bool {
        return match ($op) {
            'lt'  => $actual <  $target,
            'lte' => $actual <= $target,
            'gt'  => $actual >  $target,
            default => $actual >= $target,
        };
    };
    $statusOf = function (?float $actual, array $t) use ($meets): array {
        if ($actual === null) return ['na', 'No data'];
        $op = (string) $t['op']; $tv = (float) $t['value'];
        if ($meets($op, $actual, $tv)) return ['good', 'On target'];
        if ($op === 'lt' || $op === 'lte') return $actual <= 2 * $tv ? ['warn', 'Watch'] : ['crit', 'Off target'];
        return $actual >= 0.85 * $tv ? ['warn', 'Watch'] : ['crit', 'Off target'];
    };
    $fmtV = fn (?float $v, string $u) => $v === null ? '—' : number_format($v, ($u === '%' || $u === 'd') ? 1 : 0);
    $unitTxt = fn (string $u) => $u === 'd' ? ' days' : $u;

    // Assemble the 8 KPIs in presentation order (emergency is manual).
    $kpis = [];
    foreach (['po_open_60', 'po_open_90', 'po_processing', 'po_after_fact', 'dpo'] as $kk) {
        if (isset($dash['kpis'][$kk])) $kpis[$kk] = $dash['kpis'][$kk];
    }
    $kpis['emergency'] = [
        'label' => 'Emergency procedures (this year)', 'unit' => '',
        'actual' => (float) ($emergency ?? 0), 'sub' => 'entered by management',
        'cadence' => 'Annual', 'source' => 'Exceptions log · manual',
    ];
    foreach (['exempt', 'compliance'] as $kk) {
        if (isset($dash['kpis'][$kk])) $kpis[$kk] = $dash['kpis'][$kk];
    }
    $asAtHuman = \DateTimeImmutable::createFromFormat('!Y-m-d', $asAt)?->format('j F Y') ?? $asAt;
    $nextHuman = \DateTimeImmutable::createFromFormat('!Y-m-d', $nextRefresh)?->format('j M') ?? $nextRefresh;
@endphp

<style>
  .pd-hdr { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; border-bottom:2px solid #0d6e6e; padding-bottom:12px; margin-bottom:18px; }
  .pd-hdr .pd-who { font-size:13px; color:#64748b; margin-top:2px; }
  .pd-hdr .pd-meta { text-align:right; font-size:12.5px; color:#64748b; }
  .pd-btn { display:inline-block; margin-top:6px; background:#0d6e6e; color:#fff; border:0; border-radius:7px; padding:8px 15px; font-size:12.5px; font-weight:600; cursor:pointer; }
  .pd-btn.pd-ghost { background:transparent; color:#0d6e6e; border:1px solid #0d6e6e; }
  .pd-tiles { display:grid; grid-template-columns:repeat(auto-fit,minmax(300px,1fr)); gap:14px; }
  .pd-tile { border:1px solid #e2e8f0; border-radius:11px; padding:16px 18px 15px; background:#fff; }
  .pd-lbl { font-size:12.5px; font-weight:700; letter-spacing:.05em; text-transform:uppercase; color:#94a3b8; margin-bottom:10px; }
  .pd-val { font-size:34px; font-weight:700; letter-spacing:-.02em; font-variant-numeric:tabular-nums; line-height:1; color:#0f172a; }
  .pd-val .pd-u { font-size:16px; font-weight:600; color:#64748b; margin-left:3px; }
  .pd-sub { font-size:12.5px; color:#64748b; margin-top:8px; }
  .pd-row { display:flex; justify-content:space-between; align-items:center; margin-top:10px; gap:8px; flex-wrap:wrap; }
  .pd-tgt { font-size:13px; color:#64748b; font-variant-numeric:tabular-nums; }
  .pd-chip { display:inline-flex; align-items:center; gap:6px; font-size:11.5px; font-weight:700; border-radius:10px; padding:3px 10px; white-space:nowrap; }
  .pd-chip::before { content:""; width:8px; height:8px; border-radius:50%; background:currentColor; }
  .pd-chip.good { color:#1a7f37; background:#e6f4ea; }
  .pd-chip.warn { color:#b45309; background:#fdf1e2; }
  .pd-chip.crit { color:#b91c1c; background:#fdeaea; }
  .pd-chip.na   { color:#64748b; background:#f1f5f9; }
  .pd-adminbar { display:flex; align-items:center; gap:10px; margin:22px 0 0; flex-wrap:wrap; }
  .pd-adminnote { font-size:12px; color:#94a3b8; }
  #pdAdmin { display:none; margin-top:12px; border:1px dashed #cbd5e1; border-radius:10px; padding:14px 16px 16px; background:#fff; }
  #pdAdmin.open { display:block; }
  #pdAdmin h2 { font-size:14px; margin:0 0 10px; color:#0f172a; }
  #pdAdmin h2 .pd-note { font-size:11.5px; font-weight:400; color:#94a3b8; margin-left:6px; }
  .pd-table { width:100%; border-collapse:collapse; }
  .pd-table th { text-align:left; font-size:10.5px; letter-spacing:.06em; text-transform:uppercase; color:#94a3b8; padding:8px 10px; border-bottom:1px solid #e2e8f0; }
  .pd-table td { padding:8px 10px; font-size:13.5px; border-bottom:1px solid #f1f5f9; vertical-align:middle; color:#1e293b; }
  .pd-table .num { text-align:right; font-variant-numeric:tabular-nums; }
  .pd-table .ctr { text-align:center; }
  .pd-src { color:#64748b; font-size:12px; }
  .pd-op { padding:4px 4px; border:1px solid #e2e8f0; border-radius:6px; background:#fff; color:#1e293b; font-size:13.5px; font-weight:600; }
  .pd-in { width:70px; padding:4px 7px; border:1px solid #e2e8f0; border-radius:6px; background:#fff; color:#1e293b; font-size:13.5px; font-weight:600; text-align:right; font-variant-numeric:tabular-nums; }
  .pd-foot { margin-top:22px; padding-top:12px; border-top:1px solid #e2e8f0; font-size:11.5px; color:#64748b; }
  .pd-foot p { margin:3px 0; }
  @media print {
    .ea-sb, .pd-btn, .pd-adminbar, #pdAdmin { display:none !important; }
    .pd-tiles { grid-template-columns:repeat(3,1fr); gap:8px; }
    .pd-val { font-size:24px; }
  }
</style>

@if ($dashError)
    <div class="bg-coral-50 border border-coral-200 text-coral-800 rounded-xl p-4 text-sm mb-6">{{ $dashError }}</div>
@endif

<div class="pd-hdr">
    <div>
        <div class="pd-who">{{ optional(tenant())->name }} — governance view of the purchase-order cycle</div>
    </div>
    <div class="pd-meta">
        As at <b>{{ $asAtHuman }}</b> (last closed month-end) · <b>Cumulative YTD</b><br>
        Open POs: <b>{{ number_format((int) ($dash['meta']['open_pos'] ?? 0)) }}</b>
        · Confirmed POs YTD: <b>{{ number_format((int) ($dash['meta']['confirmed_ytd'] ?? 0)) }}</b>
        · Vendor bills YTD: <b>{{ number_format((int) ($dash['meta']['bills_ytd'] ?? 0)) }}</b>
        · Next refresh: <b>{{ $nextHuman }}</b><br>
        <button class="pd-btn" onclick="window.print()">Print / Export PDF</button>
    </div>
</div>

<div class="pd-tiles">
    @foreach ($kpis as $key => $kpi)
        @php
            $t = $targets[$key] ?? ['op' => 'lt', 'value' => 0.0];
            [$sCls, $sLbl] = $statusOf($kpi['actual'] !== null ? (float) $kpi['actual'] : null, $t);
        @endphp
        <div class="pd-tile">
            <div class="pd-lbl">{{ $kpi['label'] }}</div>
            <div class="pd-val">{{ $fmtV($kpi['actual'] !== null ? (float) $kpi['actual'] : null, $kpi['unit']) }}<span class="pd-u">{{ $unitTxt($kpi['unit']) }}</span></div>
            <div class="pd-sub">{{ $kpi['sub'] }}</div>
            <div class="pd-row">
                <span class="pd-tgt">Target {{ $OPTXT[$t['op']] ?? '<' }} {{ rtrim(rtrim(number_format((float) $t['value'], 1, '.', ','), '0'), '.') }}{{ $unitTxt($kpi['unit']) }} · {{ $kpi['cadence'] }}</span>
                <span class="pd-chip {{ $sCls }}">{{ $sLbl }}</span>
            </div>
        </div>
    @endforeach
</div>

@if ($isAdmin)
<div class="pd-adminbar">
    <button class="pd-btn pd-ghost" id="pdToggle" type="button" aria-expanded="false">Set targets (admin)</button>
    <span class="pd-adminnote">Visible to administrators only — operator + value persist for this client and drive the status chips.</span>
</div>
<div id="pdAdmin">
    <h2>Governance matrix — target setup <span class="pd-note">choose operator and value per KPI · Save to persist</span></h2>
    <form method="POST" action="{{ tenant_url('/sync') }}">
        @csrf
        <input type="hidden" name="_intent" value="proc_targets">
        <table class="pd-table">
            <tr><th style="width:30%">Indicator</th><th class="num">Actual (YTD)</th><th class="ctr">Target</th><th class="ctr">Status</th><th>Review cadence</th><th>Source</th></tr>
            @foreach ($kpis as $key => $kpi)
                @php
                    $t = $targets[$key] ?? ['op' => 'lt', 'value' => 0.0];
                    [$sCls, $sLbl] = $statusOf($kpi['actual'] !== null ? (float) $kpi['actual'] : null, $t);
                @endphp
                <tr>
                    <td>{{ $kpi['label'] }}<div class="pd-src">{{ $kpi['sub'] }}</div></td>
                    <td class="num"><b>{{ $fmtV($kpi['actual'] !== null ? (float) $kpi['actual'] : null, $kpi['unit']) }}{{ $kpi['unit'] === 'd' ? ' d' : $kpi['unit'] }}</b></td>
                    <td class="ctr" style="white-space:nowrap;">
                        <select class="pd-op" name="targets[{{ $key }}][op]" aria-label="Operator for {{ $kpi['label'] }}">
                            @foreach (['lt' => '<', 'lte' => '≤', 'gt' => '>', 'gte' => '≥'] as $opv => $sym)
                                <option value="{{ $opv }}" @if (($t['op'] ?? '') === $opv) selected @endif>{{ $sym }}</option>
                            @endforeach
                        </select>
                        <input class="pd-in" type="number" step="0.5" min="0" name="targets[{{ $key }}][value]" value="{{ rtrim(rtrim(number_format((float) $t['value'], 2, '.', ''), '0'), '.') }}" aria-label="Target value for {{ $kpi['label'] }}">
                        <span class="pd-src">{{ $unitTxt($kpi['unit']) }}</span>
                    </td>
                    <td class="ctr"><span class="pd-chip {{ $sCls }}">{{ $sLbl }}</span></td>
                    <td>{{ $kpi['cadence'] }}</td>
                    <td class="pd-src">{{ $kpi['source'] }}</td>
                </tr>
            @endforeach
        </table>
        <div style="display:flex;align-items:center;gap:12px;margin-top:12px;flex-wrap:wrap;">
            <label class="pd-src" for="pdEm">Emergency procedures recorded this year:</label>
            <input class="pd-in" id="pdEm" type="number" step="1" min="0" name="emergency_count" value="{{ (int) ($emergency ?? 0) }}">
            <button class="pd-btn" type="submit">Save targets</button>
        </div>
    </form>
</div>
<script>
document.getElementById('pdToggle').addEventListener('click', function () {
    var p = document.getElementById('pdAdmin');
    var open = !p.classList.contains('open');
    p.classList.toggle('open', open);
    this.setAttribute('aria-expanded', open ? 'true' : 'false');
    this.textContent = open ? 'Hide targets panel' : 'Set targets (admin)';
});
</script>
@endif

<div class="pd-foot">
    <p><strong>Basis.</strong> All indicators are computed cumulatively for the year to date and refresh automatically at each month-end close; the page presents the position as at the last closed month. Review cadence indicates how often each indicator is tabled, not how it is computed.</p>
    <p><strong>Definitions.</strong> Open PO = confirmed, not fully received/billed; ageing from PO order date. Processing time = RFQ created → PO confirmed, business days (proxy — no requisition module in Odoo). After-the-fact = PO order date later than its earliest posted supplier invoice. Exempt purchases &amp; PO compliance measured on all posted vendor bills (count basis). DPO = open supplier-bill balance ÷ class-6 expenses (ex 69) YTD × 365, running cumulative — same basis as the Payables page. Emergency procedures recorded manually (exceptions log). Per-PO detail lives on the Procurement page beneath this dashboard.</p>
    <p><strong>Sources.</strong> Odoo purchase orders &amp; supplier invoices (live) · targets set by management on this page (admin only).</p>
</div>

@else
    @if ($error)
        <div class="bg-coral-50 border border-coral-200 text-coral-800 rounded-xl p-4 text-sm mb-6">
            <strong>Could not reach Odoo:</strong> {{ $error }}
            <div class="text-xs mt-1 text-coral-600">Verify credentials in <a href="{{ tenant_url('/settings') }}" class="underline">Settings</a>.</div>
        </div>
    @endif

    @if (!$procurement || !empty($procurement['pending_overall']))
        <div class="bg-warm-50 border border-warm-200 rounded-xl p-5 mb-6">
            <div class="flex items-start gap-3">
                <div class="w-8 h-8 rounded-lg bg-warm-100 border border-warm-200 flex items-center justify-center shrink-0">
                    <svg class="w-4 h-4 text-warm-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8v4l3 2M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20z"/></svg>
                </div>
                <div class="flex-1">
                    <div class="text-sm font-semibold text-[#7a5a1a]">Awaiting data</div>
                    <p class="text-xs text-[#7c7466] mt-1 leading-relaxed">
                        No POs or vendor bills found for the period, or the Odoo <em>purchase</em> module isn't installed/reachable on this tenant. Try switching the period or check Settings.
                    </p>
                </div>
            </div>
        </div>
    @endif

    {{-- ── Trend chart ─────────────────────────────────────────────────────
         12-month strategic view — three switchable series:
           PO Compliance %  ·  Total Vendor Spend  ·  Open POs
         Always monthly granularity (independent of YTD/MTD period filter
         on the cards below). Vanilla JS, no chart library. --}}
    @php
        $trend = $procurement['trend'] ?? null;
        $hasTrend = is_array($trend)
            && !empty($trend['buckets'])
            && !empty($trend['series']);
        $defaultTrendKey = 'po_compliance';
    @endphp

    @if ($hasTrend)
      <div class="bg-white border border-[#e8e4de] rounded-xl p-5 mb-6"
           data-trend='@json($trend, JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG)'
           id="proc-trend-card">
          <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
              <div>
                  <h3 class="text-sm font-semibold text-[#1e3d44]">Trend</h3>
                  <div class="text-[11px] text-[#7c7466] mt-0.5">
                      {{ ($trend['granularity'] ?? 'month') === 'week' ? 'Weekly' : 'Monthly' }} buckets · {{ count($trend['buckets']) }} points · click a tab to switch series
                  </div>
              </div>
              <div class="flex flex-wrap gap-1 text-[11px]" id="proc-trend-tabs">
                  @foreach ($trend['meta'] as $key => $meta)
                      <button type="button"
                              data-trend-key="{{ $key }}"
                              class="px-3 py-1.5 rounded-md border transition
                                     {{ $key === $defaultTrendKey
                                        ? 'bg-brand-600 text-white border-brand-600 shadow-sm'
                                        : 'bg-white text-[#5a6a70] border-[#e8e4de] hover:bg-[#faf8f5]' }}">
                          {{ $meta['label'] }}
                      </button>
                  @endforeach
              </div>
          </div>

          <div class="relative">
              <svg id="proc-trend-svg" viewBox="0 0 800 220" preserveAspectRatio="none"
                   class="w-full h-[220px] block">
              </svg>
              <div id="proc-trend-readout"
                   class="absolute top-0 right-0 text-[11px] text-[#5a6a70] bg-white/80 backdrop-blur-sm rounded px-2 py-1 hidden">
              </div>
          </div>
      </div>

      <script>
      (function () {
          const card = document.getElementById('proc-trend-card');
          if (!card) return;
          const svg  = document.getElementById('proc-trend-svg');
          const tabs = document.getElementById('proc-trend-tabs');
          const readout = document.getElementById('proc-trend-readout');
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
              // Some buckets may have null (no data) — for plotting we treat
              // null as 0 for layout, but skip the dot so the gap is visible.
              const seriesRaw = trend.series[key] || [];
              const series    = seriesRaw.map(v => (v === null || v === undefined) ? 0 : v);
              const buckets   = trend.buckets || [];
              const meta      = (trend.meta || {})[key] || {label: key, format: 'count', unit: ''};
              if (!series.length) return;

              let maxV = Math.max(...series, 0);
              let minV = Math.min(...series, 0);
              if (maxV === minV) maxV = minV + 1;
              const range = maxV - minV;
              const yMax = maxV + range * 0.1;
              const yMin = minV < 0 ? minV - range * 0.1 : 0;

              const xFor = i => PAD_L + (series.length === 1 ? PLOT_W / 2 : (i / (series.length - 1)) * PLOT_W);
              const yFor = v => PAD_T + PLOT_H - ((v - yMin) / (yMax - yMin)) * PLOT_H;

              const linePath = series.map((v, i) => `${i === 0 ? 'M' : 'L'} ${xFor(i).toFixed(1)} ${yFor(v).toFixed(1)}`).join(' ');
              const areaPath = linePath + ` L ${xFor(series.length - 1).toFixed(1)} ${yFor(yMin).toFixed(1)} L ${xFor(0).toFixed(1)} ${yFor(yMin).toFixed(1)} Z`;

              const gridLines = [];
              for (let i = 0; i <= 4; i++) {
                  const y = PAD_T + (PLOT_H * i / 4);
                  const v = yMax - (yMax - yMin) * i / 4;
                  gridLines.push(`<line x1="${PAD_L}" y1="${y}" x2="${W - PAD_R}" y2="${y}" stroke="#f1ede5" stroke-width="1"/>`);
                  gridLines.push(`<text x="${PAD_L - 6}" y="${y + 3}" text-anchor="end" font-size="10" fill="#a09d95" font-family="ui-sans-serif, system-ui">${formatValue(v, meta.format, meta.unit).replace(' ' + (meta.unit||''), '')}</text>`);
              }

              const xLabels = buckets.map((b, i) => {
                  const x = xFor(i);
                  return `<text x="${x.toFixed(1)}" y="${H - 10}" text-anchor="middle" font-size="10" fill="#7c7466" font-family="ui-sans-serif, system-ui">${b.label}</text>`;
              }).join('');

              // Skip dots where original raw series was null — preserves
              // the "no data" intent visually.
              const dots = seriesRaw.map((rawV, i) => {
                  if (rawV === null || rawV === undefined) return '';
                  const v = series[i];
                  return `<circle cx="${xFor(i).toFixed(1)}" cy="${yFor(v).toFixed(1)}" r="3.5" fill="white" stroke="#0d6e6e" stroke-width="2" data-i="${i}" data-v="${v}" class="cursor-pointer"/>`;
              }).join('');

              svg.innerHTML = [
                  `<defs><linearGradient id="proc-trend-grad" x1="0" x2="0" y1="0" y2="1">
                      <stop offset="0%"   stop-color="#0d6e6e" stop-opacity="0.18"/>
                      <stop offset="100%" stop-color="#0d6e6e" stop-opacity="0"/>
                  </linearGradient></defs>`,
                  gridLines.join(''),
                  `<path d="${areaPath}" fill="url(#proc-trend-grad)"/>`,
                  `<path d="${linePath}" fill="none" stroke="#0d6e6e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>`,
                  dots,
                  xLabels,
              ].join('');

              svg.querySelectorAll('circle').forEach(c => {
                  c.addEventListener('mouseenter', () => {
                      const i = parseInt(c.dataset.i, 10);
                      const v = parseFloat(c.dataset.v);
                      readout.textContent = (buckets[i] ? buckets[i].label : '') + ' · ' + formatValue(v, meta.format, meta.unit);
                      readout.classList.remove('hidden');
                  });
                  c.addEventListener('mouseleave', () => readout.classList.add('hidden'));
              });
          };

          tabs.addEventListener('click', (e) => {
              const btn = e.target.closest('[data-trend-key]');
              if (!btn) return;
              tabs.querySelectorAll('[data-trend-key]').forEach(b => {
                  b.className = 'px-3 py-1.5 rounded-md border transition bg-white text-[#5a6a70] border-[#e8e4de] hover:bg-[#faf8f5]';
              });
              btn.className = 'px-3 py-1.5 rounded-md border transition bg-brand-600 text-white border-brand-600 shadow-sm';
              draw(btn.dataset.trendKey);
          });

          draw('{{ $defaultTrendKey }}');
      })();
      </script>
    @endif

    {{-- ── KPI metadata ────────────────────────────────────────────────── --}}
    @php
        $proposedKpis = [
            [
                'group' => 'Purchasing Lifecycle',
                'cards' => [
                    [
                        'key'    => 'po_volume',
                        'title'  => 'PO Volume &amp; Spend',
                        'source' => "count + sum(amount_total) of purchase.order in period",
                        'icon'   => 'M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z',
                        'color'  => 'brand',
                    ],
                    [
                        'key'    => 'po_cycle_time',
                        'title'  => 'PO Cycle Time',
                        'source' => 'avg(effective_date − date_order); falls back to earliest invoice_date when receipt undated',
                        'icon'   => 'M12 8v4l3 2M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20z',
                        'color'  => 'sage',
                    ],
                ],
            ],
        ];

        $procurementKpis = $procurement['kpis'] ?? [];
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

                        $live    = $procurementKpis[$card['key']] ?? null;
                        $pending = !$live || !empty($live['pending']);
                        $value   = $pending ? '— —' : ($live['value'] ?? '— —');
                        $sub     = $live['sub'] ?? '';
                        $vsPy    = !$pending && isset($live['vsPy']) && is_numeric($live['vsPy']) ? (float) $live['vsPy'] : null;
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
                                        <span class="text-[11px] uppercase tracking-wider text-[#8a8578] font-medium truncate">{!! $card['title'] !!}</span>
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
                            <div class="mt-3 pt-3 border-t border-[#f1ede5]">
                                <code class="text-[10px] text-[#7c7466] block leading-snug font-mono">{{ $card['source'] }}</code>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach

    {{-- ── Open POs Sandbox ──────────────────────────────────────────────
         Snapshot of POs that are confirmed but not yet closed. Not period-
         filtered: this is "what's open right now". Top-10 oldest gives the
         buying team a remediation list. --}}
    @php
        $openPOs = $procurement['open_pos'] ?? null;
    @endphp
    @if ($openPOs && (($openPOs['count'] ?? 0) > 0 || !empty($openPOs['oldest'])))
      <div class="bg-white border border-[#e8e4de] rounded-xl p-5 mb-6">
          <div class="flex items-center gap-2 mb-3">
              <div class="w-1 h-4 rounded bg-warm-400"></div>
              <h2 class="text-xs font-semibold uppercase tracking-wider text-[#5a6a70]">Open POs Sandbox</h2>
          </div>

          {{-- Three-stat strip --}}
          <div class="grid grid-cols-3 gap-3 mb-4">
              <div class="bg-[#faf8f5] border border-[#ece7df] rounded-lg px-4 py-3">
                  <div class="text-[10px] uppercase tracking-wider text-[#8a8578] font-medium mb-1">Open POs</div>
                  <div class="text-2xl font-bold text-[#1e3d44] tabular-nums">{{ number_format($openPOs['count'] ?? 0) }}</div>
                  <div class="text-[11px] text-[#7c7466] mt-0.5">Confirmed, not yet closed</div>
              </div>
              <div class="bg-[#faf8f5] border border-[#ece7df] rounded-lg px-4 py-3">
                  <div class="text-[10px] uppercase tracking-wider text-[#8a8578] font-medium mb-1">Avg ageing</div>
                  <div class="text-2xl font-bold text-[#1e3d44] tabular-nums">
                      {{ isset($openPOs['avg_age_days']) ? number_format($openPOs['avg_age_days'], 1) . ' d' : '—' }}
                  </div>
                  <div class="text-[11px] text-[#7c7466] mt-0.5">Days since PO creation</div>
              </div>
              <div class="bg-[#faf8f5] border border-[#ece7df] rounded-lg px-4 py-3">
                  <div class="text-[10px] uppercase tracking-wider text-[#8a8578] font-medium mb-1">Oldest open PO</div>
                  <div class="text-2xl font-bold {{ ($openPOs['oldest_age_days'] ?? 0) > 90 ? 'text-coral-700' : 'text-[#1e3d44]' }} tabular-nums">
                      {{ isset($openPOs['oldest_age_days']) ? number_format($openPOs['oldest_age_days']) . ' d' : '—' }}
                  </div>
                  <div class="text-[11px] text-[#7c7466] mt-0.5">Worst single age</div>
              </div>
          </div>

          @if (!empty($openPOs['oldest']))
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead>
                        <tr class="text-[10px] uppercase tracking-wider text-[#8a8578] border-b border-[#e8e4de]">
                            <th class="text-left  py-2 pr-3 font-medium">Top 10 oldest open POs</th>
                            <th class="text-left  py-2 px-3 font-medium">Vendor</th>
                            <th class="text-right py-2 px-3 font-medium">Created</th>
                            <th class="text-right py-2 px-3 font-medium">Age</th>
                            <th class="text-right py-2 pl-3 font-medium">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#f1ede5]">
                        @foreach ($openPOs['oldest'] as $po)
                            <tr class="text-[#2d3748]">
                                <td class="py-2 pr-3 font-medium">{{ $po['name'] ?: '#' . $po['id'] }}</td>
                                <td class="py-2 px-3">{{ $po['partner'] ?: '—' }}</td>
                                <td class="py-2 px-3 text-right tabular-nums text-[#7c7466]">
                                    {{ \Carbon\Carbon::parse($po['date_order'])->format('Y-m-d') }}
                                </td>
                                <td class="py-2 px-3 text-right tabular-nums font-semibold {{ $po['age_days'] > 90 ? 'text-coral-700' : ($po['age_days'] > 30 ? 'text-warm-700' : 'text-[#2d3748]') }}">
                                    {{ number_format($po['age_days']) }} d
                                </td>
                                <td class="py-2 pl-3 text-right tabular-nums">{{ number_format($po['amount']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
          @endif

          {{-- Open POs sorted by amount — collapsed by default. --}}
          @if (!empty($openPOs['top_by_amount']))
            <details class="mt-3 group">
                <summary class="cursor-pointer list-none flex items-center justify-between px-3 py-2 bg-[#faf8f5] border border-[#ece7df] rounded-lg hover:bg-[#f4efe5] transition">
                    <span class="text-[11px] uppercase tracking-wider text-[#5a6a70] font-semibold">Top 10 open POs by amount (largest first)</span>
                    <svg class="w-3.5 h-3.5 text-[#7c7466] transition-transform group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
                </summary>
                <div class="overflow-x-auto pt-3">
                    <table class="w-full text-xs">
                        <thead>
                            <tr class="text-[10px] uppercase tracking-wider text-[#8a8578] border-b border-[#e8e4de]">
                                <th class="text-left  py-2 pr-3 font-medium">PO</th>
                                <th class="text-left  py-2 px-3 font-medium">Vendor</th>
                                <th class="text-right py-2 px-3 font-medium">Created</th>
                                <th class="text-right py-2 px-3 font-medium">Age</th>
                                <th class="text-right py-2 pl-3 font-medium">Amount</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#f1ede5]">
                            @foreach ($openPOs['top_by_amount'] as $po)
                                <tr class="text-[#2d3748]">
                                    <td class="py-2 pr-3 font-medium">{{ $po['name'] ?: '#' . $po['id'] }}</td>
                                    <td class="py-2 px-3">{{ $po['partner'] ?: '—' }}</td>
                                    <td class="py-2 px-3 text-right tabular-nums text-[#7c7466]">
                                        {{ \Carbon\Carbon::parse($po['date_order'])->format('Y-m-d') }}
                                    </td>
                                    <td class="py-2 px-3 text-right tabular-nums text-[#7c7466]">
                                        {{ number_format($po['age_days']) }} d
                                    </td>
                                    <td class="py-2 pl-3 text-right tabular-nums font-semibold">{{ number_format($po['amount']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </details>
          @endif

          <div class="text-[11px] text-[#7c7466] mt-3 leading-relaxed">
              <strong>Reading the ageing:</strong> POs older than 30 days warrant a chase; over 90 days usually means either a stuck supplier, a forgotten partial receipt, or a PO that should be cancelled. Periodic clean-up here directly improves cycle-time and removes phantom commitments from the books.
          </div>
      </div>
    @endif

    {{-- ── Spend & Compliance ────────────────────────────────────────────
         Two paired KPI cards with native <details>-based dropdowns. Click
         the chevron to reveal the top-10 detail tables. --}}
    @php
        $p3 = $procurementKpis['po_compliance'] ?? null;
        $p4 = $procurementKpis['total_spend']   ?? null;
        $bd = (!empty($p3) && empty($p3['pending'])) ? ($p3['breakdown'] ?? null) : null;
        $unlinkedBills = $procurement['unlinked_bills'] ?? [];
        $topSuppliers  = $procurement['top_suppliers']  ?? [];
    @endphp

    <div class="mb-7">
        <div class="flex items-center gap-2 mb-3">
            <div class="w-1 h-4 rounded bg-coral-400"></div>
            <h2 class="text-xs font-semibold uppercase tracking-wider text-[#5a6a70]">Spend &amp; Compliance</h2>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">

            {{-- ── P3: PO Compliance ── --}}
            @php
                $pendingP3 = !$p3 || !empty($p3['pending']);
                $valueP3   = $pendingP3 ? '— —' : ($p3['value'] ?? '— —');
                $subP3     = $p3['sub'] ?? '';
                $vsPyP3    = !$pendingP3 && isset($p3['vsPy']) && is_numeric($p3['vsPy']) ? (float) $p3['vsPy'] : null;
                $vsPyColorP3 = $vsPyP3 === null ? 'text-slate-400'
                              : ($vsPyP3 >= 0 ? 'text-sage-700' : 'text-coral-700');
                $vsPyArrowP3 = $vsPyP3 === null ? null
                              : ($vsPyP3 >= 0 ? 'M12 6l5 5H7z' : 'M12 18l5-5H7z');
            @endphp
            <details class="kpi-details bg-white rounded-xl border border-[#e8e4de] shadow-sm overflow-hidden group">
                <summary class="cursor-pointer list-none p-4 flex items-start gap-3 hover:bg-[#fafaf7] transition">
                    <div class="accent-bar bg-coral-500 w-1 self-stretch rounded-full {{ $pendingP3 ? 'opacity-60' : '' }}"></div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-1.5 mb-1">
                                    <span class="text-[11px] uppercase tracking-wider text-[#8a8578] font-medium truncate">PO Compliance</span>
                                    @if ($pendingP3)
                                      <span class="inline-flex items-center text-[9px] font-semibold uppercase tracking-wider px-1.5 py-0.5 rounded bg-warm-100 text-warm-700 border border-warm-200">Pending data</span>
                                    @endif
                                </div>
                                <div class="text-xl font-bold {{ $pendingP3 ? 'text-[#a09d95]' : 'text-[#2d3748]' }} mt-1 tabular-nums truncate">{{ $valueP3 }}</div>
                                @if ($subP3)
                                  <div class="text-[11px] {{ $pendingP3 ? 'text-[#a09d95]' : 'text-[#7c7466]' }} mt-0.5">{!! $subP3 !!}</div>
                                @endif
                                @if ($vsPyP3 !== null)
                                  <div class="mt-2 text-[11px] font-semibold flex items-center gap-1 {{ $vsPyColorP3 }}">
                                      <svg class="w-3 h-3" viewBox="0 0 24 24" fill="currentColor"><path d="{{ $vsPyArrowP3 }}"/></svg>
                                      {{ ($vsPyP3 >= 0 ? '+' : '') . number_format($vsPyP3, 1) . '%' }}
                                      <span class="font-normal text-[#7c7466]">vs PY</span>
                                  </div>
                                @endif
                                <div class="mt-3 pt-3 border-t border-[#f1ede5]">
                                    <code class="text-[10px] text-[#7c7466] block leading-snug font-mono">vendor bills WITH a PO link ÷ all posted vendor bills</code>
                                </div>
                            </div>
                            <div class="flex flex-col items-end gap-2 shrink-0">
                                <div class="w-7 h-7 rounded-md bg-coral-50 border border-coral-100 flex items-center justify-center">
                                    <svg class="w-3.5 h-3.5 text-coral-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                </div>
                                <div class="flex items-center gap-1 text-[10px] uppercase tracking-wider text-[#8a8578] font-medium">
                                    <span>Top 10</span>
                                    <svg class="w-3 h-3 transition-transform group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
                                </div>
                            </div>
                        </div>
                    </div>
                </summary>
                @if (!empty($bd))
                  @php
                      $total    = max(1, (int) $bd['total']);
                      $linked   = (int) $bd['linked'];
                      $unlinked = (int) $bd['unlinked'];
                      $pctLinked   = round(100 * $linked / $total, 1);
                      $pctUnlinked = round(100 * $unlinked / $total, 1);
                  @endphp
                  <div class="px-4 pb-4 border-t border-[#f1ede5] pt-3 bg-[#fafaf7]">
                      <div class="flex h-6 rounded overflow-hidden border border-[#ece7df] mb-2">
                          @if ($linked > 0)
                            <div class="bg-sage-300/80 flex items-center justify-center text-[10px] font-semibold text-sage-800" style="width: {{ $pctLinked }}%">
                                {{ $pctLinked >= 12 ? "{$pctLinked}% with PO" : '' }}
                            </div>
                          @endif
                          @if ($unlinked > 0)
                            <div class="bg-coral-300/80 flex items-center justify-center text-[10px] font-semibold text-coral-800" style="width: {{ $pctUnlinked }}%">
                                {{ $pctUnlinked >= 12 ? "{$pctUnlinked}% no PO" : '' }}
                            </div>
                          @endif
                      </div>
                      <div class="flex justify-between text-[10px] text-[#7c7466] mb-3">
                          <span><strong class="text-sage-700">{{ number_format($linked) }}</strong> with PO</span>
                          <span><strong class="text-coral-700">{{ number_format($unlinked) }}</strong> maverick</span>
                          <span class="text-[#a09d95]">{{ number_format($total) }} total</span>
                      </div>
                      @if (!empty($unlinkedBills))
                        <div class="overflow-x-auto">
                            <table class="w-full text-xs">
                                <thead>
                                    <tr class="text-[10px] uppercase tracking-wider text-[#8a8578] border-b border-[#e8e4de]">
                                        <th class="text-left  py-1.5 pr-3 font-medium">Top 10 non-compliant bills</th>
                                        <th class="text-left  py-1.5 px-3 font-medium">Vendor</th>
                                        <th class="text-right py-1.5 px-3 font-medium">Date</th>
                                        <th class="text-right py-1.5 pl-3 font-medium">Amount</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-[#f1ede5]">
                                    @foreach ($unlinkedBills as $b)
                                        <tr class="text-[#2d3748]">
                                            <td class="py-1.5 pr-3 font-medium">{{ $b['name'] ?: '#' . $b['id'] }}</td>
                                            <td class="py-1.5 px-3 truncate max-w-[200px]">{{ $b['partner'] ?: '—' }}</td>
                                            <td class="py-1.5 px-3 text-right tabular-nums text-[#7c7466]">{{ $b['invoice_date'] }}</td>
                                            <td class="py-1.5 pl-3 text-right tabular-nums font-semibold">{{ number_format($b['amount']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                      @endif
                  </div>
                @endif
            </details>

            {{-- ── P4: Total Vendor Spend ── --}}
            @php
                $pendingP4 = !$p4 || !empty($p4['pending']);
                $valueP4   = $pendingP4 ? '— —' : ($p4['value'] ?? '— —');
                $subP4     = $p4['sub'] ?? '';
                $vsPyP4    = !$pendingP4 && isset($p4['vsPy']) && is_numeric($p4['vsPy']) ? (float) $p4['vsPy'] : null;
                $vsPyColorP4 = $vsPyP4 === null ? 'text-slate-400'
                              : ($vsPyP4 >= 0 ? 'text-sage-700' : 'text-coral-700');
                $vsPyArrowP4 = $vsPyP4 === null ? null
                              : ($vsPyP4 >= 0 ? 'M12 6l5 5H7z' : 'M12 18l5-5H7z');
            @endphp
            <details class="kpi-details bg-white rounded-xl border border-[#e8e4de] shadow-sm overflow-hidden group">
                <summary class="cursor-pointer list-none p-4 flex items-start gap-3 hover:bg-[#fafaf7] transition">
                    <div class="accent-bar bg-brand-500 w-1 self-stretch rounded-full {{ $pendingP4 ? 'opacity-60' : '' }}"></div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-1.5 mb-1">
                                    <span class="text-[11px] uppercase tracking-wider text-[#8a8578] font-medium truncate">Total Vendor Spend</span>
                                    @if ($pendingP4)
                                      <span class="inline-flex items-center text-[9px] font-semibold uppercase tracking-wider px-1.5 py-0.5 rounded bg-warm-100 text-warm-700 border border-warm-200">Pending data</span>
                                    @endif
                                </div>
                                <div class="text-xl font-bold {{ $pendingP4 ? 'text-[#a09d95]' : 'text-[#2d3748]' }} mt-1 tabular-nums truncate">{{ $valueP4 }}</div>
                                @if ($subP4)
                                  <div class="text-[11px] {{ $pendingP4 ? 'text-[#a09d95]' : 'text-[#7c7466]' }} mt-0.5">{!! $subP4 !!}</div>
                                @endif
                                @if ($vsPyP4 !== null)
                                  <div class="mt-2 text-[11px] font-semibold flex items-center gap-1 {{ $vsPyColorP4 }}">
                                      <svg class="w-3 h-3" viewBox="0 0 24 24" fill="currentColor"><path d="{{ $vsPyArrowP4 }}"/></svg>
                                      {{ ($vsPyP4 >= 0 ? '+' : '') . number_format($vsPyP4, 1) . '%' }}
                                      <span class="font-normal text-[#7c7466]">vs PY</span>
                                  </div>
                                @endif
                                <div class="mt-3 pt-3 border-t border-[#f1ede5]">
                                    <code class="text-[10px] text-[#7c7466] block leading-snug font-mono">sum(amount_untaxed_signed) of posted in_invoice in period · net of taxes</code>
                                </div>
                            </div>
                            <div class="flex flex-col items-end gap-2 shrink-0">
                                <div class="w-7 h-7 rounded-md bg-brand-50 border border-brand-100 flex items-center justify-center">
                                    <svg class="w-3.5 h-3.5 text-brand-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M12 1v22M2 12h20"/>
                                    </svg>
                                </div>
                                <div class="flex items-center gap-1 text-[10px] uppercase tracking-wider text-[#8a8578] font-medium">
                                    <span>Top 10</span>
                                    <svg class="w-3 h-3 transition-transform group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
                                </div>
                            </div>
                        </div>
                    </div>
                </summary>
                @if (!empty($topSuppliers))
                  <div class="px-4 pb-4 border-t border-[#f1ede5] pt-3 bg-[#fafaf7]">
                      <div class="overflow-x-auto">
                          <table class="w-full text-xs">
                              <thead>
                                  <tr class="text-[10px] uppercase tracking-wider text-[#8a8578] border-b border-[#e8e4de]">
                                      <th class="text-left  py-1.5 pr-3 font-medium">Top 10 suppliers by billed spend</th>
                                      <th class="text-left  py-1.5 px-3 font-medium">Recorded in</th>
                                      <th class="text-right py-1.5 px-3 font-medium">Bills</th>
                                      <th class="text-right py-1.5 pl-3 font-medium">Amount (HT)</th>
                                  </tr>
                              </thead>
                              <tbody class="divide-y divide-[#f1ede5]">
                                  @foreach ($topSuppliers as $s)
                                      <tr class="text-[#2d3748]">
                                          <td class="py-1.5 pr-3 font-medium truncate max-w-[220px]">{{ $s['partner'] ?: '—' }}</td>
                                          <td class="py-1.5 px-3 truncate max-w-[260px] text-[#5a6a70]">
                                              @if (!empty($s['account']))
                                                {{ $s['account'] }}
                                              @else
                                                <span class="text-[#a09d95]">—</span>
                                              @endif
                                          </td>
                                          <td class="py-1.5 px-3 text-right tabular-nums text-[#7c7466]">{{ number_format($s['count']) }}</td>
                                          <td class="py-1.5 pl-3 text-right tabular-nums font-semibold">{{ number_format($s['amount']) }}</td>
                                      </tr>
                                  @endforeach
                              </tbody>
                          </table>
                          <div class="text-[10px] text-[#a09d95] mt-2 italic">"Recorded in" = the dominant expense account (highest debit) on each supplier's posted bills.</div>
                      </div>
                  </div>
                @endif
            </details>
        </div>

        <div class="text-[11px] text-[#7c7466] mt-3 leading-relaxed">
            <strong>Reading the rate:</strong> the headline is the <em>compliance</em> rate — the share of vendor bills that were raised against an approved PO. Best-in-class is &gt;95%; normal is 85–95%; below 85% means a meaningful slice of spend is bypassing the PO process ("maverick spend"). Click each card's chevron for the top-10 detail lists; spend amounts are net of taxes.
        </div>
    </div>

@endif {{-- /dashMode --}}
@endsection
