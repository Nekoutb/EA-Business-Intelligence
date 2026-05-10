@extends('layouts.app')
@section('title', 'Product Revenue TSD')
@section('page-title', 'Product Revenue TSD · Transatlantic Douala')

{{--
    Data shape (ProductRevenueTsdController::index):
      $analysis = [
        year, today_month, vat_rate, categories,
        products  → ['cars' => ['key','label','category','color','pending','volume_only',
                                'budget_price','budget_qty','actual_qty','actual_price',
                                'ytd_actual_qty','ytd_budget_qty',
                                'ytd_actual_revenue','ytd_budget_revenue',
                                'volume_effect_xaf','price_effect_xaf','net_variance_xaf',
                                'pct_of_plan']],
        totals    → ['tracked' => [...], 'pending_ytd_budget_revenue' => float]
      ]
      $error  — exception message (only set when Odoo / cache blew up)
      $year   — currently displayed year (also lives in $analysis['year'])

    Layout: section A (per-product small multiples), B (total revenue),
    B' (price-volume-mix variance), C (budget editor). All four sections
    share the same dataset and the editor form mounts in-page so the
    user can save without leaving the analysis.
--}}

@push('styles')
<style>
  /* Scoped to this page only — uses the same palette as dashboard.blade.php
     so visual identity is consistent across the BI suite. */
  .prt-wrap { --c-cont:#1e3a8a; --c-veh:#b45309; --c-wood:#166534; --c-other:#6d28d9; }
  .prt-wrap .v5-card {
    background: var(--card-bg, #fff); border: 1px solid var(--border, #e5e7eb);
    border-radius: 6px; padding: 14px 16px 12px; margin-bottom: 14px;
  }
  .prt-wrap .v5-card__h { display:flex; justify-content:space-between; align-items:flex-start;
    margin-bottom:6px; gap:12px; flex-wrap:wrap; }
  .prt-wrap .v5-card__t { font-size:13px; font-weight:700; letter-spacing:-0.005em; }
  .prt-wrap .v5-card__sub { font-size:11px; color:#6b7280; margin-top:2px; }
  .prt-wrap .v5-card__b { padding-top:4px; }
  .prt-wrap .v5-card__f { margin-top:8px; padding-top:8px; border-top:1px solid #f3f4f6;
    font-size:11px; color:#374151; line-height:1.5; }
  .prt-wrap .v5-card__f b { color:#111827; }
  .prt-pos { color:#047857; font-weight:600; }
  .prt-neg { color:#b91c1c; font-weight:600; }
  .prt-warn{ color:#d97706; font-weight:600; }

  .prt-strip { display:grid; grid-template-columns:repeat(5,1fr); gap:0;
    background:#fff; border:1px solid #e5e7eb; border-radius:6px; margin-bottom:14px; }
  .prt-strip__c { padding:14px 18px; border-right:1px solid #f3f4f6; }
  .prt-strip__c:last-child { border-right:0; }
  .prt-strip__l { font-size:10px; text-transform:uppercase; letter-spacing:0.06em;
    color:#6b7280; font-weight:600; }
  .prt-strip__v { font-size:21px; font-weight:700; margin-top:2px; letter-spacing:-0.02em; color:#111827; }
  .prt-strip__v .u { font-size:12px; font-weight:500; color:#6b7280; margin-left:3px; }
  .prt-strip__m { font-size:11px; color:#6b7280; margin-top:2px; }
  .prt-strip__v.is-pos { color:#047857; }
  .prt-strip__v.is-neg { color:#b91c1c; }

  .prt-multi { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:14px; }
  .prt-multi .v5-card { margin-bottom:0; }
  .prt-chart { width:100%; height:200px; }
  .prt-chart--tall { height:380px; }

  .prt-section { font-size:12px; font-weight:700; text-transform:uppercase;
    letter-spacing:0.08em; color:#6b7280;
    margin:22px 0 10px; padding-bottom:6px; border-bottom:1px solid #e5e7eb; }

  .prt-controls { display:flex; gap:6px; align-items:center; flex-wrap:wrap; }
  .prt-btn { padding:5px 10px; border:1px solid #d1d5db; border-radius:4px; background:#fff;
    color:#374151; font-size:11px; font-weight:600; cursor:pointer; }
  .prt-btn.is-active { background:#0d6e6e; color:#fff; border-color:#0d6e6e; }
  .prt-btn:not(.is-active):hover { background:#f3f4f6; }
  .prt-btn.is-danger:hover { background:#fef2f2; color:#b91c1c; border-color:#b91c1c; }
  .prt-tog { display:inline-flex; gap:6px; align-items:center;
    padding:3px 8px; background:#f9fafb; border-radius:4px; font-size:11px; color:#374151; }

  .prt-cm { display:inline-block; width:8px; height:8px; border-radius:2px; margin-right:6px; vertical-align:middle; }
  .prt-cm--cont  { background:#1e3a8a; }
  .prt-cm--veh   { background:#b45309; }
  .prt-cm--wood  { background:#166534; }
  .prt-cm--other { background:#6d28d9; }
  .prt-cm--empty { background:#64748b; }

  .prt-pending-pill { font-size:9px; padding:1px 5px; border-radius:10px;
    background:#fef3c7; color:#92400e; margin-left:4px; font-weight:600; }
  .prt-leg { display:flex; gap:10px; font-size:10px; color:#374151; margin-top:2px; }
  .prt-leg span::before { content:''; display:inline-block; width:10px; height:2px; margin-right:4px; vertical-align:middle; }
  .prt-leg .l-act::before { background:currentColor; }
  .prt-leg .l-bud::before { background:transparent; border-top:2px dashed currentColor; height:0; }
  .prt-leg .l-py::before  { background:transparent; border-top:1.5px dotted currentColor; height:0; }

  /* Variance + editor tables */
  .prt-vt { width:100%; border-collapse:collapse; font-size:11px; }
  .prt-vt th, .prt-vt td { padding:6px 8px; border-bottom:1px solid #f3f4f6; }
  .prt-vt th { background:#f9fafb; font-weight:700; color:#374151;
    text-transform:uppercase; letter-spacing:0.04em; font-size:10px;
    text-align:right; vertical-align:bottom; line-height:1.3; }
  .prt-vt th:first-child, .prt-vt td:first-child { text-align:left; }
  .prt-vt td { text-align:right; font-variant-numeric:tabular-nums; }
  .prt-vt td.label { font-weight:600; color:#111827; }
  .prt-vt tr.cat-row td { background:#f3f4f6; font-weight:700; font-size:10px;
    text-transform:uppercase; letter-spacing:0.04em; color:#374151; text-align:left; padding:6px 8px; }
  .prt-vt tr.total-row td { background:#ecfdf5; font-weight:700; color:#111827; font-size:12px;
    border-top:2px solid #047857; }
  .prt-vt .vp { color:#047857; }
  .prt-vt .vn { color:#b91c1c; }
  .prt-vt .vz { color:#6b7280; }

  .prt-ed-wrap { overflow-x:auto; }
  .prt-ed { width:100%; border-collapse:collapse; font-size:11px; min-width:1300px; }
  .prt-ed th, .prt-ed td { padding:5px 6px; text-align:right; border-bottom:1px solid #f3f4f6; }
  .prt-ed th:first-child, .prt-ed td:first-child { text-align:left; font-weight:600; }
  .prt-ed th { background:#f9fafb; font-weight:700; color:#374151;
    text-transform:uppercase; letter-spacing:0.04em; font-size:10px; position:sticky; top:0; }
  .prt-ed input[type=number] { width:100%; padding:3px 5px; border:1px solid #d1d5db;
    border-radius:3px; font-size:11px; text-align:right; font-family:inherit; }
  .prt-ed input.price { width:95px; }
  .prt-ed input.vol { width:50px; }
  .prt-ed input:focus { outline:2px solid #0d6e6e; outline-offset:-1px; border-color:#0d6e6e; }
  .prt-ed .total { font-weight:700; color:#111827; background:#f9fafb; }
  .prt-ed tr.cat td { background:#f3f4f6; font-weight:700; font-size:10px;
    text-transform:uppercase; letter-spacing:0.04em; color:#374151; text-align:left; padding:6px 8px; }
  .prt-ed tr.sub td { font-weight:700; background:#f9fafb; color:#111827; }
  .prt-ed tr.grand td { font-weight:700; background:#ecfdf5; color:#047857; font-size:12px;
    border-top:2px solid #047857; }

  .prt-flash { background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46;
    padding:8px 12px; border-radius:4px; font-size:12px; margin-bottom:14px; }
</style>
@endpush

@section('content')
<div class="prt-wrap">

  @if (session('status'))
    <div class="prt-flash">{{ session('status') }}</div>
  @endif

  @if ($error)
    <div class="prt-flash" style="background:#fef2f2;border-color:#fecaca;color:#991b1b;">
      <b>Could not load analysis:</b> {{ $error }}
    </div>
  @endif

  @if (!$analysis)
    <div class="v5-card"><p>No analysis available. Try running a fresh sync from the dashboard, then come back.</p></div>
  @else

    @php
      $products = $analysis['products'];
      $cats     = $analysis['categories'];
      $tot      = $analysis['totals']['tracked'];
      $pendingYtd = (float) ($analysis['totals']['pending_ytd_budget_revenue'] ?? 0);
      $todayMonth = (int) $analysis['today_month'];

      $byCat = collect($products)->groupBy('category');

      $fmtMShort = function (?float $v): string {
          if ($v === null) return '—';
          $a = abs($v);
          if ($a >= 1e9) return number_format($v / 1e9, 2) . 'B';
          if ($a >= 1e6) return number_format($v / 1e6, 1) . 'M';
          if ($a >= 1e3) return number_format($v / 1e3, 0) . 'K';
          return number_format($v, 0);
      };
      $fmtSigned = function (?float $v): string {
          if ($v === null) return '—';
          return ($v > 0 ? '+' : '') . number_format($v / 1e6, 1) . 'M';
      };
      $pctSigned = function (?float $v): string {
          if ($v === null || !is_finite($v)) return '—';
          return ($v > 0 ? '+' : '') . number_format($v, 1) . '%';
      };
    @endphp

    {{-- ── Headline strip (5 cells) — tracked-products comparison ── --}}
    <div class="prt-strip">
      <div class="prt-strip__c">
        <div class="prt-strip__l">Revenue YTD (Actual)</div>
        <div class="prt-strip__v">{{ $fmtMShort($tot['ytd_actual_revenue']) }}<span class="u">XAF</span></div>
        <div class="prt-strip__m">{{ $todayMonth }} mo · tracked products</div>
      </div>
      <div class="prt-strip__c">
        <div class="prt-strip__l">Revenue YTD (Budget)</div>
        <div class="prt-strip__v">{{ $fmtMShort($tot['ytd_budget_revenue']) }}<span class="u">XAF</span></div>
        <div class="prt-strip__m">plan × plan price</div>
      </div>
      <div class="prt-strip__c">
        <div class="prt-strip__l">Net Revenue Gap</div>
        <div class="prt-strip__v {{ $tot['net_variance_xaf'] < 0 ? 'is-neg' : 'is-pos' }}">
          {{ $fmtSigned($tot['net_variance_xaf']) }}<span class="u">XAF</span>
        </div>
        <div class="prt-strip__m">
          @php $pct = $tot['ytd_budget_revenue'] > 0 ? 100 * $tot['net_variance_xaf'] / $tot['ytd_budget_revenue'] : 0; @endphp
          {{ $pctSigned($pct) }} vs plan
        </div>
      </div>
      <div class="prt-strip__c">
        <div class="prt-strip__l">Volume Effect</div>
        <div class="prt-strip__v {{ $tot['volume_effect_xaf'] < 0 ? 'is-neg' : 'is-pos' }}">
          {{ $fmtSigned($tot['volume_effect_xaf']) }}<span class="u">XAF</span>
        </div>
        <div class="prt-strip__m">at planned prices</div>
      </div>
      <div class="prt-strip__c">
        <div class="prt-strip__l">Price Effect</div>
        <div class="prt-strip__v {{ $tot['price_effect_xaf'] < 0 ? 'is-neg' : 'is-pos' }}">
          {{ $fmtSigned($tot['price_effect_xaf']) }}<span class="u">XAF</span>
        </div>
        <div class="prt-strip__m">at actual volumes</div>
      </div>
    </div>

    @if ($pendingYtd > 0)
      <div class="v5-card" style="background:#fffbeb;border-color:#fde68a;color:#92400e;padding:10px 14px;font-size:12px;line-height:1.5;">
        <b>Pending Odoo integration:</b> {{ $fmtMShort($pendingYtd) }} XAF of YTD budget across Other Cargo products
        (General Cargo · Dry Bulk · Packs+MAFI) is excluded from the variance above. Their budget is captured in
        Section&nbsp;C — actuals light up automatically when the Odoo source is configured.
      </div>
    @endif

    {{-- ── Section A · Per-product small multiples ── --}}
    <h2 class="prt-section">A · Per-product trajectory · {{ count($products) }} products</h2>

    <div class="v5-card" style="margin-bottom:8px;">
      <div class="v5-card__h">
        <div>
          <div class="v5-card__t">View · controls every chart below</div>
          <div class="v5-card__sub">Solid = actual · Dashed = budget · Dotted = prior year (Year {{ $analysis['year'] }} = no PY data yet)</div>
        </div>
        <div class="prt-controls">
          <div class="prt-tog">
            <span style="font-weight:600;">Metric</span>
            <button class="prt-btn is-active" data-metric="volume">Volume (units)</button>
            <button class="prt-btn" data-metric="revenue">Revenue (XAF)</button>
            <button class="prt-btn" data-metric="price">Unit Price (XAF)</button>
          </div>
          <label class="prt-tog" style="cursor:pointer;">
            <input type="checkbox" id="prt-py" checked> <span>Show prior year</span>
          </label>
        </div>
      </div>
    </div>

    <div class="prt-multi">
      @foreach ($products as $key => $p)
        @php
          // Marker CSS modifier — picked from category, with Empty
          // containers getting their own slate-grey marker so they
          // visually read as "operational tracking" vs "revenue".
          $marker = match ($p['category']) {
              'containers'  => 'cont',
              'vehicles'    => 'veh',
              'wood'        => 'wood',
              'other_cargo' => 'other',
              default       => 'cont',
          };
          if ($p['volume_only']) $marker = 'empty';
        @endphp
        <div class="v5-card">
          <div class="v5-card__h"><div>
            <div class="v5-card__t">
              <span class="prt-cm prt-cm--{{ $marker }}"></span>{{ $p['label'] }}
              @if ($p['pending'])<span class="prt-pending-pill">budget-only</span>@endif
            </div>
            <div class="v5-card__sub">
              @if ($p['pending'])
                Pending Odoo · budget only · {{ number_format($p['budget_price'], 0) }} XAF
              @elseif ($p['volume_only'])
                0 XAF · volume tracked only
              @else
                {{ number_format($p['budget_price'] / 1000, 0) }}K HT · {{ number_format($p['ytd_budget_qty'] / max(1, $todayMonth)) }}/mo · {{ number_format(array_sum($p['budget_qty'])) }}/yr
              @endif
            </div>
            <div class="prt-leg" style="color:{{ $p['color'] }}">
              @if (!$p['pending'])<span class="l-act">Act</span>@endif
              <span class="l-bud">Bud</span>
              @if (!$p['pending'])<span class="l-py">PY</span>@endif
            </div>
          </div></div>
          <div class="v5-card__b prt-chart" id="prt-chart-{{ $key }}"></div>
          <div class="v5-card__f" data-reading="{{ $key }}"></div>
        </div>
      @endforeach
    </div>

    {{-- ── Section B · Total Product Revenue ── --}}
    <h2 class="prt-section">B · Total Product Revenue · actual vs budget · monthly</h2>

    <div class="v5-card">
      <div class="v5-card__h">
        <div>
          <div class="v5-card__t">Total Product Revenue · all tracked products combined</div>
          <div class="v5-card__sub">Σ (qty × price) per month · solid = actual · dashed = budget · dotted = prior year</div>
        </div>
        <label class="prt-tog" style="cursor:pointer;">
          <input type="checkbox" id="prt-overlay-py" checked> <span>Show prior year</span>
        </label>
      </div>
      <div class="v5-card__b prt-chart prt-chart--tall" id="prt-chart-total"></div>
      <div class="v5-card__f" id="prt-total-reading"></div>
    </div>

    {{-- ── Section B' · Variance summary ── --}}
    <h2 class="prt-section">B' · Revenue variance · price–volume–mix breakdown · YTD</h2>

    <div class="v5-card">
      <div class="v5-card__h">
        <div>
          <div class="v5-card__t">Why is revenue {{ $tot['net_variance_xaf'] < 0 ? 'behind' : 'ahead of' }} plan?</div>
          <div class="v5-card__sub">Net = Volume effect (units missed × planned price) + Price effect (price slip × actual units).</div>
        </div>
      </div>
      <div class="v5-card__b" style="overflow-x:auto;">
        <table class="prt-vt">
          <thead>
            <tr>
              <th rowspan="2" style="text-align:left">Product</th>
              <th colspan="3">Volume (units, YTD)</th>
              <th colspan="3">Avg Unit Price (XAF)</th>
              <th colspan="3">Revenue (M XAF, YTD)</th>
              <th colspan="3">Variance breakdown (M XAF)</th>
            </tr>
            <tr>
              <th>Bud</th><th>Act</th><th>Δ</th>
              <th>Bud</th><th>Act</th><th>Δ%</th>
              <th>Bud</th><th>Act</th><th>Δ</th>
              <th>Volume<br>effect</th><th>Price<br>effect</th><th>Net</th>
            </tr>
          </thead>
          <tbody>
            @foreach ($cats as $catKey => $catCfg)
              @php $catProducts = $byCat->get($catKey, collect([])); @endphp
              @if ($catProducts->isNotEmpty())
                <tr class="cat-row"><td colspan="13"><span class="prt-cm prt-cm--{{ $catKey === 'other_cargo' ? 'other' : ($catKey === 'containers' ? 'cont' : ($catKey === 'vehicles' ? 'veh' : 'wood')) }}"></span>{{ strtoupper($catCfg['label']) }}</td></tr>
                @foreach ($catProducts as $p)
                  @if ($p['pending'])
                    <tr>
                      <td class="label">{{ $p['label'] }}</td>
                      <td>{{ number_format($p['ytd_budget_qty']) }}</td>
                      <td colspan="2" style="color:#6b7280;font-style:italic;text-align:center;">Actuals pending Odoo</td>
                      <td>{{ number_format($p['budget_price'] / 1000, 0) }}K</td>
                      <td colspan="2" style="color:#6b7280;font-style:italic;text-align:center;">—</td>
                      <td>{{ number_format($p['ytd_budget_revenue'] / 1e6, 1) }}</td>
                      <td colspan="5" style="color:#6b7280;font-style:italic;text-align:center;">budget-only · excluded from variance until Odoo source is configured</td>
                    </tr>
                  @else
                    @php
                      $volDelta = $p['ytd_actual_qty'] - $p['ytd_budget_qty'];
                      $priceAct = $p['ytd_actual_qty'] > 0 ? $p['ytd_actual_revenue'] / $p['ytd_actual_qty'] : 0;
                      $priceDeltaPct = $p['budget_price'] > 0 ? 100 * ($priceAct - $p['budget_price']) / $p['budget_price'] : 0;
                      $revDelta = $p['ytd_actual_revenue'] - $p['ytd_budget_revenue'];
                      $isVolOnly = $p['volume_only'] || $p['budget_price'] == 0;
                    @endphp
                    <tr>
                      <td class="label">
                        {{ $p['label'] }}
                        @if ($isVolOnly)<span style="color:#6b7280;font-weight:500;font-size:10px;">(vol only)</span>@endif
                      </td>
                      <td>{{ number_format($p['ytd_budget_qty']) }}</td>
                      <td>{{ number_format($p['ytd_actual_qty']) }}</td>
                      <td class="{{ $volDelta < 0 ? 'vn' : ($volDelta > 0 ? 'vp' : 'vz') }}">
                        {{ ($volDelta > 0 ? '+' : '') . number_format($volDelta) }}
                      </td>
                      <td>{{ number_format($p['budget_price'] / 1000, 0) }}K</td>
                      <td>{{ $isVolOnly ? '0' : number_format($priceAct / 1000, 1) . 'K' }}</td>
                      <td class="{{ $priceDeltaPct < 0 ? 'vn' : ($priceDeltaPct > 0 ? 'vp' : 'vz') }}">
                        {{ $isVolOnly ? '—' : $pctSigned($priceDeltaPct) }}
                      </td>
                      <td>{{ number_format($p['ytd_budget_revenue'] / 1e6, 1) }}</td>
                      <td>{{ number_format($p['ytd_actual_revenue'] / 1e6, 1) }}</td>
                      <td class="{{ $revDelta < 0 ? 'vn' : ($revDelta > 0 ? 'vp' : 'vz') }}">{{ $fmtSigned($revDelta) }}</td>
                      <td class="{{ $p['volume_effect_xaf'] < 0 ? 'vn' : ($p['volume_effect_xaf'] > 0 ? 'vp' : 'vz') }}"><b>{{ $fmtSigned($p['volume_effect_xaf']) }}</b></td>
                      <td class="{{ $p['price_effect_xaf'] < 0 ? 'vn' : ($p['price_effect_xaf'] > 0 ? 'vp' : 'vz') }}"><b>{{ $fmtSigned($p['price_effect_xaf']) }}</b></td>
                      <td class="{{ ($p['net_variance_xaf'] ?? 0) < 0 ? 'vn' : ((($p['net_variance_xaf'] ?? 0) > 0) ? 'vp' : 'vz') }}"><b>{{ $fmtSigned($p['net_variance_xaf']) }}</b></td>
                    </tr>
                  @endif
                @endforeach
              @endif
            @endforeach
            <tr class="total-row">
              <td>TOTAL · YTD</td>
              <td colspan="6"></td>
              <td>{{ number_format($tot['ytd_budget_revenue'] / 1e6, 1) }}</td>
              <td>{{ number_format($tot['ytd_actual_revenue'] / 1e6, 1) }}</td>
              <td class="{{ $tot['net_variance_xaf'] < 0 ? 'vn' : 'vp' }}">{{ $fmtSigned($tot['net_variance_xaf']) }}</td>
              <td class="{{ $tot['volume_effect_xaf'] < 0 ? 'vn' : 'vp' }}"><b>{{ $fmtSigned($tot['volume_effect_xaf']) }}</b></td>
              <td class="{{ $tot['price_effect_xaf'] < 0 ? 'vn' : 'vp' }}"><b>{{ $fmtSigned($tot['price_effect_xaf']) }}</b></td>
              <td class="{{ $tot['net_variance_xaf'] < 0 ? 'vn' : 'vp' }}"><b>{{ $fmtSigned($tot['net_variance_xaf']) }}</b></td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="v5-card__f">
        @php
          $dom = abs($tot['volume_effect_xaf']) > abs($tot['price_effect_xaf']) ? 'volume-driven' : 'price-driven';
        @endphp
        <b>Reading (tracked products only):</b>
        Net YTD revenue gap is
        <span class="{{ $tot['net_variance_xaf'] < 0 ? 'prt-neg' : 'prt-pos' }}">{{ $fmtSigned($tot['net_variance_xaf']) }} XAF</span>.
        The gap is <b>{{ $dom }}</b> —
        Volume effect <span class="{{ $tot['volume_effect_xaf'] < 0 ? 'prt-neg' : 'prt-pos' }}">{{ $fmtSigned($tot['volume_effect_xaf']) }}</span>,
        Price effect <span class="{{ $tot['price_effect_xaf'] < 0 ? 'prt-neg' : 'prt-pos' }}">{{ $fmtSigned($tot['price_effect_xaf']) }}</span>.
      </div>
    </div>

    {{-- ── Section C · Budget editor ── --}}
    <h2 class="prt-section">C · Budget editor · {{ count($products) }} products × unit price + 12 monthly volumes · auto-revenue</h2>

    <form method="POST" action="{{ tenant_url('/product-revenue-tsd/budget') }}" id="prt-editor-form">
      @csrf
      <input type="hidden" name="year" value="{{ $year }}">

      <div class="v5-card">
        <div class="v5-card__h">
          <div>
            <div class="v5-card__t">Product Volumes {{ $year }} <span style="color:#6b7280;font-weight:500;font-size:12px;">(separate from the P&amp;L budget upload)</span></div>
            <div class="v5-card__sub">Row total = unit price × Σ monthly volumes · subtotals + grand total update live · all prices are pre-VAT (HT); VAT {{ number_format($analysis['vat_rate'] * 100, 2) }}%</div>
          </div>
          <div class="prt-controls">
            <button type="button" class="prt-btn is-danger" id="prt-clear">Clear all to zero</button>
            <button type="submit" class="prt-btn is-active">Save budget</button>
          </div>
        </div>
        <div class="v5-card__b">
          <div class="prt-ed-wrap">
            <table class="prt-ed" id="prt-editor">
              <thead>
                <tr>
                  <th style="min-width:230px">Product</th>
                  <th>Unit price (XAF)</th>
                  <th>Jan</th><th>Feb</th><th>Mar</th><th>Apr</th><th>May</th><th>Jun</th>
                  <th>Jul</th><th>Aug</th><th>Sep</th><th>Oct</th><th>Nov</th><th>Dec</th>
                  <th style="min-width:130px">Annual revenue</th>
                </tr>
              </thead>
              <tbody>
                @foreach ($cats as $catKey => $catCfg)
                  @php $catProducts = $byCat->get($catKey, collect([])); @endphp
                  @if ($catProducts->isNotEmpty())
                    <tr class="cat"><td colspan="15"><span class="prt-cm prt-cm--{{ $catKey === 'other_cargo' ? 'other' : ($catKey === 'containers' ? 'cont' : ($catKey === 'vehicles' ? 'veh' : 'wood')) }}"></span>{{ strtoupper($catCfg['label']) }}</td></tr>
                    @foreach ($catProducts as $p)
                      <tr data-key="{{ $p['key'] }}" data-cat="{{ $catKey }}">
                        <td>{{ $p['label'] }}{!! $p['volume_only'] ? ' <span style="color:#6b7280;font-weight:500;font-size:10px;">· vol tracked, 0 XAF</span>' : '' !!}</td>
                        <td><input type="number" class="price" name="products[{{ $p['key'] }}][unit_price]" value="{{ $p['budget_price'] }}" min="0" step="100"></td>
                        @foreach (range(0, 11) as $i)
                          <td><input type="number" class="vol" name="products[{{ $p['key'] }}][volumes][{{ $i + 1 }}]" data-month="{{ $i + 1 }}" value="{{ (int) ($p['budget_qty'][$i] ?? 0) }}" min="0" step="1"></td>
                        @endforeach
                        <td class="total" data-out="row">—</td>
                      </tr>
                    @endforeach
                    <tr class="sub" data-cat-subtotal="{{ $catKey }}">
                      <td colspan="14" style="text-align:right">{{ $catCfg['label'] }} subtotal</td>
                      <td class="total" data-out="cat">—</td>
                    </tr>
                  @endif
                @endforeach
                <tr class="grand">
                  <td colspan="14" style="text-align:right">TOTAL BUDGETED PRODUCT REVENUE {{ $year }}</td>
                  <td class="total" id="prt-grand">—</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
        <div class="v5-card__f">
          <b>Storage:</b>
          <code style="background:#f3f4f6;padding:1px 4px;border-radius:3px;font-size:10px">tenant_budgets.data.products[product_key]</code>
          = {label, category, unit_price, volumes: {1..12}}, separate from the existing P&amp;L line keys —
          a P&amp;L budget upload won't disturb the per-product values, and vice versa.
        </div>
      </div>
    </form>

  @endif {{-- /$analysis --}}
</div>
@endsection

@if ($analysis)
@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/echarts@5.4.3/dist/echarts.min.js"></script>
<script>
(function () {
  const D = @json($analysis['products']);
  const TODAY_M = {{ (int) $analysis['today_month'] }};
  const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

  const t = {
    pri: '#0d6e6e', pos: '#047857', neg: '#b91c1c', warn: '#d97706',
    textM: '#6b7280', textF: '#9ca3af', grid: '#f3f4f6', base: '#d1d5db',
  };
  const FF = "'Segoe UI',-apple-system,BlinkMacSystemFont,system-ui,sans-serif";
  const tip = {
    trigger: 'axis', backgroundColor: '#fff', borderColor: '#e5e7eb',
    borderWidth: 1, padding: [6, 10],
    textStyle: { color: '#111827', fontSize: 11 },
    axisPointer: { type: 'line', lineStyle: { color: '#d1d5db', type: 'dashed' } },
  };

  function fmtMoney(v) {
    if (v == null) return '—';
    const a = Math.abs(v);
    if (a >= 1e9) return (v / 1e9).toFixed(2) + 'B';
    if (a >= 1e6) return (v / 1e6).toFixed(1) + 'M';
    if (a >= 1e3) return (v / 1e3).toFixed(0) + 'K';
    return v.toFixed(0);
  }
  const fmtSigned = v => v == null ? '—' : (v > 0 ? '+' : '') + (v / 1e6).toFixed(1);
  const pctSigned = v => (v == null || !isFinite(v)) ? '—' : (v > 0 ? '+' : '') + v.toFixed(1) + '%';

  /* ── Per-product small multiples ─────────────────────────────── */
  let metric = 'volume';
  let showPY = true;
  const productKeys = Object.keys(D);
  const charts = {};

  function seriesForMetric(d, m) {
    if (m === 'volume') {
      return { actual: d.actual_qty, budget: d.budget_qty,
               py: d.budget_qty.map(_ => null),  // no PY data Year 1
               fmt: v => v == null ? '—' : v + ' u' };
    }
    if (m === 'price') {
      return { actual: d.actual_price, budget: d.budget_qty.map(_ => d.budget_price),
               py: d.budget_qty.map(_ => null),
               fmt: v => v == null ? '—' : (v / 1000).toFixed(1) + 'K' };
    }
    return {
      actual: d.actual_qty.map((q, i) => q == null ? null : q * d.actual_price[i]),
      budget: d.budget_qty.map(q => q * d.budget_price),
      py:     d.budget_qty.map(_ => null),
      fmt:    v => v == null ? '—' : fmtMoney(v),
    };
  }

  function renderProduct(key) {
    const d = D[key];
    if (!d) return;
    const el = document.getElementById('prt-chart-' + key);
    if (!el) return;
    if (!charts[key]) charts[key] = echarts.init(el);
    const s = seriesForMetric(d, metric);

    const series = [];
    if (!d.pending) {
      series.push({
        name: 'Actual', type: 'line', data: s.actual,
        lineStyle: { color: d.color, width: 2.2 },
        itemStyle: { color: d.color },
        symbol: 'circle', symbolSize: 5, connectNulls: false,
        areaStyle: { color: d.color + '14' }, z: 4,
      });
    }
    series.push({
      name: 'Budget', type: 'line', data: s.budget,
      lineStyle: { color: d.color, type: 'dashed', width: 1.5 },
      itemStyle: { color: d.color }, symbol: 'none', z: 3,
    });
    if (showPY && !d.pending) {
      series.push({
        name: 'Prior year', type: 'line', data: s.py,
        lineStyle: { color: d.color, type: 'dotted', width: 1.5, opacity: 0.55 },
        itemStyle: { color: d.color, opacity: 0.55 }, symbol: 'none', z: 2,
      });
    }

    charts[key].setOption({
      textStyle: { fontFamily: FF, color: '#374151' },
      grid: { left: 38, right: 8, top: 12, bottom: 22, containLabel: false },
      tooltip: { ...tip, valueFormatter: s.fmt },
      xAxis: { type: 'category', data: months,
        axisLine: { lineStyle: { color: t.base } }, axisTick: { show: false },
        axisLabel: { color: t.textM, fontSize: 9, interval: 1 } },
      yAxis: { type: 'value', axisLine: { show: false }, axisTick: { show: false },
        splitLine: { lineStyle: { color: t.grid } },
        axisLabel: { color: t.textM, fontSize: 9, formatter: v => s.fmt(v).replace(/ u| XAF/, '') } },
      series,
    }, true);

    const reading = document.querySelector(`[data-reading="${key}"]`);
    if (reading) {
      if (d.pending) {
        const ytdBud = s.budget.slice(0, TODAY_M).reduce((a,v) => a + (v||0), 0);
        reading.innerHTML = `<b>YTD bud:</b> <span class="prt-warn">${metric === 'price' ? s.fmt(ytdBud / Math.max(1, TODAY_M)) : s.fmt(ytdBud)}</span> · actuals pending Odoo`;
      } else {
        const ytdAct = s.actual.slice(0, TODAY_M).reduce((a,v) => a + (v||0), 0);
        const ytdBud = s.budget.slice(0, TODAY_M).reduce((a,v) => a + (v||0), 0);
        const pct = ytdBud > 0 ? (100 * ytdAct / ytdBud) : null;
        const cls = pct == null ? 'prt-warn' : (pct >= 100 ? 'prt-pos' : pct >= 90 ? 'prt-warn' : 'prt-neg');
        reading.innerHTML = `<b>YTD:</b> <span class="${cls}">${pct == null ? '—' : pct.toFixed(0)}%</span> of plan
           · ${metric === 'price' ? 'avg ' + s.fmt(ytdAct / Math.max(1, TODAY_M)) : s.fmt(ytdAct)} actual
           vs ${metric === 'price' ? s.fmt(ytdBud / Math.max(1, TODAY_M)) : s.fmt(ytdBud)} budget`;
      }
    }
  }

  function renderAll() { productKeys.forEach(renderProduct); }
  renderAll();
  window.addEventListener('resize', () => {
    productKeys.forEach(k => charts[k] && charts[k].resize());
    if (total) total.resize();
  });

  document.querySelectorAll('[data-metric]').forEach(btn => {
    btn.addEventListener('click', () => {
      metric = btn.dataset.metric;
      document.querySelectorAll('[data-metric]').forEach(b => b.classList.toggle('is-active', b === btn));
      renderAll();
    });
  });
  document.getElementById('prt-py').addEventListener('change', e => { showPY = e.target.checked; renderAll(); });

  /* ── Total Revenue chart (3 lines) ───────────────────────────── */
  let showOverlayPY = true;
  const totalEl = document.getElementById('prt-chart-total');
  const total = totalEl ? echarts.init(totalEl) : null;

  function renderTotal() {
    if (!total) return;
    const trackedKeys = productKeys.filter(k => !D[k].pending);

    const sumActual = months.map((_, m) => {
      let sum = 0, hasAny = false;
      trackedKeys.forEach(key => {
        const d = D[key];
        const q = d.actual_qty[m];
        if (q != null) { sum += q * d.actual_price[m]; hasAny = true; }
      });
      return hasAny ? sum : null;
    });
    const sumBudget = months.map((_, m) => {
      let sum = 0;
      trackedKeys.forEach(key => { sum += D[key].budget_qty[m] * D[key].budget_price; });
      return sum;
    });
    const sumPY = months.map((_, m) => 0);  // no PY Year 1

    const series = [
      { name: 'Actual', type: 'line', data: sumActual,
        lineStyle: { color: t.pri, width: 2.5 }, itemStyle: { color: t.pri },
        symbol: 'circle', symbolSize: 6, connectNulls: false,
        areaStyle: { color: 'rgba(13,110,110,0.10)' }, z: 4 },
      { name: 'Budget', type: 'line', data: sumBudget,
        lineStyle: { color: t.warn, type: 'dashed', width: 1.8 },
        itemStyle: { color: t.warn }, symbol: 'none', z: 3 },
    ];

    total.setOption({
      textStyle: { fontFamily: FF, color: '#374151' },
      grid: { left: 80, right: 20, top: 32, bottom: 28, containLabel: false },
      legend: { top: 0, right: 12, icon: 'roundRect',
        itemWidth: 14, itemHeight: 4, itemGap: 14,
        textStyle: { fontSize: 11, color: t.textM, fontFamily: FF } },
      tooltip: { ...tip, valueFormatter: v => v == null ? '—' : fmtMoney(v) + ' XAF' },
      xAxis: { type: 'category', data: months,
        axisLine: { lineStyle: { color: t.base } }, axisTick: { show: false },
        axisLabel: { color: t.textM, fontSize: 11 } },
      yAxis: { type: 'value', axisLine: { show: false }, axisTick: { show: false },
        splitLine: { lineStyle: { color: t.grid } },
        axisLabel: { color: t.textM, fontSize: 10, formatter: v => fmtMoney(v) } },
      series,
    }, true);

    const ytdAct = sumActual.slice(0, TODAY_M).reduce((a,v) => a + (v||0), 0);
    const ytdBud = sumBudget.slice(0, TODAY_M).reduce((a,v) => a + v, 0);
    const gap = ytdAct - ytdBud;
    const pct = ytdBud > 0 ? 100 * gap / ytdBud : 0;
    document.getElementById('prt-total-reading').innerHTML = `
      <b>YTD totals (${TODAY_M} mo, tracked products):</b>
      Actual <b>${fmtMoney(ytdAct)} XAF</b> ·
      Budget <b>${fmtMoney(ytdBud)} XAF</b> ·
      Gap <span class="${gap < 0 ? 'prt-neg' : 'prt-pos'}">${fmtSigned(gap)}M (${pctSigned(pct)})</span>.
      Per-product breakdown in Section A above; variance decomposition in Section B'.`;
  }
  renderTotal();
  document.getElementById('prt-overlay-py').addEventListener('change', e => {
    showOverlayPY = e.target.checked;
    renderTotal();
  });

  /* ── Budget editor live totals ───────────────────────────────── */
  function recalcEditor() {
    const subtotals = {};
    let grand = 0;
    document.querySelectorAll('#prt-editor tbody tr[data-key]').forEach(row => {
      const cat = row.dataset.cat;
      const price = parseFloat(row.querySelector('input.price').value || 0);
      let vol = 0;
      row.querySelectorAll('input.vol').forEach(i => { vol += parseFloat(i.value || 0); });
      const rev = price * vol;
      row.querySelector('[data-out=row]').textContent = rev.toLocaleString() + ' XAF';
      subtotals[cat] = (subtotals[cat] || 0) + rev;
      grand += rev;
    });
    document.querySelectorAll('#prt-editor tr[data-cat-subtotal]').forEach(row => {
      const cat = row.dataset.catSubtotal;
      row.querySelector('[data-out=cat]').textContent = (subtotals[cat] || 0).toLocaleString() + ' XAF';
    });
    document.getElementById('prt-grand').textContent = grand.toLocaleString() + ' XAF';
  }
  recalcEditor();
  document.querySelectorAll('#prt-editor input').forEach(i => i.addEventListener('input', recalcEditor));
  document.getElementById('prt-clear').addEventListener('click', () => {
    if (!confirm('Reset every input to 0?')) return;
    document.querySelectorAll('#prt-editor input').forEach(i => { i.value = 0; });
    recalcEditor();
  });
})();
</script>
@endpush
@endif
