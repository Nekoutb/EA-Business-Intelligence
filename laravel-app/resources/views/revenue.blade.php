@extends('layouts.app')
@section('title', 'Revenue Analysis')
@section('page-title', 'Revenue Analysis')

{{--
    Revenue Analysis — v5 design.

    Layout: sticky head (topbar from layout · summary band · 6-cell KPI
    strip) + scrollable grid (trajectory + composition · cost ratios ·
    profitability tables · top discounted). Master period filter lives
    in the sidebar.

    Data shape (RevenueController::index):
      $data['current']/['previous']/['budget']/['yoy_growth_pct'] etc.
      $profitability['most_profitable']/['least_profitable']
      $topDiscounted (top-5 by discount amount)
      $period, $periodLabel, $error
--}}

@push('styles')
<style>
  /* Page-local v5 layout — extends shell tokens */
  .rv-summary { background: var(--surface); border: 1px solid var(--border); border-left: 3px solid var(--ea-primary); border-radius: var(--r); padding: 6px 12px; margin-bottom: 8px; display: flex; align-items: center; gap: 12px; font: 500 12px var(--font); color: var(--text-muted); min-height: 30px; flex-wrap: wrap; }
  .rv-summary__l { font: 700 10px var(--font); color: var(--ea-primary); letter-spacing: .1em; text-transform: uppercase; flex-shrink: 0; }
  .rv-summary b { color: var(--text); font-weight: 700; }
  .rv-summary .pos { color: var(--pos); font-weight: 700; }
  .rv-summary .neg { color: var(--neg); font-weight: 700; }
  .rv-summary .neutral { color: var(--warn); font-weight: 700; }

  .rv-strip { display: grid; grid-template-columns: repeat(6, 1fr); background: var(--surface); border: 1px solid var(--border); border-radius: var(--r); margin-bottom: 8px; overflow: hidden; }
  .rv-strip__c { padding: 6px 10px; border-right: 1px solid var(--border-faint); }
  .rv-strip__c:last-child { border-right: 0; }
  .rv-strip__l { font: 700 9px var(--font); color: var(--text-subtle); letter-spacing: .06em; text-transform: uppercase; margin-bottom: 2px; }
  .rv-strip__v { font: 700 14px var(--font); color: var(--text); line-height: 1; letter-spacing: -.01em; }
  .rv-strip__v.is-pos { color: var(--pos); }
  .rv-strip__v.is-neg { color: var(--neg); }
  .rv-strip__v .u { font: 500 10px var(--font); color: var(--text-subtle); margin-left: 1px; }
  .rv-strip__m { font: 500 9px var(--font); color: var(--text-subtle); margin-top: 2px; }
  .rv-strip__d { display: inline-flex; align-items: center; gap: 2px; padding: 1px 4px; border-radius: 3px; font: 700 9px var(--font); margin-left: 4px; vertical-align: 1px; }
  .rv-strip__d.up { color: var(--pos); background: #ecfdf5; }
  .rv-strip__d.down { color: var(--neg); background: #fef2f2; }

  .rv-grid { display: grid; gap: 8px; grid-template-columns: repeat(12, 1fr); margin-bottom: 8px; }
  .rv-grid .span-12 { grid-column: span 12; }
  .rv-grid .span-8 { grid-column: span 8; }
  .rv-grid .span-6 { grid-column: span 6; }
  .rv-grid .span-4 { grid-column: span 4; }

  .rv-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-lg); padding: 10px 12px; display: flex; flex-direction: column; }
  .rv-card__h { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; margin-bottom: 6px; }
  .rv-card__t { font: 700 12px var(--font); color: var(--text); display: inline-flex; align-items: center; gap: 6px; }
  .rv-card__t .stripe { display: inline-block; width: 3px; height: 14px; border-radius: 2px; }
  .rv-card__t .stripe.pos { background: var(--pos); }
  .rv-card__t .stripe.neg { background: var(--neg); }
  .rv-card__t .stripe.warn { background: var(--warn); }
  .rv-card__sub { font: 400 10.5px var(--font); color: var(--text-subtle); margin-top: 1px; }
  .rv-card__leg { display: flex; gap: 10px; font: 500 10px var(--font); color: var(--text-subtle); flex-wrap: wrap; }
  .rv-card__leg > span { display: inline-flex; align-items: center; gap: 4px; }
  .lline { width: 12px; height: 2px; }
  .rv-card__b { width: 100%; position: relative; }
  .rv-card__f { font: 500 10px var(--font); color: var(--text-subtle); margin-top: 6px; padding-top: 6px; border-top: 1px solid var(--border-faint); line-height: 1.4; }
  .rv-card__f b { color: var(--text); font-weight: 600; }
  .rv-card__f .pos { color: var(--pos); font-weight: 600; }
  .rv-card__f .neg { color: var(--neg); font-weight: 600; }
  .rv-card__f .warn { color: var(--warn); font-weight: 600; }

  .rv-chart-280 { height: 280px; }

  /* v5 table */
  table.rv-tab { width: 100%; border-collapse: collapse; font: 500 12px var(--font); }
  table.rv-tab thead tr { border-bottom: 1px solid var(--border); }
  table.rv-tab th { font: 700 9px var(--font); color: var(--text-subtle); letter-spacing: .06em; text-transform: uppercase; padding: 6px 8px; text-align: left; }
  table.rv-tab th.r, table.rv-tab td.r { text-align: right; }
  table.rv-tab td { padding: 7px 8px; border-bottom: 1px solid var(--border-faint); font: 500 12px var(--font); color: var(--text-muted); vertical-align: top; }
  table.rv-tab tr:last-child td { border-bottom: 0; }
  table.rv-tab tr:hover td { background: var(--surface-alt); }
  table.rv-tab td .name { font: 600 12px var(--font); color: var(--text); display: block; line-height: 1.2; }
  table.rv-tab td .meta { font: 500 9.5px var(--font); color: var(--text-faint); margin-top: 1px; }
  .rv-chip { display: inline-flex; align-items: center; padding: 1px 6px; border-radius: 999px; font: 700 9.5px var(--font); }
  .rv-chip--pos { color: var(--pos); background: #ecfdf5; border: 1px solid #a7f3d0; }
  .rv-chip--neg { color: var(--neg); background: #fef2f2; border: 1px solid #fecaca; }
  .rv-chip--warn { color: var(--warn); background: var(--warn-bg); border: 1px solid var(--warn-bd); }

  /* Cost-ratio bars */
  .rv-cost { padding: 6px 0; }
  .rv-cost__row { display: flex; align-items: center; gap: 10px; padding: 4px 0; }
  .rv-cost__l { width: 110px; flex-shrink: 0; font: 700 10px var(--font); color: var(--text-subtle); letter-spacing: .04em; text-transform: uppercase; }
  .rv-cost__t { flex: 1; height: 18px; background: var(--border-faint); border-radius: var(--r-sm); position: relative; overflow: hidden; }
  .rv-cost__f { height: 100%; background: var(--ea-primary); display: flex; align-items: center; padding-left: 8px; color: #fff; font: 700 10px var(--font); }
  .rv-cost__f.warn { background: var(--warn); }
  .rv-cost__m { position: absolute; top: -2px; bottom: -2px; width: 2px; background: var(--text); }
  .rv-cost__v { width: 100px; flex-shrink: 0; font: 700 11px var(--font); color: var(--text); text-align: right; }
  .rv-cost__v small { color: var(--text-faint); font-weight: 500; font-size: 9px; margin-left: 2px; }

  .rv-empty { padding: 24px; text-align: center; color: var(--text-faint); font: 500 11px var(--font); }
  .rv-error { padding: 12px 16px; background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; border-radius: var(--r); font-size: 12px; margin-bottom: 8px; }
</style>
@endpush

@section('content')

@if ($error)
  <div class="rv-error">
    <strong>Error:</strong> {{ $error }} ·
    <a href="{{ tenant_url('/settings') }}" style="color:#991b1b; text-decoration:underline;">Check Odoo settings</a>
  </div>
@elseif (!$data)
  <div class="rv-empty">Loading revenue data…</div>
@else

@php
  // ── Defensive shape pulls ──
  $cur          = $data['current']        ?? [];
  $prev         = $data['previous']       ?? [];
  $ccy          = $data['currency']       ?? 'XAF';
  $yoy          = $data['yoy_growth_pct'] ?? null;
  $budget       = $data['budget']         ?? [];
  $budgetTotals = $budget['totals']       ?? [];
  $budgetSeries = $budget['series']       ?? [];

  $safeDiv = function ($num, $den) {
      if (!is_numeric($num) || !is_numeric($den) || (float) $den === 0.0) return null;
      return (float) $num / (float) $den;
  };
  $variance = function ($current, $reference) {
      if (!is_numeric($current) || !is_numeric($reference)) return null;
      if ((float) $reference === 0.0) return (float) $current === 0.0 ? 0.0 : null;
      return (($current - $reference) / abs($reference)) * 100.0;
  };

  $planRevenue   = $budgetTotals['revenue']   ?? null;

  // Helpers — short formatters consistent with dashboard
  $shortNum = function (float $v) {
      $a = abs($v);
      if ($a >= 1_000_000) return number_format($v / 1_000_000, 2);
      if ($a >= 1_000)     return number_format($v / 1_000, 0);
      return number_format($v, 0);
  };
  $shortUnit = function (float $v) {
      $a = abs($v); return ($a >= 1_000_000 ? 'M' : ($a >= 1_000 ? 'K' : ''));
  };
  $signed = fn ($v, int $dec = 1) => ($v === null ? '—' : (($v >= 0 ? '+' : '') . number_format($v, $dec) . '%'));

  // KPI delta classes
  $deltaCls = fn ($v, $preferDown = false) => $v === null ? '' : ((($preferDown ? -$v : $v) >= 0) ? 'up' : 'down');

  // Derived ratios for cost pressure
  $rpoCur = (float) ($cur['rpo'] ?? 0);
  $rpcCur = (float) ($cur['per_customer'] ?? 0);
  $cpoR   = $rpoCur > 0 ? 100.0 * ((float) ($cur['cpo']   ?? 0)) / $rpoCur : 0.0;
  $dpotR  = $rpoCur > 0 ? 100.0 * ((float) ($cur['dp_ot'] ?? 0)) / $rpoCur : 0.0;
  $dpcR   = $rpcCur > 0 ? 100.0 * ((float) ($cur['dp_c']  ?? 0)) / $rpcCur : 0.0;

  // Top SKU pull-out for the summary band
  $top = $profitability['most_profitable'][0] ?? null;
  $totalRev = (float) ($cur['total'] ?? 0);
  $topShare = ($top && $totalRev > 0) ? 100.0 * ((float) $top['revenue']) / $totalRev : null;

  // ── Trajectory: cumulative monthly buckets from the daily series.
  // Same algorithm as the dashboard so the two pages stay consistent.
  $dailySeries = $cur['series'] ?? [];
  $monthlyActual = array_fill(1, 12, 0.0);
  foreach ($dailySeries as $date => $val) {
      try {
          $m = (int) (new \DateTimeImmutable($date))->format('n');
          if ($m >= 1 && $m <= 12) $monthlyActual[$m] += (float) $val;
      } catch (\Throwable $e) { /* skip */ }
  }
  $cumActual = []; $running = 0.0;
  for ($mm = 1; $mm <= 12; $mm++) {
      if ($monthlyActual[$mm] > 0) { $running += $monthlyActual[$mm]; $cumActual[$mm] = $running; }
      else                          { $cumActual[$mm] = null; }
  }
  // Cumulative budget (per-day series → monthly → cumulative)
  $monthlyBudget = array_fill(1, 12, 0.0);
  foreach ($budgetSeries as $date => $val) {
      try {
          $m = (int) (new \DateTimeImmutable($date))->format('n');
          if ($m >= 1 && $m <= 12) $monthlyBudget[$m] += (float) $val;
      } catch (\Throwable $e) { /* skip */ }
  }
  $cumBudget = []; $rb = 0.0;
  for ($mm = 1; $mm <= 12; $mm++) {
      if ($monthlyBudget[$mm] > 0) { $rb += $monthlyBudget[$mm]; $cumBudget[$mm] = $rb; }
      else                         { $cumBudget[$mm] = null; }
  }
  // Linear pace (actual / WD-so-far × cumulative-WD-by-month)
  $year = (int) ($period['year'] ?? date('Y'));
  $wdByMonth = [];
  for ($mm = 1; $mm <= 12; $mm++) {
      $ms = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $mm));
      $nm = $ms->modify('first day of next month');
      $w = 0;
      for ($d = $ms; $d < $nm; $d = $d->modify('+1 day')) if ((int) $d->format('N') < 7) $w++;
      $wdByMonth[$mm] = $w;
  }
  $wdSoFar = max(1, (int) ($cur['working_days'] ?? 1));
  $dailyPace = $wdSoFar > 0 ? $totalRev / $wdSoFar : 0.0;
  $cumLinear = []; $cw = 0;
  for ($mm = 1; $mm <= 12; $mm++) { $cw += $wdByMonth[$mm]; $cumLinear[$mm] = $dailyPace * $cw; }

  // ── Composition: top-5 SKUs + "Other" from $profitability
  $compRows = [];
  $other = 0.0;
  if (!empty($profitability['most_profitable'])) {
      // Sort by revenue (the most-profitable list is already by margin; we want by revenue here)
      $byRev = $profitability['most_profitable'];
      usort($byRev, fn ($a, $b) => ($b['revenue'] ?? 0) <=> ($a['revenue'] ?? 0));
      $top5 = array_slice($byRev, 0, 5);
      foreach ($top5 as $r) {
          $compRows[] = ['name' => $r['name'], 'value' => (float) ($r['revenue'] ?? 0)];
      }
      $top5Sum = array_sum(array_column($compRows, 'value'));
      $other = max(0, $totalRev - $top5Sum);
  }

  // JS payloads (built as PHP variables — never inline multi-line @json args)
  $ecTrajectory = [
      'months'  => ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'],
      'actual'  => array_values($cumActual),
      'linear'  => array_values($cumLinear),
      'budget'  => $planRevenue ? array_values($cumBudget) : [],
  ];
  $ecComposition = [];
  foreach ($compRows as $r) $ecComposition[] = ['name' => $r['name'], 'value' => $r['value']];
  if ($other > 0) $ecComposition[] = ['name' => 'Other', 'value' => $other];
@endphp

{{-- Summary band ─────────────────────────────────────────────── --}}
<div class="rv-summary">
    <span class="rv-summary__l">In 5 sec</span>
    <span>
        Revenue <b>{{ $shortNum($totalRev) }}{{ $shortUnit($totalRev) }} {{ $ccy }} {{ $periodLabel ?? 'YTD' }}</b>
        @if ($yoy !== null)
          · <span class="{{ $yoy >= 0 ? 'pos' : 'neg' }}">{{ $signed($yoy, 1) }} vs PY</span>
        @endif
        · RpD pace <b>{{ $shortNum((float) ($cur['rpd'] ?? 0)) }}{{ $shortUnit((float) ($cur['rpd'] ?? 0)) }} ({{ $wdSoFar }} WD)</b>.
        @if ($top && $topShare !== null)
          Top SKU <b>{{ \Illuminate\Support\Str::limit($top['name'], 28) }}</b> contributes <b>{{ number_format($topShare, 1) }}%</b>.
        @endif
        @if ($cpoR > 0)
          Watch: <span class="{{ $cpoR > 35 ? 'neutral' : 'pos' }}">CpO {{ number_format($cpoR, 0) }}% of RpO</span> (target ≤ 35%).
        @endif
    </span>
</div>

{{-- KPI strip ────────────────────────────────────────────────── --}}
<div class="rv-strip">
    <div class="rv-strip__c">
        <div class="rv-strip__l">Revenue {{ ($period['type'] ?? 'ytd') === 'mtd' ? 'MTD' : ($periodLabel ?? 'YTD') }}</div>
        <div class="rv-strip__v">
          {{ $shortNum($totalRev) }}<span class="u">{{ $shortUnit($totalRev) }} {{ $ccy }}</span>
          @if ($yoy !== null)<span class="rv-strip__d {{ $deltaCls($yoy) }}">{{ $signed($yoy, 1) }}</span>@endif
        </div>
        <div class="rv-strip__m">{{ number_format((int) ($cur['customers'] ?? 0)) }} customers</div>
    </div>
    <div class="rv-strip__c">
        <div class="rv-strip__l">RpD · Rev / Day</div>
        @php $d = $variance($cur['rpd'] ?? null, $prev['rpd'] ?? null); @endphp
        <div class="rv-strip__v">{{ $shortNum((float) ($cur['rpd'] ?? 0)) }}<span class="u">{{ $shortUnit((float) ($cur['rpd'] ?? 0)) }}</span>@if($d!==null)<span class="rv-strip__d {{ $deltaCls($d) }}">{{ $signed($d, 1) }}</span>@endif</div>
        <div class="rv-strip__m">{{ $wdSoFar }} working days</div>
    </div>
    <div class="rv-strip__c">
        <div class="rv-strip__l">OpD · Outfit / Day</div>
        @php $d = $variance($cur['opd'] ?? null, $prev['opd'] ?? null); @endphp
        <div class="rv-strip__v">{{ number_format((float) ($cur['opd'] ?? 0), 1) }}@if($d!==null)<span class="rv-strip__d {{ $deltaCls($d) }}">{{ $signed($d, 1) }}</span>@endif</div>
        <div class="rv-strip__m">{{ number_format((int) ($cur['outfits'] ?? 0)) }} outfits</div>
    </div>
    <div class="rv-strip__c">
        <div class="rv-strip__l">RpO · Rev / Outfit</div>
        @php $d = $variance($cur['rpo'] ?? null, $prev['rpo'] ?? null); @endphp
        <div class="rv-strip__v">{{ $shortNum((float) ($cur['rpo'] ?? 0)) }}<span class="u">{{ $shortUnit((float) ($cur['rpo'] ?? 0)) }}</span>@if($d!==null)<span class="rv-strip__d {{ $deltaCls($d) }}">{{ $signed($d, 1) }}</span>@endif</div>
        <div class="rv-strip__m">unit price</div>
    </div>
    <div class="rv-strip__c">
        <div class="rv-strip__l">RpC · Rev / Customer</div>
        @php $d = $variance($cur['per_customer'] ?? null, $prev['per_customer'] ?? null); @endphp
        <div class="rv-strip__v">{{ $shortNum((float) ($cur['per_customer'] ?? 0)) }}<span class="u">{{ $shortUnit((float) ($cur['per_customer'] ?? 0)) }}</span>@if($d!==null)<span class="rv-strip__d {{ $deltaCls($d) }}">{{ $signed($d, 1) }}</span>@endif</div>
        <div class="rv-strip__m">{{ number_format((int) ($cur['customers'] ?? 0)) }} customers</div>
    </div>
    <div class="rv-strip__c">
        <div class="rv-strip__l">CpO · Cost / Outfit</div>
        @php $d = $variance($cur['cpo'] ?? null, $prev['cpo'] ?? null); @endphp
        <div class="rv-strip__v {{ $cpoR > 35 ? 'is-neg' : '' }}">{{ $shortNum((float) ($cur['cpo'] ?? 0)) }}<span class="u">{{ $shortUnit((float) ($cur['cpo'] ?? 0)) }}</span>@if($d!==null)<span class="rv-strip__d {{ $deltaCls($d, true) }}">{{ $signed($d, 1) }}</span>@endif</div>
        <div class="rv-strip__m">{{ number_format($cpoR, 0) }}% of RpO · target ≤ 35%</div>
    </div>
</div>

{{-- Grid ─────────────────────────────────────────────────────── --}}
<div class="rv-grid">

    {{-- Trajectory (span 8) --}}
    <div class="rv-card span-8">
        <div class="rv-card__h">
            <div>
                <div class="rv-card__t">Cumulative Revenue · Linear pace @if($planRevenue) · Budget @endif</div>
                <div class="rv-card__sub">{{ $periodLabel ?? 'YTD' }} {{ $year }} · {{ $ccy }}</div>
            </div>
            <div class="rv-card__leg">
                <span><span class="lline" style="background:var(--ea-primary);"></span>Actual</span>
                <span><span class="lline" style="background:var(--text-faint);"></span>Linear pace</span>
                @if ($planRevenue)
                  <span><span class="lline" style="background:var(--warn);"></span>Budget</span>
                @endif
            </div>
        </div>
        <div class="rv-card__b rv-chart-280" id="ec-rv-trajectory">
            @if (empty($dailySeries))<div class="rv-empty">No revenue series yet.</div>@endif
        </div>
        <div class="rv-card__f">
            <b>Reading:</b> Daily run-rate <b>{{ $shortNum($dailyPace) }}{{ $shortUnit($dailyPace) }} {{ $ccy }}</b>
            @if ($yoy !== null) · vs PY <span class="{{ $yoy >= 0 ? 'pos' : 'neg' }}">{{ $signed($yoy, 1) }}</span>@endif
            @if (!$planRevenue) · <span class="warn">budget feed pending</span>@endif.
        </div>
    </div>

    {{-- Composition donut (span 4) --}}
    <div class="rv-card span-4">
        <div class="rv-card__h">
            <div>
                <div class="rv-card__t">Revenue Composition</div>
                <div class="rv-card__sub">Top 5 SKUs + Other · YTD share</div>
            </div>
        </div>
        <div class="rv-card__b rv-chart-280" id="ec-rv-composition">
            @if (empty($ecComposition))<div class="rv-empty">No product breakdown yet.</div>@endif
        </div>
        <div class="rv-card__f">
            @if (!empty($ecComposition))
              <b>Reading:</b> Top {{ count($compRows) }} SKUs drive
              {{ $totalRev > 0 ? number_format(100 * array_sum(array_column($compRows, 'value')) / $totalRev, 0) : '—' }}% of revenue.
            @else
              <b>Reading:</b> Run sync to compute SKU breakdown.
            @endif
        </div>
    </div>

    {{-- Cost & Discount Ratios (span 12) --}}
    <div class="rv-card span-12">
        <div class="rv-card__h">
            <div>
                <div class="rv-card__t">Cost &amp; Discount Ratios · vs target</div>
                <div class="rv-card__sub">Per unit-revenue basis · CpO / RpO, DpOT / RpO, DpC / RpC</div>
            </div>
        </div>
        <div class="rv-card__b rv-cost">
            <div class="rv-cost__row">
                <div class="rv-cost__l">CpO / RpO</div>
                <div class="rv-cost__t">
                    <div class="rv-cost__f {{ $cpoR > 35 ? 'warn' : '' }}" style="width: {{ min(100, $cpoR / 60 * 100) }}%;">{{ number_format($cpoR, 0) }}%</div>
                    <div class="rv-cost__m" style="left: {{ min(100, 35 / 60 * 100) }}%;" title="Target ≤ 35%"></div>
                </div>
                <div class="rv-cost__v">{{ number_format($cpoR, 1) }}%<small>· 35% tgt</small></div>
            </div>
            <div class="rv-cost__row">
                <div class="rv-cost__l">DpOT / RpO</div>
                <div class="rv-cost__t">
                    <div class="rv-cost__f {{ $dpotR > 15 ? 'warn' : '' }}" style="width: {{ min(100, $dpotR / 60 * 100) }}%;">{{ number_format($dpotR, 0) }}%</div>
                    <div class="rv-cost__m" style="left: {{ min(100, 15 / 60 * 100) }}%;" title="Target ≤ 15%"></div>
                </div>
                <div class="rv-cost__v">{{ number_format($dpotR, 1) }}%<small>· 15% tgt</small></div>
            </div>
            <div class="rv-cost__row">
                <div class="rv-cost__l">DpC / RpC</div>
                <div class="rv-cost__t">
                    <div class="rv-cost__f {{ $dpcR > 15 ? 'warn' : '' }}" style="width: {{ min(100, $dpcR / 60 * 100) }}%;">{{ number_format($dpcR, 0) }}%</div>
                    <div class="rv-cost__m" style="left: {{ min(100, 15 / 60 * 100) }}%;" title="Target ≤ 15%"></div>
                </div>
                <div class="rv-cost__v">{{ number_format($dpcR, 1) }}%<small>· 15% tgt</small></div>
            </div>
        </div>
        <div class="rv-card__f">
            <b>Reading:</b>
            @if ($cpoR > 35)
              <span class="warn">CpO at {{ number_format($cpoR, 0) }}% — {{ number_format($cpoR - 35, 0) }} pts above target</span>. Cost discipline is the lever.
            @elseif ($cpoR > 0)
              <span class="pos">CpO inside target band</span>. Discounting is well-controlled too.
            @else
              Run sync to populate cost ratios.
            @endif
        </div>
    </div>

    {{-- Profitability tables --}}
    @php
        $mostProfitable  = $profitability['most_profitable']  ?? [];
        $leastProfitable = $profitability['least_profitable'] ?? [];
        $fmtM = function (float $v): string {
            $sign = $v < 0 ? '−' : '';
            return $sign . number_format(round(abs($v) / 1e6, 2), 2) . 'M';
        };
    @endphp

    @if (!empty($mostProfitable))
    <div class="rv-card span-6">
        <div class="rv-card__h">
            <div>
                <div class="rv-card__t"><span class="stripe pos"></span>Top {{ count($mostProfitable) }} Most Profitable Products</div>
                <div class="rv-card__sub">By margin (sales price vs standard cost)</div>
            </div>
            <span style="font: 500 9.5px var(--font); color: var(--text-faint);">Amounts in {{ $ccy }} · M</span>
        </div>
        <div class="rv-card__b">
            <table class="rv-tab">
                <thead>
                    <tr><th>Product</th><th class="r">Revenue</th><th class="r">Cost</th><th class="r">Margin</th><th class="r">Margin %</th></tr>
                </thead>
                <tbody>
                    @foreach ($mostProfitable as $p)
                      <tr>
                          <td><span class="name">{{ $p['name'] }}</span><span class="meta">Qty: {{ number_format((float) $p['qty'], 0) }}</span></td>
                          <td class="r">{{ $fmtM((float) $p['revenue']) }}</td>
                          <td class="r" style="color:var(--text-faint);">{{ $fmtM((float) $p['cost']) }}</td>
                          <td class="r" style="color:var(--pos); font-weight:700;">{{ $fmtM((float) $p['margin']) }}</td>
                          <td class="r"><span class="rv-chip rv-chip--pos">{{ number_format((float) $p['margin_pct'], 1) }}%</span></td>
                      </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="rv-card__f"><b>Reading:</b> Protect pricing on these — they're the margin engine.</div>
    </div>
    @endif

    @if (!empty($leastProfitable))
    <div class="rv-card span-{{ !empty($mostProfitable) ? '6' : '12' }}">
        <div class="rv-card__h">
            <div>
                <div class="rv-card__t"><span class="stripe neg"></span>Top {{ count($leastProfitable) }} Least Profitable Products</div>
                <div class="rv-card__sub">Lowest / negative margin (sales price vs standard cost)</div>
            </div>
            <span style="font: 500 9.5px var(--font); color: var(--text-faint);">Amounts in {{ $ccy }} · M</span>
        </div>
        <div class="rv-card__b">
            <table class="rv-tab">
                <thead>
                    <tr><th>Product</th><th class="r">Revenue</th><th class="r">Cost</th><th class="r">Margin</th><th class="r">Margin %</th></tr>
                </thead>
                <tbody>
                    @foreach ($leastProfitable as $p)
                      @php $negMargin = ((float) $p['margin']) < 0; @endphp
                      <tr>
                          <td><span class="name">{{ $p['name'] }}</span><span class="meta">Qty: {{ number_format((float) $p['qty'], 0) }}</span></td>
                          <td class="r">{{ $fmtM((float) $p['revenue']) }}</td>
                          <td class="r" style="color:var(--text-faint);">{{ $fmtM((float) $p['cost']) }}</td>
                          <td class="r" style="color:{{ $negMargin ? 'var(--neg)' : 'var(--warn)' }}; font-weight:700;">{{ $fmtM((float) $p['margin']) }}</td>
                          <td class="r"><span class="rv-chip {{ $negMargin ? 'rv-chip--neg' : 'rv-chip--warn' }}">{{ number_format((float) $p['margin_pct'], 1) }}%</span></td>
                      </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="rv-card__f"><b>Reading:</b> Re-price the negatives or unbundle. Watch the band-edges next month.</div>
    </div>
    @endif

    {{-- Top discounted (span 12) --}}
    @if (!empty($topDiscounted))
    <div class="rv-card span-12">
        <div class="rv-card__h">
            <div>
                <div class="rv-card__t"><span class="stripe warn"></span>Top {{ count($topDiscounted) }} Most Discounted Products</div>
                <div class="rv-card__sub">Ranked by total discount amount granted · {{ $periodLabel ?? 'YTD' }}</div>
            </div>
            <span style="font: 500 9.5px var(--font); color: var(--text-faint);">Amounts in {{ $ccy }} · M</span>
        </div>
        <div class="rv-card__b">
            <table class="rv-tab">
                <thead>
                    <tr><th>Product</th><th class="r">Discount Amount</th><th class="r">Avg Discount %</th><th class="r">Lines</th><th class="r">% of Revenue</th></tr>
                </thead>
                <tbody>
                    @foreach ($topDiscounted as $d)
                      @php $shareOfRev = $totalRev > 0 ? 100.0 * ((float) $d['discount_amount']) / $totalRev : null; @endphp
                      <tr>
                          <td><span class="name">{{ $d['name'] }}</span><span class="meta">Qty: {{ number_format((float) ($d['qty'] ?? 0), 0) }}</span></td>
                          <td class="r" style="color:var(--warn); font-weight:700;">{{ $fmtM((float) $d['discount_amount']) }}</td>
                          <td class="r"><span class="rv-chip rv-chip--warn">{{ number_format((float) $d['avg_discount_pct'], 1) }}%</span></td>
                          <td class="r" style="color:var(--text-muted);">{{ number_format((float) ($d['lines'] ?? 0)) }}</td>
                          <td class="r" style="color:var(--text-faint);">{{ $shareOfRev !== null ? number_format($shareOfRev, 1) . '%' : '—' }}</td>
                      </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="rv-card__f"><b>Reading:</b> Verify each volume threshold is still earning incremental margin.</div>
    </div>
    @endif

</div>

@push('scripts')
<script>
(function () {
  const t = { pri:'#0d6e6e', pos:'#047857', neg:'#b91c1c', warn:'#d97706', warnLt:'#fbbf24', textM:'#6b7280', textF:'#9ca3af', grid:'#f3f4f6', base:'#d1d5db', area:'rgba(13,110,110,0.10)' };
  const FF = "'Segoe UI',-apple-system,BlinkMacSystemFont,system-ui,sans-serif";
  const tip = { trigger:'axis', backgroundColor:'#fff', borderColor:'#e5e7eb', borderWidth:1, padding:[6,10], textStyle:{color:'#111827',fontSize:11}, axisPointer:{type:'shadow'} };
  function fmt(v) { if (v==null) return '—'; const a=Math.abs(v); if(a>=1e6) return (v/1e6).toFixed(1).replace(/\.0$/,'')+'M'; if(a>=1e3) return (v/1e3).toFixed(0)+'K'; return v.toString(); }

  /* Trajectory */
  const trEl = document.getElementById('ec-rv-trajectory');
  const tr = @json($ecTrajectory);
  if (trEl && tr.actual.some(v => v != null)) {
    const series = [
      { name:'Actual', type:'line', data: tr.actual, lineStyle:{ color: t.pri, width: 2 }, itemStyle:{ color: t.pri }, areaStyle:{ color: t.area }, symbol:'circle', symbolSize: 5 },
      { name:'Linear', type:'line', data: tr.linear, lineStyle:{ color: t.textF, type:'dashed', width: 1.5 }, symbol:'none' },
    ];
    if (tr.budget && tr.budget.length) {
      series.push({ name:'Budget', type:'line', data: tr.budget, lineStyle:{ color: t.warn, type:'dashed', width: 1.5 }, symbol:'none' });
    }
    echarts.init(trEl).setOption({
      textStyle: { fontFamily: FF, color:'#374151' },
      grid: { left: 48, right: 16, top: 10, bottom: 24, containLabel: false },
      tooltip: tip,
      xAxis: { type:'category', data: tr.months, axisLine:{ lineStyle:{ color: t.base } }, axisTick:{ show:false }, axisLabel:{ color: t.textM, fontSize: 10 } },
      yAxis: { type:'value', axisLine:{ show:false }, axisTick:{ show:false }, splitLine:{ lineStyle:{ color: t.grid } }, axisLabel:{ color: t.textM, fontSize: 10, formatter: v => fmt(v) } },
      series: series,
    });
  }

  /* Composition donut */
  const cmEl = document.getElementById('ec-rv-composition');
  const cm = @json($ecComposition);
  if (cmEl && cm.length) {
    const palette = [t.pri, '#338f8f', '#66abab', '#99c7c7', '#cce3e3', '#e5e7eb'];
    const data = cm.map((d, i) => ({ ...d, itemStyle: { color: palette[i % palette.length] } }));
    echarts.init(cmEl).setOption({
      textStyle: { fontFamily: FF },
      tooltip: { trigger:'item', backgroundColor:'#fff', borderColor:'#e5e7eb', borderWidth:1, textStyle:{color:'#111827', fontSize:11},
        formatter: p => `<b>${p.name}</b>: ${fmt(p.value)} (${p.percent}%)` },
      legend: { orient:'vertical', right: 4, top: 'middle', icon: 'circle', itemWidth: 8, itemHeight: 8, textStyle: { color: '#6b7280', fontSize: 10, fontFamily: FF } },
      series: [{
        type: 'pie', radius: ['52%', '76%'], center: ['38%', '50%'], avoidLabelOverlap: false, label: { show: false }, labelLine: { show: false },
        data: data,
      }],
    });
  }

  function resizeAll() {
    document.querySelectorAll('[id^="ec-rv-"]').forEach(el => { const i = echarts.getInstanceByDom(el); if (i) i.resize(); });
  }
  window.addEventListener('resize', resizeAll);
  setTimeout(resizeAll, 100);
})();
</script>
@endpush

@endif
@endsection
