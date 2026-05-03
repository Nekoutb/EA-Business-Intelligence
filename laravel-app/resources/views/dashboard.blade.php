@extends('layouts.app')
@section('title', 'Executive Overview')
@section('main-class', 'is-fit')

{{--
    Executive Overview — v5 single-screen layout.

    Fits the entire briefing on one viewport: topbar + 1-line summary
    + 6 charts on a 12-col grid + 6-cell KPI strip. Everything sized
    via CSS grid (`grid-auto-rows: 1fr`) so charts fill available
    height — ECharts resizes to container on `window.resize`.

    Data shape (from DashboardController::index):
      $data: current/previous periods, currency, yoy, period meta,
             daily series, budget feed.
      $pl:   profit & loss for current period (revenue, cogs, gp,
             opex, ebitda, da+tax, net, all with margin pcts).
      $reconciliation: expected → billed → cash with gaps.
      $receivables:    AR totals, DSO, overdue, top-5.
      $period, $periodLabel, $error.
--}}

@push('styles')
<style>
  /* ── Page-local layout (extends .ea-main.is-fit from layout shell) */
  .topbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; flex-shrink: 0; gap: 12px; }
  .topbar__t  { display: flex; align-items: baseline; gap: 12px; }
  .topbar__eb { font: 600 10px var(--font); color: var(--text-subtle); letter-spacing: .12em; text-transform: uppercase; display: flex; align-items: center; gap: 6px; }
  .topbar__eb::before { content:''; width:5px; height:5px; border-radius: 50%; background: var(--ea-primary); }
  .topbar__h  { font: 700 20px var(--font); color: var(--text); letter-spacing: -0.02em; margin-top: 2px; }
  .topbar__c  { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
  .topbar__meta { font: 500 11px var(--font); color: var(--text-subtle); }
  .topbar__meta b { color: var(--text); font-weight: 600; }

  .period { display: inline-flex; background: var(--surface); border: 1px solid var(--border); border-radius: var(--r); padding: 2px; }
  .period button { border: 0; background: transparent; padding: 4px 12px; font: 600 12px var(--font); color: var(--text-subtle); cursor: pointer; border-radius: var(--r-sm); }
  .period button.is-active { background: rgba(13,110,110,.10); color: var(--ea-primary); }
  .year-pick { background: var(--surface); border: 1px solid var(--border); border-radius: var(--r); padding: 4px 10px; font: 600 12px var(--font); color: var(--text-muted); cursor: pointer; }
  .month-pick { background: var(--surface); border: 1px solid var(--border); border-radius: var(--r); padding: 4px 8px; font: 600 12px var(--font); color: var(--text-muted); cursor: pointer; }
  .refresh-btn { background: var(--ea-primary); color: #fff; border: 0; border-radius: var(--r); padding: 4px 10px; font: 600 11px var(--font); cursor: pointer; display: inline-flex; align-items: center; gap: 4px; }
  .refresh-btn:hover { background: var(--ea-primary-dark); }
  .refresh-btn svg { width: 12px; height: 12px; }

  .summary { background: var(--surface); border: 1px solid var(--border); border-left: 3px solid var(--ea-primary); border-radius: var(--r); padding: 6px 12px; margin-bottom: 8px; flex-shrink: 0; display: flex; align-items: center; gap: 12px; font: 500 12px var(--font); color: var(--text-muted); min-height: 30px; flex-wrap: wrap; }
  .summary__l { font: 700 10px var(--font); color: var(--ea-primary); letter-spacing: .1em; text-transform: uppercase; flex-shrink: 0; }
  .summary b { color: var(--text); font-weight: 700; }
  .summary .pos { color: var(--pos); font-weight: 700; }
  .summary .neg { color: var(--neg); font-weight: 700; }
  .summary .neutral { color: var(--warn); font-weight: 700; }

  .grid { flex: 1; display: grid; gap: 8px; grid-template-columns: repeat(12, 1fr); grid-auto-rows: 1fr; min-height: 0; }
  .grid > * { min-height: 0; min-width: 0; }
  .span-7 { grid-column: span 7; } .span-5 { grid-column: span 5; }
  .span-4 { grid-column: span 4; } .span-12 { grid-column: span 12; }

  .v5-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-lg); padding: 8px 12px; display: flex; flex-direction: column; min-height: 0; }
  .v5-card__h { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; margin-bottom: 4px; flex-shrink: 0; }
  .v5-card__t { font: 700 12px var(--font); color: var(--text); }
  .v5-card__sub { font: 400 10.5px var(--font); color: var(--text-subtle); margin-top: 1px; }
  .v5-card__leg { display: flex; gap: 10px; font: 500 10px var(--font); color: var(--text-subtle); flex-shrink: 0; flex-wrap: wrap; }
  .v5-card__leg > span { display: inline-flex; align-items: center; gap: 4px; }
  .lswatch { width: 10px; height: 10px; border-radius: 2px; }
  .lline { width: 12px; height: 2px; }
  .v5-card__b { flex: 1; min-height: 0; width: 100%; position: relative; }
  .v5-card__f { font: 500 10px var(--font); color: var(--text-subtle); margin-top: 4px; padding-top: 4px; border-top: 1px solid var(--border-faint); flex-shrink: 0; line-height: 1.4; }
  .v5-card__f b { color: var(--text); font-weight: 600; }
  .v5-card__f .pos { color: var(--pos); font-weight: 600; }
  .v5-card__f .neg { color: var(--neg); font-weight: 600; }
  .v5-card__f .warn { color: var(--warn); font-weight: 600; }

  /* Bullet (run-rate) — pure CSS */
  .bullet { display: flex; flex-direction: column; gap: 6px; padding: 4px 0; }
  .bullet__r { display: flex; align-items: center; gap: 8px; }
  .bullet__l { font: 700 9px var(--font); color: var(--text-subtle); letter-spacing: .06em; text-transform: uppercase; width: 80px; flex-shrink: 0; }
  .bullet__l.dim { color: var(--text-faint); }
  .bullet__t { flex: 1; height: 18px; background: var(--border-faint); border-radius: var(--r-sm); position: relative; overflow: hidden; }
  .bullet__t.dim { border: 1px dashed var(--warn-bd); }
  .bullet__f { height: 100%; background: var(--ea-primary); display: flex; align-items: center; padding-left: 8px; color: #fff; font: 700 10px var(--font); white-space: nowrap; }
  .bullet__f.proj { background: repeating-linear-gradient(45deg, var(--text-faint), var(--text-faint) 4px, #d1d5db 4px, #d1d5db 8px); width: 100%; }
  .bullet__f.dimt { color: var(--pending-text); font-style: italic; font-weight: 600; background: transparent; padding-left: 8px; }
  .bullet__m { position: absolute; top: -2px; bottom: -2px; width: 2px; background: var(--text); }
  .bullet__v { font: 700 11px var(--font); color: var(--text); width: 70px; text-align: right; flex-shrink: 0; }
  .bullet__v.dim { color: var(--text-faint); }

  /* KPI strip (~52px) */
  .strip { display: grid; grid-template-columns: repeat(6, 1fr); background: var(--surface); border: 1px solid var(--border); border-radius: var(--r); margin-top: 8px; flex-shrink: 0; overflow: hidden; }
  .strip__c { padding: 6px 10px; border-right: 1px solid var(--border-faint); }
  .strip__c:last-child { border-right: 0; }
  .strip__l { font: 700 9px var(--font); color: var(--text-subtle); letter-spacing: .06em; text-transform: uppercase; margin-bottom: 2px; }
  .strip__v { font: 700 14px var(--font); color: var(--text); line-height: 1; letter-spacing: -.01em; }
  .strip__v.is-pos { color: var(--pos); }
  .strip__v.is-neg { color: var(--neg); }
  .strip__v .u { font: 500 10px var(--font); color: var(--text-subtle); margin-left: 1px; }
  .strip__m { font: 500 9px var(--font); color: var(--text-subtle); margin-top: 2px; }

  /* Empty / error states */
  .empty-state { display: flex; align-items: center; justify-content: center; height: 100%; color: var(--text-faint); font: 500 11px var(--font); text-align: center; padding: 8px; }
  .error-card { padding: 12px 16px; background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; border-radius: var(--r); font-size: 12px; margin-bottom: 8px; }

  /* Tighter responsive on narrow viewports */
  @media (max-width: 1300px) {
    .grid { grid-auto-rows: minmax(140px, 1fr); }
  }
</style>
@endpush

@section('content')

@if ($error)
  <div class="error-card">
    <strong>Error:</strong> {{ $error }} ·
    <a href="{{ tenant_url('/settings') }}" style="color:#991b1b; text-decoration:underline;">Check Odoo settings</a>
  </div>
@endif

@php
  // ── Defensive shape pulls so the page renders even when data is partial.
  $cur    = $data['current']  ?? [];
  $prev   = $data['previous'] ?? [];
  $ccy    = $data['currency'] ?? 'XAF';
  $yoy    = $data['yoy_growth_pct'] ?? null;
  $plCur  = $pl['current']    ?? null;
  $rec    = $reconciliation   ?? null;
  $recH   = $rec['headline']  ?? null;
  $rcv    = $receivables      ?? null;

  $budgetData   = $data['budget'] ?? [];
  $budgetTotals = $budgetData['totals'] ?? [];

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
  $planOutfits   = $budgetTotals['outfits']   ?? null;
  $planDiscounts = $budgetTotals['discounts'] ?? null;
  $planCustomers = $budgetTotals['customers'] ?? null;
  $planCostBase  = $budgetTotals['cost_base'] ?? null;

  // Period flags + working-day pace (logic mirrors prior dashboard).
  $periodType    = $period['type'] ?? 'ytd';
  $isMtd         = in_array($periodType, ['mtd', 'month'], true);
  $workingDaysSoFar = max(1, (int) ($cur['working_days'] ?? 1));

  $today = new \DateTimeImmutable();
  $year  = (int) ($period['year'] ?? $today->format('Y'));
  $monthCur = (int) ($period['month'] ?? $today->format('n'));
  $fullPeriodWorkingDays = 0;
  if ($isMtd) {
      $monthStart = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year,
          $periodType === 'month' ? (int) ($period['month'] ?? $today->format('n')) : (int) $today->format('n')));
      $nextMonth = $monthStart->modify('first day of next month');
      for ($d = $monthStart; $d < $nextMonth; $d = $d->modify('+1 day')) {
          if ((int) $d->format('N') < 7) $fullPeriodWorkingDays++;
      }
  } else {
      for ($d = new \DateTimeImmutable("$year-01-01"); (int) $d->format('Y') === $year; $d = $d->modify('+1 day')) {
          if ((int) $d->format('N') < 7) $fullPeriodWorkingDays++;
      }
  }
  $fullPeriodWorkingDays = max(1, $fullPeriodWorkingDays);

  $runRate     = ((float) ($cur['total'] ?? 0) / $workingDaysSoFar) * $fullPeriodWorkingDays;
  $runRatePy   = ($prev['total'] ?? null) !== null ? ((float) $prev['total'] / $workingDaysSoFar) * $fullPeriodWorkingDays : null;
  $runRatePlan = is_numeric($planRevenue) ? ((float) $planRevenue / $workingDaysSoFar) * $fullPeriodWorkingDays : null;

  // Trajectory series — group daily into months and compute cumulative.
  $dailySeries = $cur['series'] ?? [];
  $monthlyActual = array_fill(1, 12, 0);
  foreach ($dailySeries as $date => $val) {
      try {
          $m = (int) (new \DateTimeImmutable($date))->format('n');
          if ($m >= 1 && $m <= 12) $monthlyActual[$m] += (float) $val;
      } catch (\Throwable $e) { /* skip malformed dates */ }
  }
  $cumActual = []; $running = 0; $latestActualMonth = 0;
  for ($m = 1; $m <= 12; $m++) {
      if ($monthlyActual[$m] > 0) {
          $running += $monthlyActual[$m];
          $cumActual[$m] = $running;
          $latestActualMonth = $m;
      } else {
          $cumActual[$m] = null;
      }
  }
  // Linear pace = (actual / WD-so-far) × cumulative-WD-by-month.
  $wdByMonth = [];
  for ($m = 1; $m <= 12; $m++) {
      $ms = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $m));
      $nm = $ms->modify('first day of next month');
      $w = 0;
      for ($d = $ms; $d < $nm; $d = $d->modify('+1 day')) if ((int) $d->format('N') < 7) $w++;
      $wdByMonth[$m] = $w;
  }
  $dailyPace = $workingDaysSoFar > 0 ? ((float) ($cur['total'] ?? 0) / $workingDaysSoFar) : 0;
  $cumLinear = []; $cw = 0;
  for ($m = 1; $m <= 12; $m++) { $cw += $wdByMonth[$m]; $cumLinear[$m] = $dailyPace * $cw; }

  // Helpers for pretty number formatting in the strip.
  $shortNum = function (float $v) {
      $a = abs($v);
      if ($a >= 1_000_000) return number_format($v / 1_000_000, 2);
      if ($a >= 1_000)     return number_format($v / 1_000, 0);
      return number_format($v, 0);
  };
  $shortUnit = function (float $v, string $ccy = 'XAF') {
      $a = abs($v);
      return ($a >= 1_000_000 ? 'M ' : ($a >= 1_000 ? 'K ' : '')) . $ccy;
  };

  // Strip metrics — fall back gracefully if PL is missing.
  $rev      = (float) ($cur['total']           ?? 0);
  $gp       = (float) ($plCur['gross_profit']  ?? 0);
  $gpPct    = (float) ($plCur['gross_margin_pct'] ?? $plCur['gross_margin'] ?? 0);
  $ebitda   = (float) ($plCur['ebitda']        ?? 0);
  $ebPct    = (float) ($plCur['ebitda_margin_pct'] ?? $plCur['ebitda_margin'] ?? 0);
  $net      = (float) ($plCur['net_profit']    ?? 0);
  $netPct   = (float) ($plCur['net_margin_pct']    ?? $plCur['net_margin']    ?? 0);
  $cash     = (float) ($recH['collected']      ?? 0);
  $billed   = (float) ($recH['billed']         ?? 0);
  $cashPct  = $billed > 0 ? 100.0 * $cash / $billed : null;
  $ar       = (float) ($rcv['total_ar']        ?? 0);
  $dso      = (float) ($rcv['dso']             ?? $rcv['kpis']['dso_days'] ?? 0);

  // Summary band priority — pick the tightest constraint.
  $priority = 'OPEX leverage';
  if ($gpPct > 0 && $gpPct < 35) $priority = 'Gross margin recovery';
  elseif ($ebPct < -3) $priority = 'OPEX leverage';
  elseif ($cashPct !== null && $cashPct < 80) $priority = 'AR collection';

  // ── Pre-built JS payloads ──────────────────────────────────────
  // CRITICAL: Blade's @json() directive uses a brittle bracket-counter
  // when its argument spans multiple lines with nested arrays + ?:
  // ternaries — it silently truncates the array to the first element
  // (https://github.com/laravel/framework/issues — historical issue).
  // To stay safe we ALWAYS construct chart payloads here in PHP and
  // hand a single variable to @json(). Never inline a multi-line
  // array literal as a @json argument.
  $rpoCur = (float) ($cur['rpo'] ?? 0);
  $rpcCur = (float) ($cur['per_customer'] ?? 0);
  $costData = [
      ['CPO',  $rpoCur > 0 ? 100.0 * ((float) ($cur['cpo']   ?? 0)) / $rpoCur : 0.0, 35],
      ['DPOT', $rpoCur > 0 ? 100.0 * ((float) ($cur['dp_ot'] ?? 0)) / $rpoCur : 0.0, 15],
      ['DPC',  $rpcCur > 0 ? 100.0 * ((float) ($cur['dp_c']  ?? 0)) / $rpcCur : 0.0, 15],
  ];
  $unitData = [
      ['RPD · Rev/Day',      [(float) ($prev['rpd']          ?? 0), (float) ($cur['rpd']          ?? 0)]],
      ['OPD · Outfit/Day',   [(float) ($prev['opd']          ?? 0), (float) ($cur['opd']          ?? 0)]],
      ['RPO · Rev/Outfit',   [(float) ($prev['rpo']          ?? 0), (float) ($cur['rpo']          ?? 0)]],
      ['RPC · Rev/Customer', [(float) ($prev['per_customer'] ?? 0), (float) ($cur['per_customer'] ?? 0)]],
  ];
  $cumActualJson = array_values($cumActual);
  $cumLinearJson = array_values($cumLinear);
@endphp

{{-- ── Topbar ────────────────────────────────────────────────────── --}}
<div class="topbar">
    <div class="topbar__t">
        <div>
            <div class="topbar__eb">{{ $periodLabel ?? 'YTD' }} · {{ now()->format('d M H:i') }}</div>
            <div class="topbar__h">Executive Overview</div>
        </div>
    </div>
    <div class="topbar__c">
        <form method="GET" class="period">
            <button type="submit" name="period" value="ytd"   class="{{ $periodType === 'ytd' ? 'is-active' : '' }}">YTD</button>
            <button type="submit" name="period" value="mtd"   class="{{ $periodType === 'mtd' ? 'is-active' : '' }}">MTD</button>
            <button type="submit" name="period" value="month" class="{{ $periodType === 'month' ? 'is-active' : '' }}">Month</button>
            @if ($periodType === 'month')
              <input type="hidden" name="year" value="{{ $year }}">
              <input type="hidden" name="month" value="{{ $monthCur }}">
            @endif
        </form>
        <form method="GET" style="display:inline-flex; gap: 4px;">
            <input type="hidden" name="period" value="{{ $periodType }}">
            <select name="year" onchange="this.form.submit()" class="year-pick">
                @for ($y = (int) date('Y'); $y >= (int) date('Y') - 3; $y--)
                  <option value="{{ $y }}" {{ $year == $y ? 'selected' : '' }}>{{ $y }}</option>
                @endfor
            </select>
            @if ($periodType === 'month')
              <select name="month" onchange="this.form.submit()" class="month-pick">
                @foreach (range(1,12) as $m)
                  <option value="{{ $m }}" {{ $monthCur == $m ? 'selected' : '' }}>{{ date('M', mktime(0,0,0,$m,1)) }}</option>
                @endforeach
              </select>
            @endif
        </form>
        <span class="topbar__meta">Updated <b>{{ now()->format('H:i') }}</b> · {{ ea_last_sync_label() }}</span>
        <form method="POST" action="{{ tenant_url('/sync') }}" style="margin:0;">
            @csrf
            <button type="submit" class="refresh-btn" title="Force refresh">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                Refresh
            </button>
        </form>
    </div>
</div>

{{-- ── Summary band ──────────────────────────────────────────────── --}}
<div class="summary">
    <span class="summary__l">In 5 sec</span>
    <span>
        Revenue <b>{{ $shortNum($rev) }}{{ $shortUnit($rev, $ccy) }} {{ $periodLabel ?? 'YTD' }}</b>
        @if (!$isMtd && $runRate > 0)
          on linear pace · year-end projection <b>{{ $shortNum($runRate) }}{{ $shortUnit($runRate, $ccy) }}</b>.
        @else
          .
        @endif
        @if ($plCur)
          Gross <span class="{{ $gpPct >= 35 ? 'pos' : ($gpPct >= 25 ? 'neutral' : 'neg') }}">{{ $gpPct >= 0 ? '+' : '' }}{{ number_format($gpPct, 1) }}%</span>,
          EBITDA <span class="{{ $ebPct >= 0 ? 'pos' : 'neg' }}">{{ $ebPct >= 0 ? '+' : '' }}{{ number_format($ebPct, 1) }}%</span>
          @if ($ebPct < 0 && $gpPct > 0) — OPEX consumes margin. @else . @endif
        @endif
        @if ($cashPct !== null)
          Cash <span class="{{ $cashPct >= 90 ? 'pos' : ($cashPct >= 70 ? 'neutral' : 'neg') }}">{{ number_format($cashPct, 1) }}% of billed</span>
          @if ($ar > 0)
            · AR <span class="neutral">{{ $shortNum($ar) }}{{ $shortUnit($ar, $ccy) }}{{ $dso > 0 ? ' · DSO ' . round($dso) . 'd' : '' }}</span>.
          @else
            .
          @endif
        @endif
        Priority: <b>{{ $priority }}</b>.
    </span>
</div>

{{-- ── Grid ──────────────────────────────────────────────────────── --}}
<div class="grid">

    {{-- Row 1 · Cumulative Revenue (span 7) --}}
    <div class="v5-card span-7">
        <div class="v5-card__h">
            <div>
                <div class="v5-card__t">Cumulative Revenue · Linear pace</div>
                <div class="v5-card__sub">{{ $periodLabel ?? 'YTD' }} {{ $year }} · {{ $ccy }} (Mon-Sat {{ $fullPeriodWorkingDays }} WD)</div>
            </div>
            <div class="v5-card__leg">
                <span><span class="lline" style="background:var(--ea-primary);"></span>Actual</span>
                <span><span class="lline" style="background:var(--text-faint);"></span>Linear pace</span>
            </div>
        </div>
        <div class="v5-card__b" id="ec-trajectory">
            @if (empty($dailySeries))<div class="empty-state">No revenue series for this period.</div>@endif
        </div>
        <div class="v5-card__f">
            <b>Reading:</b> Daily run-rate <b>{{ $shortNum($dailyPace) }}{{ $shortUnit($dailyPace, $ccy) }}</b>
            · {{ $workingDaysSoFar }}/{{ $fullPeriodWorkingDays }} WD ({{ number_format(100 * $workingDaysSoFar / $fullPeriodWorkingDays, 1) }}%)
            · @if(!$isMtd) year-end at pace: <b>{{ $shortNum($runRate) }}{{ $shortUnit($runRate, $ccy) }}</b> @else month-end: <b>{{ $shortNum($runRate) }}{{ $shortUnit($runRate, $ccy) }}</b> @endif.
            @if (!$planRevenue)<span class="warn">Budget feed pending</span>.@endif
        </div>
    </div>

    {{-- Row 1 · Margin Waterfall (span 5) --}}
    <div class="v5-card span-5">
        <div class="v5-card__h">
            <div>
                <div class="v5-card__t">Margin Waterfall</div>
                <div class="v5-card__sub">Revenue → Net Profit ({{ $ccy }})</div>
            </div>
            <div class="v5-card__leg">
                <span><span class="lswatch" style="background:#047857;"></span>In</span>
                <span><span class="lswatch" style="background:#fbbf24;"></span>Out</span>
                <span><span class="lswatch" style="background:#b91c1c;"></span>Loss</span>
            </div>
        </div>
        <div class="v5-card__b" id="ec-waterfall">
            @if (!$plCur)<div class="empty-state">P&amp;L not yet available.</div>@endif
        </div>
        <div class="v5-card__f">
            <b>Reading:</b>
            @if ($plCur)
              <span class="{{ $gpPct >= 35 ? 'pos' : 'warn' }}">{{ number_format($gpPct, 1) }}% gross margin</span>,
              OPEX <span class="warn">{{ $rev > 0 ? number_format(100 * (float) ($plCur['opex'] ?? 0) / $rev, 0) : '—' }}% of revenue</span>.
              EBITDA <span class="{{ $ebitda >= 0 ? 'pos' : 'neg' }}">{{ $shortNum($ebitda) }}{{ $shortUnit($ebitda, $ccy) }}</span>.
            @else
              Run sync to populate the P&amp;L stages.
            @endif
        </div>
    </div>

    {{-- Row 2 · Cost Pressure (span 4) --}}
    <div class="v5-card span-4">
        <div class="v5-card__h">
            <div>
                <div class="v5-card__t">Cost Ratio &amp; Discount</div>
                <div class="v5-card__sub">CPO · DPOT · DPC vs target</div>
            </div>
        </div>
        <div class="v5-card__b" id="ec-cost"></div>
        <div class="v5-card__f">
            <b>Reading:</b>
            @php
                $cpoR = ($cur['rpo'] ?? 0) > 0 ? 100.0 * ((float)($cur['cpo'] ?? 0)) / ((float)$cur['rpo']) : 0;
            @endphp
            CPO {{ number_format($cpoR, 0) }}% of unit revenue
            ({{ $cpoR > 35 ? 'above' : 'within' }} 35% target).
            Tighten cost-per-outfit first.
        </div>
    </div>

    {{-- Row 2 · Run-Rate Bullet (span 4) --}}
    <div class="v5-card span-4">
        <div class="v5-card__h">
            <div>
                <div class="v5-card__t">{{ $isMtd ? 'Monthly Run-Rate' : 'Annualised Run-Rate' }}</div>
                <div class="v5-card__sub">{{ number_format(100 * $workingDaysSoFar / $fullPeriodWorkingDays, 1) }}% of {{ $isMtd ? 'month' : 'year' }} elapsed</div>
            </div>
        </div>
        <div class="v5-card__b" style="display:flex; flex-direction:column; justify-content:center;">
            <div class="bullet">
                <div class="bullet__r">
                    <div class="bullet__l">{{ $isMtd ? 'MTD' : 'YTD' }}</div>
                    <div class="bullet__t">
                        <div class="bullet__f" style="width: {{ min(100, 100 * $workingDaysSoFar / $fullPeriodWorkingDays) }}%;">
                            {{ $shortNum($rev) }}{{ $shortUnit($rev, $ccy) }}
                        </div>
                        <div class="bullet__m" style="left: {{ min(100, 100 * $workingDaysSoFar / $fullPeriodWorkingDays) }}%;"></div>
                    </div>
                    <div class="bullet__v">{{ number_format(100 * $workingDaysSoFar / $fullPeriodWorkingDays, 1) }}%</div>
                </div>
                <div class="bullet__r">
                    <div class="bullet__l">Projection</div>
                    <div class="bullet__t">
                        <div class="bullet__f proj">{{ $shortNum($runRate) }}{{ $shortUnit($runRate, $ccy) }} projected</div>
                    </div>
                    <div class="bullet__v" style="color:#374151; font-size:10px;">at pace</div>
                </div>
                <div class="bullet__r">
                    @if ($planRevenue)
                      <div class="bullet__l">Budget</div>
                      <div class="bullet__t">
                          <div class="bullet__f" style="width: {{ min(100, 100 * $rev / $planRevenue) }}%; background: var(--warn);">
                              {{ number_format(100 * $rev / $planRevenue, 1) }}%
                          </div>
                      </div>
                      <div class="bullet__v">{{ $shortNum($planRevenue) }}{{ $shortUnit($planRevenue, $ccy) }}</div>
                    @else
                      <div class="bullet__l dim">Budget</div>
                      <div class="bullet__t dim">
                          <div class="bullet__f dimt">budget feed pending</div>
                      </div>
                      <div class="bullet__v dim">—</div>
                    @endif
                </div>
            </div>
        </div>
        <div class="v5-card__f">
            <b>Reading:</b>
            @if ($planRevenue)
              {{ $rev >= $planRevenue ? 'Ahead of plan.' : 'Behind plan by ' . number_format(100 * (1 - $rev / $planRevenue), 1) . '%.' }}
            @else
              On linear pace · <span class="warn">budget gap pending feed integration</span>.
            @endif
        </div>
    </div>

    {{-- Row 2 · Sankey · Reconciliation (span 4) --}}
    <div class="v5-card span-4">
        <div class="v5-card__h">
            <div>
                <div class="v5-card__t">Revenue Reconciliation</div>
                <div class="v5-card__sub">Expected → Billed → Cash</div>
            </div>
        </div>
        <div class="v5-card__b" id="ec-sankey">
            @if (!$recH)<div class="empty-state">Reconciliation not available.</div>@endif
        </div>
        <div class="v5-card__f">
            @if ($recH && $cashPct !== null)
              <b>Reading:</b>
              <span class="{{ $cashPct >= 90 ? 'pos' : 'warn' }}">{{ number_format($cashPct, 1) }}% lands in cash</span>
              · <span class="neg">{{ number_format(100 - $cashPct, 1) }}% as receivables</span>.
            @else
              <b>Reading:</b> Run sync to compute reconciliation.
            @endif
        </div>
    </div>

    {{-- Row 3 · Unit Economics small multiples (span 12) --}}
    <div class="v5-card span-12">
        <div class="v5-card__h">
            <div>
                <div class="v5-card__t">Unit Economics</div>
                <div class="v5-card__sub">RPD · OPD · RPO · RPC — current period vs prior year</div>
            </div>
        </div>
        <div class="v5-card__b" id="ec-unit"></div>
        <div class="v5-card__f">
            <b>Reading:</b>
            @php
                $rpdDelta = $variance($cur['rpd'] ?? null, $prev['rpd'] ?? null);
                $opdDelta = $variance($cur['opd'] ?? null, $prev['opd'] ?? null);
                $rpoDelta = $variance($cur['rpo'] ?? null, $prev['rpo'] ?? null);
                $rpcDelta = $variance($cur['per_customer'] ?? null, $prev['per_customer'] ?? null);
            @endphp
            @if ($rpdDelta !== null && $opdDelta !== null && ($rpdDelta > 0 || $opdDelta > 0) && (($rpoDelta ?? 0) < 0 || ($rpcDelta ?? 0) < 0))
              Throughput accelerating, unit pricing eroding — pattern of <span class="warn">volume-led growth at lower contract values</span>.
            @elseif ($rpdDelta !== null && $rpdDelta > 0)
              Revenue per day up — units holding their value.
            @else
              Compare current to prior year for trajectory.
            @endif
        </div>
    </div>

</div>

{{-- ── KPI strip (6 cells) ──────────────────────────────────────── --}}
<div class="strip">
    <div class="strip__c">
        <div class="strip__l">Revenue {{ $isMtd ? 'MTD' : 'YTD' }}</div>
        <div class="strip__v">{{ $shortNum($rev) }}<span class="u">{{ $shortUnit($rev, $ccy) }}</span></div>
        <div class="strip__m">tracking pace</div>
    </div>
    <div class="strip__c">
        <div class="strip__l">Gross Profit</div>
        <div class="strip__v {{ $gp >= 0 ? 'is-pos' : 'is-neg' }}">{{ $shortNum($gp) }}<span class="u">{{ $shortUnit($gp, $ccy) }}</span></div>
        <div class="strip__m">{{ number_format($gpPct, 1) }}% margin</div>
    </div>
    <div class="strip__c">
        <div class="strip__l">EBITDA</div>
        <div class="strip__v {{ $ebitda >= 0 ? 'is-pos' : 'is-neg' }}">{{ $ebitda < 0 ? '−' : '' }}{{ $shortNum(abs($ebitda)) }}<span class="u">{{ $shortUnit($ebitda, $ccy) }}</span></div>
        <div class="strip__m">{{ number_format($ebPct, 1) }}% margin</div>
    </div>
    <div class="strip__c">
        <div class="strip__l">Net Profit</div>
        <div class="strip__v {{ $net >= 0 ? 'is-pos' : 'is-neg' }}">{{ $net < 0 ? '−' : '' }}{{ $shortNum(abs($net)) }}<span class="u">{{ $shortUnit($net, $ccy) }}</span></div>
        <div class="strip__m">{{ number_format($netPct, 1) }}% margin</div>
    </div>
    <div class="strip__c">
        <div class="strip__l">Cash Collected</div>
        <div class="strip__v {{ $cashPct !== null && $cashPct >= 80 ? 'is-pos' : '' }}">{{ $shortNum($cash) }}<span class="u">{{ $shortUnit($cash, $ccy) }}</span></div>
        <div class="strip__m">{{ $cashPct !== null ? number_format($cashPct, 1) . '% of billed' : '—' }}</div>
    </div>
    <div class="strip__c">
        <div class="strip__l">AR Outstanding</div>
        <div class="strip__v">{{ $shortNum($ar) }}<span class="u">{{ $shortUnit($ar, $ccy) }}</span></div>
        <div class="strip__m">{{ $dso > 0 ? 'DSO ' . round($dso) . 'd' : 'DSO —' }}</div>
    </div>
</div>

@push('scripts')
<script>
(function () {
  const t = {
    pri: '#0d6e6e', pos: '#047857', neg: '#b91c1c',
    warn: '#d97706', warnLt: '#fbbf24',
    textM: '#6b7280', textF: '#9ca3af',
    grid: '#f3f4f6', base: '#d1d5db', area: 'rgba(13,110,110,0.10)'
  };
  const FF = "'Segoe UI',-apple-system,BlinkMacSystemFont,system-ui,sans-serif";
  const tip = {
    trigger: 'axis', backgroundColor: '#fff', borderColor: '#e5e7eb',
    borderWidth: 1, padding: [6, 10],
    textStyle: { color: '#111827', fontSize: 11 },
    axisPointer: { type: 'shadow', shadowStyle: { color: 'rgba(13,110,110,0.04)' } }
  };

  function fmt(v) {
    if (v == null) return '—';
    const a = Math.abs(v);
    if (a >= 1e6) return (v / 1e6).toFixed(1).replace(/\.0$/, '') + 'M';
    if (a >= 1e3) return (v / 1e3).toFixed(0) + 'K';
    return v.toString();
  }

  /* ── 1. Trajectory ───────────────────────────────────────────── */
  const trajectoryEl = document.getElementById('ec-trajectory');
  const cumActual = @json($cumActualJson);
  const cumLinear = @json($cumLinearJson);
  if (trajectoryEl && cumActual.some(v => v != null)) {
    echarts.init(trajectoryEl).setOption({
      textStyle: { fontFamily: FF, color: '#374151' },
      grid: { left: 48, right: 16, top: 8, bottom: 22, containLabel: false },
      tooltip: tip,
      xAxis: { type: 'category', data: ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'],
        axisLine: { lineStyle: { color: t.base } }, axisTick: { show: false },
        axisLabel: { color: t.textM, fontSize: 9 } },
      yAxis: { type: 'value', axisLine: { show: false }, axisTick: { show: false },
        splitLine: { lineStyle: { color: t.grid } },
        axisLabel: { color: t.textM, fontSize: 9, formatter: v => fmt(v) } },
      series: [
        { name: 'Actual', type: 'line', data: cumActual,
          lineStyle: { color: t.pri, width: 2 }, itemStyle: { color: t.pri },
          areaStyle: { color: t.area }, symbol: 'circle', symbolSize: 5 },
        { name: 'Linear', type: 'line', data: cumLinear,
          lineStyle: { color: t.textF, type: 'dashed', width: 1.5 },
          itemStyle: { color: t.textF }, symbol: 'none' },
      ],
    });
  }

  /* ── 2. Waterfall ────────────────────────────────────────────── */
  const wfEl = document.getElementById('ec-waterfall');
  const pl = @json($plCur);
  if (wfEl && pl) {
    const stages = [
      { name: 'Rev',    value: +pl.revenue       || 0,           type: 'total' },
      { name: 'COGS',   value: -Math.abs(+pl.cogs || 0),         type: 'out'   },
      { name: 'GP',     value: +pl.gross_profit  || 0,           type: 'sub'   },
      { name: 'OPEX',   value: -Math.abs(+pl.opex || 0),         type: 'out'   },
      { name: 'EBITDA', value: +pl.ebitda        || 0,           type: (+pl.ebitda || 0) >= 0 ? 'sub' : 'sub-' },
      { name: 'D&A+T',  value: -Math.abs((+pl.depreciation || 0) + (+pl.tax || 0) + (+pl.interest || 0)), type: 'out' },
      { name: 'NP',     value: +pl.net_profit    || 0,           type: (+pl.net_profit || 0) >= 0 ? 'sub' : 'tot-' },
    ];
    const ph = [], pos = [], neg = [];
    let r = 0;
    stages.forEach(s => {
      if (['total','sub','sub-','tot-'].includes(s.type)) {
        ph.push(s.value < 0 ? s.value : 0);
        if (s.value >= 0) { pos.push(s.value); neg.push(0); }
        else              { pos.push(0); neg.push(Math.abs(s.value)); }
        r = s.value;
      } else {
        const e = r + s.value;
        ph.push(Math.min(r, e));
        const h = Math.abs(s.value);
        if (s.value >= 0) { pos.push(h); neg.push(0); }
        else              { pos.push(0); neg.push(h); }
        r = e;
      }
    });
    echarts.init(wfEl).setOption({
      textStyle: { fontFamily: FF, color: '#374151' },
      grid: { left: 48, right: 8, top: 8, bottom: 22, containLabel: false },
      tooltip: { ...tip, formatter: p => {
        const s = stages[p[0].dataIndex]; return `<b>${s.name}</b>: ${fmt(s.value)}`;
      } },
      xAxis: { type: 'category', data: stages.map(s => s.name),
        axisLine: { lineStyle: { color: t.base } }, axisTick: { show: false },
        axisLabel: { color: t.textM, fontSize: 9, fontWeight: 600 } },
      yAxis: { type: 'value', axisLine: { show: false },
        splitLine: { lineStyle: { color: t.grid } },
        axisLabel: { color: t.textM, fontSize: 9, formatter: v => fmt(v) } },
      series: [
        { name: 'ph', type: 'bar', stack: 'w', itemStyle: { color: 'transparent' }, data: ph, tooltip: { show: false } },
        { name: 'In', type: 'bar', stack: 'w',
          data: pos.map((v, i) => ({ value: v,
            itemStyle: { color: ['total','sub'].includes(stages[i].type) ? t.pri : t.pos } })),
          barWidth: '60%' },
        { name: 'Out', type: 'bar', stack: 'w',
          data: neg.map((v, i) => ({ value: v,
            itemStyle: { color: ['sub-','tot-'].includes(stages[i].type) ? t.neg : t.warnLt } })),
          barWidth: '60%' },
      ],
    });
  }

  /* ── 3. Cost Pressure (horizontal bars: CPO/RPO, DPOT/RPO, DPC/RPC) ── */
  const costEl = document.getElementById('ec-cost');
  const costData = @json($costData);
  if (costEl && costData.some(d => d[1] > 0)) {
    echarts.init(costEl).setOption({
      textStyle: { fontFamily: FF, color: '#374151' },
      grid: { left: 50, right: 60, top: 6, bottom: 22, containLabel: false },
      tooltip: tip,
      yAxis: { type: 'category', data: costData.map(d => d[0]).reverse(),
        axisLine: { lineStyle: { color: t.base } }, axisTick: { show: false },
        axisLabel: { color: t.textM, fontSize: 10, fontWeight: 600 } },
      xAxis: { type: 'value', max: 60, axisLine: { show: false },
        splitLine: { lineStyle: { color: t.grid } },
        axisLabel: { color: t.textM, fontSize: 9, formatter: '{value}%' } },
      series: [{
        type: 'bar', data: costData.slice().reverse().map(d => ({
          value: d[1],
          itemStyle: { color: d[1] > d[2] ? t.warn : t.pri }
        })),
        barWidth: 14,
        label: { show: true, position: 'right', color: t.textM, fontSize: 10,
          formatter: p => p.value.toFixed(0) + '%' },
        markLine: {
          silent: true, symbol: 'none',
          lineStyle: { color: t.textF, type: 'dashed', width: 1 },
          label: { position: 'end', formatter: '≤ 35%', color: t.textM, fontSize: 9, distance: 4 },
          data: [{ xAxis: 35 }]
        }
      }],
    });
  }

  /* ── 4. Unit Economics small multiples ──────────────────────── */
  const unitEl = document.getElementById('ec-unit');
  const unit = @json($unitData);
  if (unitEl && unit.some(u => u[1][1] > 0)) {
    const grids = unit.map((_, i) => ({ left: `${i * 25 + 3}%`, right: `${100 - (i + 1) * 25 + 1}%`, top: 28, bottom: 22 }));
    const titles = unit.map((u, i) => ({
      text: u[0], left: grids[i].left, top: 4,
      textStyle: { fontSize: 10, fontWeight: 700, color: '#111827', fontFamily: FF }
    }));
    echarts.init(unitEl).setOption({
      textStyle: { fontFamily: FF, color: '#374151' },
      title: titles, grid: grids,
      xAxis: unit.map((_, i) => ({ gridIndex: i, type: 'category', data: ['Prior', 'Current'],
        axisLine: { lineStyle: { color: t.base } }, axisTick: { show: false },
        axisLabel: { color: t.textM, fontSize: 9 } })),
      yAxis: unit.map((_, i) => ({ gridIndex: i, type: 'value', axisLine: { show: false }, axisTick: { show: false },
        splitLine: { lineStyle: { color: t.grid } },
        axisLabel: { color: t.textM, fontSize: 9, formatter: v => fmt(v) } })),
      series: unit.map((u, i) => ({
        type: 'bar', xAxisIndex: i, yAxisIndex: i, data: u[1],
        itemStyle: { color: (u[1][1] >= u[1][0]) ? t.pri : t.neg },
        barWidth: '60%',
        label: { show: true, position: 'top', color: t.textM, fontSize: 9, formatter: p => fmt(p.value) }
      })),
    });
  }

  /* ── 5. Sankey · Reconciliation ──────────────────────────────── */
  const sankeyEl = document.getElementById('ec-sankey');
  const recH = @json($recH);
  if (sankeyEl && recH) {
    const expected = +recH.expected || 0;
    const billed   = +recH.billed   || 0;
    const cash     = +recH.collected || 0;
    const leak     = Math.max(0, billed - cash);
    if (billed > 0) {
      echarts.init(sankeyEl).setOption({
        textStyle: { fontFamily: FF, color: '#374151' },
        series: [{
          type: 'sankey',
          left: 6, right: 70, top: 4, bottom: 4,
          data: [
            { name: 'Expected',    itemStyle: { color: t.warnLt, opacity: 0.5 } },
            { name: 'Billed',      itemStyle: { color: t.pri } },
            { name: 'Cash',        itemStyle: { color: t.pos } },
            { name: 'Receivables', itemStyle: { color: t.neg } },
          ],
          links: [
            ...(expected > 0 ? [{ source: 'Expected', target: 'Billed', value: Math.min(expected, billed),
                lineStyle: { color: t.warnLt, opacity: 0.3 } }] : []),
            { source: 'Billed', target: 'Cash',        value: cash, lineStyle: { color: t.pos, opacity: 0.5 } },
            { source: 'Billed', target: 'Receivables', value: leak || 1, lineStyle: { color: t.neg, opacity: 0.7 } },
          ],
          orient: 'horizontal', nodeAlign: 'justify', nodeWidth: 18, nodeGap: 10,
          label: { fontSize: 10, fontWeight: 600, color: '#111827', fontFamily: FF },
          lineStyle: { curveness: 0.5 },
        }],
        tooltip: { ...tip, trigger: 'item',
          formatter: p => p.dataType === 'edge'
              ? `<b>${p.data.source}→${p.data.target}</b>: ${fmt(p.data.value)}`
              : `<b>${p.name}</b>` },
      });
    }
  }

  /* ── Resize charts when viewport changes ─────────────────────── */
  function resizeAll() {
    document.querySelectorAll('.v5-card__b').forEach(el => {
      const i = echarts.getInstanceByDom(el); if (i) i.resize();
    });
  }
  window.addEventListener('resize', resizeAll);
  /* ECharts may need a second tick to settle after Blade injection. */
  setTimeout(resizeAll, 100);
})();
</script>
@endpush

@endsection
