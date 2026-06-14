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

  /* ── KPI Targets & Trends — scrolls below the single-screen board.
     Override is-fit's overflow so the page can scroll to this section while
     the board above still fills the first screen. Page-scoped (this <style>
     only loads on the dashboard), so other is-fit pages are untouched. */
  .ea-main.is-fit { overflow-y: auto; }
  .ea-main.is-fit > * { flex-shrink: 0; }
  .grid { min-height: calc(100vh - 200px); }

  .kpanel { background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-lg); margin-top: 10px; padding: 10px 14px 14px; }
  .kpanel__h { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 10px; }
  .kpanel__t { font: 700 13px var(--font); color: var(--text); }
  .kpanel__t span { font: 500 11px var(--font); color: var(--text-subtle); margin-left: 6px; }
  .kform { display: grid; grid-template-columns: repeat(6, 1fr); gap: 8px 10px; align-items: end; margin-bottom: 14px; }
  .kform__c { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
  .kform__l { font: 600 9px var(--font); color: var(--text-subtle); text-transform: uppercase; letter-spacing: .04em; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .kform__i { width: 100%; box-sizing: border-box; padding: 5px 7px; font: 600 12px var(--font); color: var(--text); background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-sm); }
  .kform__i:focus { outline: none; border-color: var(--ea-primary); }
  .kform__save { grid-column: 1 / -1; display: flex; justify-content: flex-end; gap: 10px; align-items: center; flex-wrap: wrap; }
  .kform__hint { font: 500 10px var(--font); color: var(--text-subtle); margin-right: auto; }
  .kform__btn { padding: 6px 16px; font: 700 11px var(--font); color: #fff; background: var(--ea-primary); border: 0; border-radius: var(--r-sm); cursor: pointer; }
  .kgrid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
  .kgrid__c { border: 1px solid var(--border-faint); border-radius: var(--r); padding: 8px 10px 4px; }
  .kgrid__t { display: flex; align-items: baseline; justify-content: space-between; gap: 8px; font: 700 11px var(--font); color: var(--text); margin-bottom: 2px; }
  .kgrid__u { font: 500 9px var(--font); color: var(--text-subtle); text-transform: uppercase; letter-spacing: .04em; }
  .kchart { width: 100%; height: 168px; }
  @media (max-width: 900px) { .kform { grid-template-columns: repeat(2, 1fr); } .kgrid { grid-template-columns: 1fr; } }

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
  // Per-tenant KPI gate. Falls back to true when no tenant is in
  // scope (eg test renders) so dashboards still populate. The
  // registry default is honoured by Tenant::dashboardKpiEnabled
  // when no DB row exists.
  $kpiOn = function (string $key): bool {
      $t = function_exists('tenant') ? tenant() : null;
      if ($t && method_exists($t, 'dashboardKpiEnabled')) {
          return (bool) $t->dashboardKpiEnabled($key);
      }
      return true;
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

  // ── Period-aware budget overlay ────────────────────────────────────
  // Pulled from the user-uploaded TenantBudget (passed by the
  // controller). When YTD: cumulative annual budget across 12 months.
  // When MTD: 4 weekly buckets for the current month, each = monthly
  // revenue ÷ 4 (uniform split — simpler than working-days-per-week
  // and what the preview promised). Falls back gracefully when no
  // upload exists.
  $monthlyBudget = $uploadedBudgetMonthly ?? null;          // array 1..12 or null
  $cumBudget = [];
  $cumBudgetTotal = 0.0;
  if (is_array($monthlyBudget)) {
      $running = 0.0;
      for ($mm = 1; $mm <= 12; $mm++) {
          $running += (float) ($monthlyBudget[$mm] ?? 0);
          $cumBudget[$mm] = $running;
      }
      $cumBudgetTotal = $running;
  }

  // ── MTD weekly buckets ────────────────────────────────────────────
  // For MTD/Month period: split the current month into 4 weekly slots
  // and sum the daily series into each. Weekly budget target = monthly
  // budget ÷ 4 (uniform).
  $weeklyActual = []; $weeklyBudget = []; $weekLabels = ['Week 1','Week 2','Week 3','Week 4'];
  if ($isMtd) {
      $monthRefY = (int) ($period['year']  ?? $today->format('Y'));
      $monthRefM = (int) ($period['month'] ?? $today->format('n'));
      $monthBudget = is_array($monthlyBudget) ? (float) ($monthlyBudget[$monthRefM] ?? 0) : 0.0;
      $weekBucket = [0, 0, 0, 0];
      foreach ($dailySeries as $date => $val) {
          try {
              $d = new \DateTimeImmutable($date);
          } catch (\Throwable $e) { continue; }
          if ((int) $d->format('Y') !== $monthRefY || (int) $d->format('n') !== $monthRefM) continue;
          $dom = (int) $d->format('j');
          // Day-of-month → 4 buckets: 1-7, 8-14, 15-21, 22-end
          $idx = min(3, intdiv($dom - 1, 7));
          $weekBucket[$idx] += (float) $val;
      }
      $weeklyActual = $weekBucket;
      $weeklyBudget = $monthBudget > 0 ? array_fill(0, 4, $monthBudget / 4.0) : [0, 0, 0, 0];
  }

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
  // Unit economics — filter by per-tenant KPI registry so admins can
  // hide metrics that aren't relevant for a particular tenant. Toggles
  // live in /admin/tenants/{id} (same UI as every other dashboard KPI).
  // Default: all 4 visible (no behavioural change for existing tenants).
  $unitDataFull = [
      'rpd' => ['RPD · Rev/Day',      [(float) ($prev['rpd']          ?? 0), (float) ($cur['rpd']          ?? 0)]],
      'opd' => ['OPD · Outfit/Day',   [(float) ($prev['opd']          ?? 0), (float) ($cur['opd']          ?? 0)]],
      'rpo' => ['RPO · Rev/Outfit',   [(float) ($prev['rpo']          ?? 0), (float) ($cur['rpo']          ?? 0)]],
      'rpc' => ['RPC · Rev/Customer', [(float) ($prev['per_customer'] ?? 0), (float) ($cur['per_customer'] ?? 0)]],
  ];
  $unitData = [];
  foreach ($unitDataFull as $key => $row) {
      if ($kpiOn($key)) $unitData[] = $row;
  }
  $cumActualJson = array_values($cumActual);
  $cumLinearJson = array_values($cumLinear);
  $cumBudgetJson = !empty($cumBudget) ? array_values($cumBudget) : [];
  $weeklyActualJson = $weeklyActual;
  $weeklyBudgetJson = $weeklyBudget;
  $weekLabelsJson   = $weekLabels;
  // Trajectory mode flag: 'mtd' shows weekly bars, otherwise cumulative line
  $trajectoryMode = $isMtd ? 'mtd' : 'ytd';
@endphp

{{-- ── Topbar ────────────────────────────────────────────────────── --}}
<div class="topbar">
    <div class="topbar__t">
        <div>
            <div class="topbar__eb">{{ $periodLabel ?? 'YTD' }} · {{ now()->format('d M H:i') }}</div>
            <div class="topbar__h">Executive Overview</div>
        </div>
    </div>
    {{-- Topbar right side — period filter has moved to the sidebar
         master filter. We keep only the "last refresh" indicator,
         the Refresh trigger, and the tenant company badge. --}}
    <div class="topbar__c">
        <span class="topbar__meta">Updated <b>{{ now()->format('H:i') }}</b> · {{ ea_last_sync_label() }}</span>
        <form method="POST" action="{{ tenant_url('/sync') }}" style="margin:0;">
            @csrf
            <button type="submit" class="refresh-btn" title="Force refresh">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                Refresh
            </button>
        </form>
        <div class="ea-cobadge" title="{{ tenant_brand_name() }}">
            <div class="ea-cobadge__logo">
                @if (tenant_logo_url())
                  <img src="{{ tenant_logo_url() }}" alt="">
                @else
                  {{ tenant_brand_initial() }}
                @endif
            </div>
            <div class="ea-cobadge__t">
                <div class="ea-cobadge__n">{{ tenant_brand_name() }}</div>
                <div class="ea-cobadge__sub">{{ optional(tenant())->name ?? 'Workspace' }}</div>
            </div>
        </div>
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

    {{-- Row 1 · Cumulative Revenue (span 7) — gated on `revenue_ytd` --}}
    @if ($kpiOn('revenue_ytd'))
    <div class="v5-card span-7">
        <div class="v5-card__h">
            <div>
                @if ($isMtd)
                  <div class="v5-card__t">Weekly Revenue · {{ $periodLabel ?? 'MTD' }}</div>
                  <div class="v5-card__sub">4 weeks · actual vs weekly budget · {{ $ccy }}</div>
                @else
                  <div class="v5-card__t">Cumulative Revenue · vs Annual Budget</div>
                  <div class="v5-card__sub">{{ $periodLabel ?? 'YTD' }} {{ $year }} · {{ $ccy }} · cumulative</div>
                @endif
            </div>
            <div class="v5-card__leg">
                @if ($isMtd)
                  <span><span class="lswatch" style="background:var(--ea-primary);"></span>Actual</span>
                  @if (!empty($cumBudget))<span><span class="lline" style="background:var(--warn);"></span>Budget</span>@endif
                @else
                  <span><span class="lline" style="background:var(--ea-primary);"></span>Actual</span>
                  @if (!empty($cumBudget))<span><span class="lline" style="background:var(--warn);"></span>Budget</span>@endif
                  <span><span class="lline" style="background:var(--text-faint);"></span>Linear pace</span>
                @endif
            </div>
        </div>
        <div class="v5-card__b" id="ec-trajectory">
            @if (empty($dailySeries))<div class="empty-state">No revenue series for this period.</div>@endif
        </div>
        <div class="v5-card__f">
            @if ($isMtd)
              @php
                  $mtdActual = array_sum($weeklyActual);
                  $mtdBudget = array_sum($weeklyBudget);
                  $mtdAchPct = $mtdBudget > 0 ? 100.0 * $mtdActual / $mtdBudget : null;
              @endphp
              <b>Reading:</b> MTD <b>{{ $shortNum($mtdActual) }}{{ $shortUnit($mtdActual, $ccy) }}</b>
              @if ($mtdBudget > 0)
                vs budget <b>{{ $shortNum($mtdBudget) }}{{ $shortUnit($mtdBudget, $ccy) }}</b>
                · <span class="{{ $mtdAchPct >= 100 ? 'pos' : ($mtdAchPct >= 80 ? 'warn' : 'neg') }}">{{ number_format($mtdAchPct, 1) }}% of plan</span>.
              @else
                · <span class="warn">no budget uploaded for this month</span>.
              @endif
            @else
              <b>Reading:</b> Daily run-rate <b>{{ $shortNum($dailyPace) }}{{ $shortUnit($dailyPace, $ccy) }}</b>
              · {{ $workingDaysSoFar }}/{{ $fullPeriodWorkingDays }} WD ({{ number_format(100 * $workingDaysSoFar / $fullPeriodWorkingDays, 1) }}%)
              · year-end at pace: <b>{{ $shortNum($runRate) }}{{ $shortUnit($runRate, $ccy) }}</b>
              @if ($cumBudgetTotal > 0)
                vs annual budget <b>{{ $shortNum($cumBudgetTotal) }}{{ $shortUnit($cumBudgetTotal, $ccy) }}</b>
                · <span class="{{ $runRate >= $cumBudgetTotal ? 'pos' : 'warn' }}">{{ number_format(100 * $runRate / $cumBudgetTotal, 1) }}% of plan at pace</span>.
              @else
                . <span class="warn">No annual budget uploaded — upload via /budget-upload to see plan variance.</span>
              @endif
            @endif
        </div>
    </div>
    @endif {{-- /revenue_ytd --}}

    {{-- Row 1 · Margin Waterfall (span 5) — gated on any margin-layer KPI --}}
    @if ($kpiOn('gross_profit') || $kpiOn('ebitda') || $kpiOn('net_profit') || $kpiOn('opex'))
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
    @endif {{-- /margin layers --}}

    {{-- Row 2 · Cost Pressure (span 4) — gated on any of CPO / DPOT / DPC --}}
    @if ($kpiOn('cpo') || $kpiOn('dpot') || $kpiOn('dpc'))
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
    @endif {{-- /cost ratio --}}

    {{-- Row 2 · Run-Rate Bullet (span 4) — gated on `run_rate` --}}
    @if ($kpiOn('run_rate'))
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
    @endif {{-- /run_rate --}}

    {{-- Row 2 · Sankey · Revenue Reconciliation (scan → cash) (span 4)
         Gated by the per-tenant `revenue_reconciliation` module flag.
         When the flag is off, the controller doesn't fetch $recH and
         this entire card is skipped — the row collapses to whatever
         else lives on Row 2. --}}
    @if (tenant()?->moduleEnabled('revenue_reconciliation'))
    @php
        // Scan-to-cash framing — every leakage explicit:
        //   Reconciliation 1 · Scanned (Expected) → Billed (+ Leakage)
        //   Reconciliation 2 · Billed → Collected (+ Receivables)
        // The graph renders both leakages as separate sankey targets so
        // the user can read "of what was scanned, X went to cash, Y was
        // never billed, Z is still outstanding AR" in a single glance.
        $expectedAmt  = (float) ($recH['expected']  ?? 0);
        $billedAmt    = (float) ($recH['billed']    ?? 0);
        $collectedAmt = (float) ($recH['collected'] ?? 0);
        $billLeak     = max(0.0, $expectedAmt - $billedAmt);   // Recon 1 gap
        $arOutstanding = max(0.0, $billedAmt   - $collectedAmt); // Recon 2 gap
        // Conversion rates ─ all framed against EXPECTED (the operational baseline)
        $scanToBillPct  = $expectedAmt > 0 ? 100.0 * $billedAmt    / $expectedAmt : null;
        $billToCashPct  = $billedAmt   > 0 ? 100.0 * $collectedAmt / $billedAmt   : null;
        $scanToCashPct  = $expectedAmt > 0 ? 100.0 * $collectedAmt / $expectedAmt : null;
    @endphp
    <div class="v5-card span-4">
        <div class="v5-card__h">
            <div>
                <div class="v5-card__t">Revenue Reconciliation · Scan → Cash</div>
                <div class="v5-card__sub">Scanned → Billed (+ Leakage) → Collected (+ AR)</div>
            </div>
        </div>
        <div class="v5-card__b" id="ec-sankey">
            @if (!$recH)<div class="empty-state">Reconciliation not available.</div>@endif
        </div>
        <div class="v5-card__f">
            @if ($recH && $expectedAmt > 0)
              <b>Reading:</b>
              Scan-to-cash <span class="{{ $scanToCashPct >= 60 ? 'pos' : ($scanToCashPct >= 30 ? 'warn' : 'neg') }}">{{ number_format($scanToCashPct, 1) }}%</span>.
              Bill leakage <span class="warn">−{{ $shortNum($billLeak) }}{{ $shortUnit($billLeak, $ccy) }}</span>
              ({{ number_format($scanToBillPct, 1) }}% billed) ·
              AR <span class="neg">−{{ $shortNum($arOutstanding) }}{{ $shortUnit($arOutstanding, $ccy) }}</span>
              ({{ number_format($billToCashPct, 1) }}% collected).
            @else
              <b>Reading:</b> Run sync to compute reconciliation.
            @endif
        </div>
    </div>
    @endif {{-- /revenue_reconciliation module flag --}}

    {{-- Row 3 · Unit Economics small multiples (span 12) — only the
         metrics enabled in /admin/tenants/{id} render. Card hides
         entirely when all 4 are disabled. --}}
    @if (count($unitData) > 0)
    <div class="v5-card span-12">
        <div class="v5-card__h">
            <div>
                <div class="v5-card__t">Unit Economics</div>
                <div class="v5-card__sub">{{ implode(' · ', array_map(fn ($u) => explode(' ', $u[0])[0], $unitData)) }} — current period vs prior year · 1 dp</div>
            </div>
        </div>
        <div class="v5-card__b" id="ec-unit"></div>
        <div class="v5-card__f">
            <b>Reading:</b>
            @php
                $rpdDelta = $kpiOn('rpd') ? $variance($cur['rpd'] ?? null, $prev['rpd'] ?? null) : null;
                $opdDelta = $kpiOn('opd') ? $variance($cur['opd'] ?? null, $prev['opd'] ?? null) : null;
                $rpoDelta = $kpiOn('rpo') ? $variance($cur['rpo'] ?? null, $prev['rpo'] ?? null) : null;
                $rpcDelta = $kpiOn('rpc') ? $variance($cur['per_customer'] ?? null, $prev['per_customer'] ?? null) : null;
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
    @endif

</div>

{{-- ── KPI strip (6 cells — each gated individually so an admin can hide --}}
{{--    any single headline metric per tenant from /admin/tenants/{id}). --}}
<div class="strip">
    @if ($kpiOn('revenue_ytd'))
    <div class="strip__c">
        <div class="strip__l">Revenue {{ $isMtd ? 'MTD' : 'YTD' }}</div>
        <div class="strip__v">{{ $shortNum($rev) }}<span class="u">{{ $shortUnit($rev, $ccy) }}</span></div>
        <div class="strip__m">tracking pace</div>
    </div>
    @endif {{-- /revenue_ytd strip --}}
    @if ($kpiOn('gross_profit'))
    <div class="strip__c">
        <div class="strip__l">Gross Profit</div>
        <div class="strip__v {{ $gp >= 0 ? 'is-pos' : 'is-neg' }}">{{ $shortNum($gp) }}<span class="u">{{ $shortUnit($gp, $ccy) }}</span></div>
        <div class="strip__m">{{ number_format($gpPct, 1) }}% margin</div>
    </div>
    @endif {{-- /gross_profit strip --}}
    @if ($kpiOn('ebitda'))
    <div class="strip__c">
        <div class="strip__l">EBITDA</div>
        <div class="strip__v {{ $ebitda >= 0 ? 'is-pos' : 'is-neg' }}">{{ $ebitda < 0 ? '−' : '' }}{{ $shortNum(abs($ebitda)) }}<span class="u">{{ $shortUnit($ebitda, $ccy) }}</span></div>
        <div class="strip__m">{{ number_format($ebPct, 1) }}% margin</div>
    </div>
    @endif {{-- /ebitda strip --}}
    @if ($kpiOn('net_profit'))
    <div class="strip__c">
        <div class="strip__l">Net Profit</div>
        <div class="strip__v {{ $net >= 0 ? 'is-pos' : 'is-neg' }}">{{ $net < 0 ? '−' : '' }}{{ $shortNum(abs($net)) }}<span class="u">{{ $shortUnit($net, $ccy) }}</span></div>
        <div class="strip__m">{{ number_format($netPct, 1) }}% margin</div>
    </div>
    @endif {{-- /net_profit strip --}}
    @if ($kpiOn('cash_collected'))
    <div class="strip__c">
        <div class="strip__l">Cash Collected</div>
        <div class="strip__v {{ $cashPct !== null && $cashPct >= 80 ? 'is-pos' : '' }}">{{ $shortNum($cash) }}<span class="u">{{ $shortUnit($cash, $ccy) }}</span></div>
        <div class="strip__m">{{ $cashPct !== null ? number_format($cashPct, 1) . '% of billed' : '—' }}</div>
    </div>
    @endif {{-- /cash_collected strip --}}
    @if ($kpiOn('ar_outstanding'))
    <div class="strip__c">
        <div class="strip__l">AR Outstanding</div>
        <div class="strip__v">{{ $shortNum($ar) }}<span class="u">{{ $shortUnit($ar, $ccy) }}</span></div>
        <div class="strip__m">{{ $dso > 0 ? 'DSO ' . round($dso) . 'd' : 'DSO —' }}</div>
    </div>
    @endif {{-- /ar_outstanding strip --}}
</div>

{{-- ── Efficiency KPI strip — DSO · DPO · Cost/scan · Revenue/scan · EBITDA% ·
       EBIT%. Each shows the latest month value with a month-over-month arrow:
       green ▲ if better than last month, red ▼ if worse. --}}
@if (!empty($extraKpis))
@php
    $fmtKpi = function ($v, $kpi) use ($ccy) {
        if (!is_numeric($v)) return '—';
        return match ($kpi['fmt'] ?? 'pct') {
            'days'  => number_format((float) $v, 0) . ' d',
            'money' => ea_money_short((float) $v, $kpi['ccy'] ?? $ccy),
            default => number_format((float) $v, 1) . '%',
        };
    };
@endphp
<div class="strip" style="margin-top: 6px;">
    @foreach ($extraKpis as $kpi)
        @php
            $val = $kpi['value']; $prev = $kpi['prev']; $hb = (bool) ($kpi['higher_better'] ?? true);
            $better = null; $deltaPct = null;
            if (is_numeric($val) && is_numeric($prev)) {
                $better = ((float) $val === (float) $prev) ? null : ($hb ? ($val > $prev) : ($val < $prev));
                if ((float) $prev != 0.0) $deltaPct = (($val - $prev) / abs((float) $prev)) * 100.0;
            }
        @endphp
        <div class="strip__c">
            <div class="strip__l">{{ $kpi['label'] }}</div>
            <div class="strip__v">{{ $fmtKpi($val, $kpi) }}</div>
            <div class="strip__m">
                @if ($better === null)
                    <span style="color: var(--text-faint);">— vs last mo</span>
                @else
                    <span style="color: {{ $better ? 'var(--pos)' : 'var(--neg)' }}; font-weight: 700;">{{ $better ? '▲' : '▼' }}@if ($deltaPct !== null) {{ number_format(abs($deltaPct), 1) }}%@endif</span><span style="color: var(--text-faint);"> vs last mo</span>
                @endif
            </div>
        </div>
    @endforeach
</div>
@endif

{{-- ── KPI Targets & Trends ───────────────────────────────────────────
     Input budgeted targets for each efficiency KPI, then see the monthly
     actual plotted against that target line across the year. Sits below the
     single-screen board (the page scrolls to reach it). The form reuses the
     cached POST /sync route via an `_intent` marker (a dedicated route would
     404 behind this host's stale route cache). --}}
@if (!empty($extraKpis))
@php
    $kyear = (int) ($period['year'] ?? now()->format('Y'));
    // Single JSON blob for the charts (Blade @json wants one variable):
    // per KPI → label, fmt, currency, 12-month actual series, budget target.
    $kpiGraphData = [];
    foreach ($extraKpis as $kkey => $kkpi) {
        $kpiGraphData[$kkey] = [
            'label'  => $kkpi['label'] ?? $kkey,
            'fmt'    => $kkpi['fmt'] ?? 'pct',
            'ccy'    => $kkpi['ccy'] ?? ($ccy ?? 'XAF'),
            'series' => $kkpi['series'] ?? [],
            'budget' => $kpiBudgets[$kkey] ?? null,
        ];
    }
    $unitOf = fn ($kpi) => ($kpi['fmt'] ?? '') === 'days' ? 'days'
        : (($kpi['fmt'] ?? '') === 'money' ? (($kpi['ccy'] ?? ($ccy ?? 'XAF')) . '/scan') : '%');
@endphp
<section class="kpanel">
    <div class="kpanel__h">
        <div class="kpanel__t">KPI Targets &amp; Trends <span>{{ $kyear }} · monthly actual vs your budget</span></div>
    </div>

    <form method="POST" action="{{ tenant_url('/sync') }}" class="kform">
        @csrf
        <input type="hidden" name="_intent" value="kpi_budget">
        <input type="hidden" name="year" value="{{ $kyear }}">
        @foreach ($extraKpis as $kkey => $kkpi)
            <div class="kform__c">
                <label class="kform__l" for="kb-{{ $kkey }}" title="{{ $kkpi['label'] }} target">{{ $kkpi['label'] }} ({{ $unitOf($kkpi) }})</label>
                <input class="kform__i" type="number" step="any" id="kb-{{ $kkey }}" name="budget[{{ $kkey }}]"
                       value="{{ ($kpiBudgets[$kkey] ?? null) !== null ? rtrim(rtrim(number_format((float) $kpiBudgets[$kkey], 4, '.', ''), '0'), '.') : '' }}"
                       placeholder="—">
            </div>
        @endforeach
        <div class="kform__save">
            <span class="kform__hint">Set a target per KPI; leave a field blank to remove its line. Saving refreshes the charts.</span>
            <button type="submit" class="kform__btn">Save targets</button>
        </div>
    </form>

    <div class="kgrid">
        @foreach ($extraKpis as $kkey => $kkpi)
            <div class="kgrid__c">
                <div class="kgrid__t"><span>{{ $kkpi['label'] }}</span><span class="kgrid__u">{{ $unitOf($kkpi) }}</span></div>
                <div class="kchart" id="kg-{{ $kkey }}"></div>
            </div>
        @endforeach
    </div>
</section>
@endif

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
  /* Harmonised 1-decimal formatter — used everywhere on the unit-
     economics small multiples so prior/current bars line up
     visually regardless of magnitude. */
  function fmt1(v) {
    if (v == null) return '—';
    const a = Math.abs(v);
    if (a >= 1e6) return (v / 1e6).toFixed(1) + 'M';
    if (a >= 1e3) return (v / 1e3).toFixed(1) + 'K';
    return v.toFixed(1);
  }

  /* ── 1. Trajectory · period-aware ────────────────────────────
     YTD/Month: cumulative actual line vs cumulative annual budget
       (uploaded) vs linear pace (synthesised reference). All three
       lines drawn over Jan→Dec.
     MTD: 4 weekly bars (actual revenue) vs weekly budget target
       (monthly budget ÷ 4 — uniform split). Same card, different
       chart type to match the period the user has selected. */
  const trajectoryEl = document.getElementById('ec-trajectory');
  const trajMode  = @json($trajectoryMode);                  // 'ytd' or 'mtd'
  const cumActual = @json($cumActualJson);
  const cumLinear = @json($cumLinearJson);
  const cumBudget = @json($cumBudgetJson);
  const wkActual  = @json($weeklyActualJson);
  const wkBudget  = @json($weeklyBudgetJson);
  const wkLabels  = @json($weekLabelsJson);

  if (trajectoryEl) {
    if (trajMode === 'mtd' && wkActual && wkActual.length === 4) {
      // ── MTD: weekly bars + budget line ─────────────────────────
      echarts.init(trajectoryEl).setOption({
        textStyle: { fontFamily: FF, color: '#374151' },
        grid: { left: 48, right: 16, top: 18, bottom: 22, containLabel: false },
        tooltip: tip,
        legend: { right: 8, top: 0, icon: 'roundRect', itemWidth: 14, itemHeight: 4,
          textStyle: { fontSize: 9, color: t.textM, fontFamily: FF } },
        xAxis: { type: 'category', data: wkLabels,
          axisLine: { lineStyle: { color: t.base } }, axisTick: { show: false },
          axisLabel: { color: t.textM, fontSize: 10 } },
        yAxis: { type: 'value', axisLine: { show: false }, axisTick: { show: false },
          splitLine: { lineStyle: { color: t.grid } },
          axisLabel: { color: t.textM, fontSize: 9, formatter: v => fmt(v) } },
        series: [
          { name: 'Actual (week)', type: 'bar', data: wkActual,
            barWidth: 32, itemStyle: { color: t.pri } },
          ...(wkBudget && wkBudget.some(v => v > 0) ? [{
            name: 'Budget (week)', type: 'line', smooth: true, data: wkBudget,
            lineStyle: { color: t.warn, type: 'dashed', width: 1.6 }, itemStyle: { color: t.warn },
            symbol: 'circle', symbolSize: 5,
          }] : []),
        ],
      });
    } else if (cumActual.some(v => v != null)) {
      // ── YTD/Month: cumulative line + budget overlay + linear pace ─
      const series = [
        { name: 'Actual (cum)', type: 'line', data: cumActual,
          lineStyle: { color: t.pri, width: 2 }, itemStyle: { color: t.pri },
          areaStyle: { color: t.area }, symbol: 'circle', symbolSize: 5 },
      ];
      if (cumBudget && cumBudget.length === 12 && cumBudget[11] > 0) {
        series.push({
          name: 'Annual budget (cum)', type: 'line', smooth: true, data: cumBudget,
          lineStyle: { color: t.warn, type: 'dashed', width: 1.6 }, itemStyle: { color: t.warn },
          symbol: 'none',
        });
      }
      series.push({
        name: 'Linear pace', type: 'line', data: cumLinear,
        lineStyle: { color: t.textF, type: 'dashed', width: 1.5 }, itemStyle: { color: t.textF },
        symbol: 'none',
      });
      echarts.init(trajectoryEl).setOption({
        textStyle: { fontFamily: FF, color: '#374151' },
        grid: { left: 48, right: 16, top: 18, bottom: 22, containLabel: false },
        tooltip: tip,
        legend: { right: 8, top: 0, icon: 'roundRect', itemWidth: 14, itemHeight: 4,
          textStyle: { fontSize: 9, color: t.textM, fontFamily: FF } },
        xAxis: { type: 'category', data: ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'],
          axisLine: { lineStyle: { color: t.base } }, axisTick: { show: false },
          axisLabel: { color: t.textM, fontSize: 9 } },
        yAxis: { type: 'value', axisLine: { show: false }, axisTick: { show: false },
          splitLine: { lineStyle: { color: t.grid } },
          axisLabel: { color: t.textM, fontSize: 9, formatter: v => fmt(v) } },
        series: series,
      });
    }
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

  /* ── 4. Unit Economics small multiples — registry-filtered ─────
     Card auto-shrinks to whichever metrics the tenant admin has
     enabled in /admin/tenants/{id} (rpd / opd / rpo / rpc). The
     grid widths recompute against the actual count, and every
     visible number uses fmt1 (1 decimal place) so prior vs current
     bars line up regardless of magnitude. */
  const unitEl = document.getElementById('ec-unit');
  const unit = @json($unitData);
  if (unitEl && unit.length > 0 && unit.some(u => u[1][1] > 0)) {
    const cols = unit.length;
    const slot = 100 / cols;
    const grids = unit.map((_, i) => ({
      left: `${i * slot + 3}%`,
      right: `${100 - (i + 1) * slot + 1}%`,
      top: 28, bottom: 22,
    }));
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
        axisLabel: { color: t.textM, fontSize: 9, formatter: v => fmt1(v) } })),
      series: unit.map((u, i) => ({
        type: 'bar', xAxisIndex: i, yAxisIndex: i, data: u[1],
        itemStyle: { color: (u[1][1] >= u[1][0]) ? t.pri : t.neg },
        barWidth: '60%',
        label: { show: true, position: 'top', color: t.textM, fontSize: 9, formatter: p => fmt1(p.value) }
      })),
    });
  }

  /* ── 5. Sankey · Scan-to-Cash flow ────────────────────────────
     Shows BOTH leakages explicitly:
       Reconciliation 1 · Scanned → Billed (+ Bill leakage)
       Reconciliation 2 · Billed  → Collected (+ Receivables)
     The user reads "of every X scanned, Y reached cash" in one
     glance because every node and gap is visible, not just the
     billed-to-cash slice.                                       */
  const sankeyEl = document.getElementById('ec-sankey');
  const recH = @json($recH);
  if (sankeyEl && recH) {
    const expected   = Math.max(0, +recH.expected  || 0);
    const billed     = Math.max(0, +recH.billed    || 0);
    const collected  = Math.max(0, +recH.collected || 0);
    const billLeak   = Math.max(0, expected - billed);    // Recon 1 gap
    const arOutstand = Math.max(0, billed   - collected); // Recon 2 gap

    if (expected > 0 || billed > 0) {
      // Conversion context — framed against scanned (the operational baseline).
      const scanToBill = expected > 0 ? (100 * billed    / expected) : null;
      const scanToCash = expected > 0 ? (100 * collected / expected) : null;
      const billToCash = billed   > 0 ? (100 * collected / billed)   : null;
      const pct = v => (v == null ? '—' : v.toFixed(1) + '%');
      const dim = '#6b7280';

      echarts.init(sankeyEl).setOption({
        textStyle: { fontFamily: FF, color: '#374151' },
        series: [{
          type: 'sankey',
          left: 6, right: 92, top: 4, bottom: 4,
          // Node values feed the tooltip and let hover highlight the path.
          data: [
            { name: 'Scanned',     value: expected,   itemStyle: { color: t.warnLt } },
            { name: 'Billed',      value: billed,     itemStyle: { color: t.pri } },
            { name: 'Leakage',     value: billLeak,   itemStyle: { color: t.warn, opacity: 0.85 } },
            { name: 'Collected',   value: collected,  itemStyle: { color: t.pos } },
            { name: 'Receivables', value: arOutstand, itemStyle: { color: t.neg } },
          ],
          links: [
            // Reconciliation 1 · Scanned → (Billed + Leakage)
            { source: 'Scanned', target: 'Billed',  value: billed   || 0.001,
              lineStyle: { color: t.pri,  opacity: 0.55 } },
            ...(billLeak > 0 ? [{ source: 'Scanned', target: 'Leakage', value: billLeak,
              lineStyle: { color: t.warn, opacity: 0.50 } }] : []),
            // Reconciliation 2 · Billed → (Collected + Receivables)
            ...(billed > 0 ? [
              { source: 'Billed', target: 'Collected',   value: collected  || 0.001,
                lineStyle: { color: t.pos, opacity: 0.55 } },
              { source: 'Billed', target: 'Receivables', value: arOutstand || 0.001,
                lineStyle: { color: t.neg, opacity: 0.55 } },
            ] : []),
          ],
          orient: 'horizontal', nodeAlign: 'justify', nodeWidth: 14, nodeGap: 12,
          label: { fontSize: 10, fontWeight: 600, color: '#111827', fontFamily: FF },
          lineStyle: { curveness: 0.5 },
          // Hover a node (e.g. the yellow Scanned) → dim everything else and
          // light up the full path it feeds, so the scanned-revenue flow pops.
          emphasis: {
            focus: 'adjacency',
            itemStyle: { borderColor: '#111827', borderWidth: 1.5 },
            lineStyle: { opacity: 0.85 },
          },
          blur: { itemStyle: { opacity: 0.25 }, lineStyle: { opacity: 0.07 } },
        }],
        tooltip: { ...tip, trigger: 'item', confine: true,
          formatter: function (p) {
            if (p.dataType === 'edge') {
              return '<b>' + p.data.source + ' → ' + p.data.target + '</b>: ' + fmt(p.data.value);
            }
            const v = fmt(p.value);
            switch (p.name) {
              case 'Scanned':
                return '<b>Scanned revenue</b>: ' + v
                  + '<br/>→ Billed ' + fmt(billed) + ' <span style="color:' + dim + '">(' + pct(scanToBill) + ')</span>'
                  + '<br/>→ Collected ' + fmt(collected) + ' <span style="color:' + dim + '">(' + pct(scanToCash) + ' scan→cash)</span>';
              case 'Billed':
                return '<b>Billed</b>: ' + v + '<br/><span style="color:' + dim + '">' + pct(scanToBill) + ' of scanned · ' + pct(billToCash) + ' collected</span>';
              case 'Collected':
                return '<b>Collected</b>: ' + v + '<br/><span style="color:' + dim + '">' + pct(scanToCash) + ' of scanned revenue</span>';
              case 'Leakage':
                return '<b>Bill leakage</b>: ' + v + '<br/><span style="color:' + dim + '">scanned but never billed</span>';
              case 'Receivables':
                return '<b>Receivables</b>: ' + v + '<br/><span style="color:' + dim + '">billed but not yet collected</span>';
              default:
                return '<b>' + p.name + '</b>: ' + v;
            }
          } },
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

@push('scripts')
<script>
/* ── KPI Targets & Trends: one line chart per efficiency KPI — monthly
   actual vs the flat budget target the user saved. */
(function () {
  if (typeof echarts === 'undefined') return;
  const data = @json($kpiGraphData ?? []);
  const MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  const PRI = '#0d6e6e', BUD = '#d97706', GRID = '#f3f4f6', TEXT = '#6b7280';
  const FF = "'Segoe UI',-apple-system,BlinkMacSystemFont,system-ui,sans-serif";

  function fmtVal(v, fmt) {
    if (v === null || v === undefined || isNaN(v)) return '—';
    if (fmt === 'pct')  return (Math.round(v * 10) / 10) + '%';
    if (fmt === 'days') return Math.round(v) + ' d';
    const a = Math.abs(v);                       // money → compact
    if (a >= 1e9) return (v / 1e9).toFixed(2) + 'B';
    if (a >= 1e6) return (v / 1e6).toFixed(2) + 'M';
    if (a >= 1e3) return (v / 1e3).toFixed(1) + 'k';
    return Math.round(v).toString();
  }

  const instances = [];
  Object.keys(data).forEach(function (key) {
    const el = document.getElementById('kg-' + key);
    if (!el) return;
    const d = data[key] || {};
    const fmt = d.fmt || 'pct';
    const series = (d.series || []).map(x => (x === null || x === undefined || x === '') ? null : +x);
    const budget = (d.budget === null || d.budget === undefined || d.budget === '') ? null : +d.budget;

    const ser = [{
      name: 'Actual', type: 'line', data: series, connectNulls: false,
      smooth: true, showSymbol: true, symbolSize: 5,
      lineStyle: { color: PRI, width: 2 }, itemStyle: { color: PRI },
      areaStyle: { color: 'rgba(13,110,110,0.07)' },
    }];
    if (budget !== null && !isNaN(budget)) {
      ser.push({
        name: 'Target', type: 'line', data: MONTHS.map(() => budget),
        showSymbol: false, smooth: false,
        lineStyle: { color: BUD, width: 1.5, type: 'dashed' }, itemStyle: { color: BUD },
      });
    }

    const inst = echarts.init(el);
    inst.setOption({
      grid: { left: 6, right: 12, top: 22, bottom: 2, containLabel: true },
      legend: { show: budget !== null, right: 0, top: 0, itemWidth: 12, itemHeight: 8,
        textStyle: { fontSize: 9, color: TEXT, fontFamily: FF } },
      tooltip: {
        trigger: 'axis', backgroundColor: '#fff', borderColor: '#e5e7eb', borderWidth: 1,
        padding: [6, 10], textStyle: { color: '#111827', fontSize: 11, fontFamily: FF },
        formatter: function (ps) {
          let s = '<b>' + ps[0].axisValue + '</b>';
          ps.forEach(p => { if (p.value !== null && p.value !== undefined) s += '<br/>' + p.marker + p.seriesName + ': ' + fmtVal(p.value, fmt); });
          return s;
        },
      },
      xAxis: { type: 'category', data: MONTHS, boundaryGap: false,
        axisLine: { lineStyle: { color: '#e5e7eb' } }, axisTick: { show: false },
        axisLabel: { fontSize: 9, color: TEXT, fontFamily: FF } },
      yAxis: { type: 'value', scale: true,
        splitLine: { lineStyle: { color: GRID } },
        axisLabel: { fontSize: 9, color: TEXT, fontFamily: FF, formatter: (v) => fmtVal(v, fmt) } },
      series: ser,
    });
    instances.push(inst);
  });

  function resizeK() { instances.forEach(i => { try { i.resize(); } catch (e) {} }); }
  window.addEventListener('resize', resizeK);
  setTimeout(resizeK, 140);
})();
</script>
@endpush

@endsection
