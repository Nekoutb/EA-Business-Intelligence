@extends('layouts.app')
@section('title', 'Receivables')
@section('page-title', 'Accounts Receivable')

{{-- Lock the master sidebar filter to YTD on this page. AR is a
     snapshot metric — MTD/Month/YoY aren't meaningful here. The
     year picker stays live so users can review prior-year
     snapshots; the month picker is greyed out. --}}
@section('mfilter-frozen', 'mtd,month,yoy')

@push('styles')
<style>
  .ar-strip { display: grid; grid-template-columns: repeat(6, 1fr); background: var(--surface); border: 1px solid var(--border); border-radius: var(--r); margin-bottom: 8px; overflow: hidden; }
  .ar-strip__c { padding: 6px 10px; border-right: 1px solid var(--border-faint); }
  .ar-strip__c:last-child { border-right: 0; }
  .ar-strip__l { font: 700 9px var(--font); color: var(--text-subtle); letter-spacing: .06em; text-transform: uppercase; margin-bottom: 2px; }
  .ar-strip__v { font: 700 14px var(--font); color: var(--text); line-height: 1; letter-spacing: -.01em; }
  .ar-strip__v.is-pos { color: var(--pos); }
  .ar-strip__v.is-neg { color: var(--neg); }
  .ar-strip__v.is-warn { color: var(--warn); }
  .ar-strip__v .u { font: 500 10px var(--font); color: var(--text-subtle); margin-left: 1px; }
  .ar-strip__m { font: 500 9px var(--font); color: var(--text-subtle); margin-top: 2px; }

  .ar-grid { display: grid; gap: 8px; grid-template-columns: repeat(12, 1fr); margin-bottom: 8px; }
  .ar-grid .span-12 { grid-column: span 12; }
  .ar-grid .span-8 { grid-column: span 8; }
  .ar-grid .span-6 { grid-column: span 6; }
  .ar-grid .span-4 { grid-column: span 4; }

  .ar-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-lg); padding: 10px 12px; display: flex; flex-direction: column; }
  .ar-card__h { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; margin-bottom: 6px; }
  .ar-card__t { font: 700 12px var(--font); color: var(--text); }
  .ar-card__sub { font: 400 10.5px var(--font); color: var(--text-subtle); margin-top: 1px; }
  .ar-card__leg { display: flex; gap: 10px; font: 500 10px var(--font); color: var(--text-subtle); flex-wrap: wrap; }
  .ar-card__leg > span { display: inline-flex; align-items: center; gap: 4px; }
  .ar-lline { width: 12px; height: 2px; }
  .ar-card__b { width: 100%; position: relative; }
  .ar-card__f { font: 500 10px var(--font); color: var(--text-subtle); margin-top: 6px; padding-top: 6px; border-top: 1px solid var(--border-faint); line-height: 1.4; }
  .ar-card__f b { color: var(--text); font-weight: 600; }
  .ar-card__f .pos { color: var(--pos); font-weight: 600; }
  .ar-card__f .neg { color: var(--neg); font-weight: 600; }
  .ar-card__f .warn { color: var(--warn); font-weight: 600; }
  .ar-chart-h-320 { height: 320px; }
  .ar-chart-h-220 { height: 220px; }

  /* Collapsible top offenders block */
  details.ar-reveal { background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-lg); }
  details.ar-reveal > summary {
    list-style: none; cursor: pointer;
    padding: 12px 14px;
    display: flex; align-items: center; justify-content: space-between; gap: 8px;
    font: 700 12px var(--font); color: var(--text);
    user-select: none;
  }
  details.ar-reveal > summary::-webkit-details-marker { display: none; }
  details.ar-reveal > summary::after {
    content: '▾'; color: var(--text-subtle); font-size: 14px; transition: transform .2s;
  }
  details.ar-reveal[open] > summary::after { transform: rotate(180deg); }
  details.ar-reveal > summary:hover { background: var(--surface-alt); }
  .ar-reveal__chip { font: 600 10px var(--font); color: var(--ea-primary); background: rgba(13,110,110,.10); padding: 2px 8px; border-radius: 999px; }
  .ar-reveal__sub  { font: 500 10.5px var(--font); color: var(--text-subtle); margin-left: 6px; font-weight: 500; }
  .ar-reveal__body { padding: 0 14px 12px; border-top: 1px solid var(--border-faint); }

  table.ar-tab { width: 100%; border-collapse: collapse; font: 500 12px var(--font); }
  table.ar-tab thead tr { border-bottom: 1px solid var(--border); }
  table.ar-tab th { font: 700 9px var(--font); color: var(--text-subtle); letter-spacing: .06em; text-transform: uppercase; padding: 8px 8px; text-align: left; }
  table.ar-tab th.r, table.ar-tab td.r { text-align: right; }
  table.ar-tab td { padding: 8px; border-bottom: 1px solid var(--border-faint); font: 500 12px var(--font); color: var(--text-muted); vertical-align: top; }
  table.ar-tab tr:last-child td { border-bottom: 0; }
  table.ar-tab tr:hover td { background: var(--surface-alt); }
  table.ar-tab td .name { font: 600 12px var(--font); color: var(--text); display: block; line-height: 1.2; }
  table.ar-tab td .meta { font: 500 9.5px var(--font); color: var(--text-faint); margin-top: 1px; }
  .ar-rating { display: inline-flex; align-items: center; gap: 5px; padding: 2px 8px; border-radius: 999px; font: 700 10px var(--font); border: 1px solid; }
  .ar-rating .dot { width: 6px; height: 6px; border-radius: 50%; }
  .ar-rating--prime    { color: var(--pos); background: #ecfdf5; border-color: #a7f3d0; }
  .ar-rating--prime .dot { background: var(--pos); }
  .ar-rating--watch    { color: var(--warn); background: var(--warn-bg); border-color: var(--warn-bd); }
  .ar-rating--watch .dot { background: var(--warn); }
  .ar-rating--risk     { color: #b45309; background: #fff7ed; border-color: #fed7aa; }
  .ar-rating--risk .dot { background: #b45309; }
  .ar-rating--critical { color: var(--neg); background: #fef2f2; border-color: #fecaca; }
  .ar-rating--critical .dot { background: var(--neg); }

  .ar-empty { padding: 24px; text-align: center; color: var(--text-faint); font: 500 11px var(--font); }
  .ar-error { padding: 12px 16px; background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; border-radius: var(--r); font-size: 12px; margin-bottom: 8px; }
</style>
@endpush

@section('content')

{{-- Logistics sector: fees vs débours split + ageing, from the invoice warehouse --}}
@includeWhen(!empty($logistics), 'logistics.receivables-section', ['logistics' => $logistics ?? null])

@if ($error)
  <div class="ar-error">
    <strong>Error:</strong> {{ $error }} ·
    <a href="{{ tenant_url('/settings') }}" style="color:#991b1b; text-decoration:underline;">Check Odoo settings</a>
  </div>
@elseif (!$data)
  <div class="ar-empty">Loading receivables data…</div>
@else

@php
  $ccy        = $data['currency']     ?? 'XAF';
  $totalAr    = (float) ($data['total_ar']     ?? 0);
  $overdueAr  = (float) ($data['overdue_ar']   ?? 0);
  $overdueRate = (float) ($data['overdue_rate'] ?? 0);
  $dso        = $data['dso'] ?? null;
  $dsoBasis   = $data['dso_basis'] ?? null;
  // Defensive numeric coerce for downstream arithmetic + display (the
  // tile keeps showing "—" when null).
  $dsoNum     = is_numeric($dso) ? (float) $dso : null;

  // Tooltip + tile suffix labels — built here in PHP rather than
  // inline in the title="" attribute. Blade's tokenizer chokes on
  // nested ternaries with string-concatenation when they include
  // unicode separator characters (· × ÷) inside attribute values.
  $dsoTooltip = 'DSO not available';
  $dsoMethodSuffix = '';
  $dsoTileMeta = 'target ≤ 30 d';
  if ($dsoBasis) {
      $methodHint = match ($dsoBasis['method'] ?? '') {
          'gl_class_41_70' => ' · GL-backed (OHADA class 41 ÷ 70)',
          'gl_credit_90d'  => ' · GL-backed (class 41 ÷ credit sales, 70 against 41)',
          'gl_dormant'     => ' · GL reachable but no recent sales',
          'legacy_invoice' => ' · invoice-derived (GL fallback)',
          default          => '',
      };
      $dsoTooltip = ($dsoBasis['numerator_label'] ?? '?')
                  . ' ÷ ' . ($dsoBasis['denominator_label'] ?? '?')
                  . ' × ' . ($dsoBasis['window_days'] ?? 0) . ' days'
                  . $methodHint;
      if (in_array($dsoBasis['method'] ?? '', ['gl_class_41_70', 'gl_credit_90d'], true)) {
          $dsoMethodSuffix = 'GL';
          $dsoTileMeta = 'rolling ' . ($dsoBasis['window_days'] ?? 90) . ' d · target ≤ 30';
      }
  }
  $dsoDormantHint = ($dsoBasis && ($dsoBasis['method'] ?? '') === 'gl_dormant')
      ? 'no recent sales' : 'not available';
  $avgAge     = (float) ($data['avg_age']      ?? 0);
  $top5Pct    = (float) ($data['top5_pct']     ?? 0);
  $pctGt60    = (float) ($data['pct_gt_60']    ?? 0);
  $pctGt90    = (float) ($data['pct_gt_90']    ?? 0);
  $over60Amt  = (float) ($data['over60_amount'] ?? 0);
  $over90Amt  = (float) ($data['over90_amount'] ?? 0);
  $topPartners = $data['top_partners'] ?? [];
  $arEvolution = $data['ar_evolution'] ?? [];
  $aging       = $data['aging']        ?? [];

  $shortNum = function (float $v) {
      $a = abs($v);
      $sign = $v < 0 ? '-' : '';
      if ($a >= 1_000_000) return $sign . number_format($a / 1_000_000, 2);
      if ($a >= 1_000)     return $sign . number_format($a / 1_000, 0);
      return $sign . number_format($a, 0);
  };
  $shortUnit = function (float $v) {
      $a = abs($v); return ($a >= 1_000_000 ? 'M' : ($a >= 1_000 ? 'K' : ''));
  };

  // ── JS payload — never inline multi-line array literals into @json
  $evoMonths = array_column($arEvolution, 'label');
  $evoTotal  = array_map(fn ($r) => (float) $r['total'],   $arEvolution);
  $evoOver60 = array_map(fn ($r) => (float) $r['over_60'], $arEvolution);
  $evoOver90 = array_map(fn ($r) => (float) $r['over_90'], $arEvolution);
  $hasEvo    = !empty($arEvolution);
  $latestTot = $hasEvo ? end($evoTotal) : 0;
  $firstTot  = $hasEvo ? reset($evoTotal) : 0;
  $arTrendPct = ($hasEvo && $firstTot > 0) ? (($latestTot - $firstTot) / $firstTot) * 100.0 : null;

  // Aging buckets for the side donut
  $bucketSeries = [];
  foreach (['current' => 'Current', '1_30' => '1–30 d', '31_60' => '31–60 d', '61_90' => '61–90 d', '90_plus' => '90+ d'] as $k => $lbl) {
      if (isset($aging[$k]) && (float) $aging[$k]['amount'] > 0) {
          $bucketSeries[] = ['name' => $lbl, 'value' => (float) $aging[$k]['amount']];
      }
  }
@endphp

{{-- KPI strip ────────────────────────────────────────────────── --}}
<div class="ar-strip">
    <div class="ar-strip__c">
        <div class="ar-strip__l">Total AR</div>
        <div class="ar-strip__v">{{ $shortNum($totalAr) }}<span class="u">{{ $shortUnit($totalAr) }} {{ $ccy }}</span></div>
        <div class="ar-strip__m">{{ number_format((int) ($data['open_invoice_count'] ?? 0)) }} open invoices</div>
    </div>
    <div class="ar-strip__c">
        <div class="ar-strip__l">Overdue AR</div>
        <div class="ar-strip__v {{ $overdueAr > 0 ? 'is-warn' : '' }}">{{ $shortNum($overdueAr) }}<span class="u">{{ $shortUnit($overdueAr) }} {{ $ccy }}</span></div>
        <div class="ar-strip__m">{{ number_format($overdueRate, 1) }}% of AR</div>
    </div>
    <div class="ar-strip__c" title="{{ $dsoTooltip }}">
        <div class="ar-strip__l">DSO @if ($dsoMethodSuffix !== '')<span style="font-weight:500; color:var(--ea-primary); letter-spacing:.04em;">· {{ $dsoMethodSuffix }}</span>@endif</div>
        @if ($dsoNum === null)
          <div class="ar-strip__v">—<span class="u">days</span></div>
          <div class="ar-strip__m">{{ $dsoDormantHint }}</div>
        @else
          <div class="ar-strip__v {{ $dsoNum > 60 ? 'is-neg' : ($dsoNum > 30 ? 'is-warn' : 'is-pos') }}">{{ number_format($dsoNum, 0) }}<span class="u">days</span></div>
          <div class="ar-strip__m">{{ $dsoTileMeta }}</div>
        @endif
    </div>
    <div class="ar-strip__c">
        <div class="ar-strip__l">Average Age</div>
        <div class="ar-strip__v">{{ number_format($avgAge, 0) }}<span class="u">days</span></div>
        <div class="ar-strip__m">weighted by amount</div>
    </div>
    <div class="ar-strip__c">
        <div class="ar-strip__l">AR &gt; 60 days</div>
        <div class="ar-strip__v {{ $over60Amt > 0 ? 'is-warn' : '' }}">{{ $shortNum($over60Amt) }}<span class="u">{{ $shortUnit($over60Amt) }} {{ $ccy }}</span></div>
        <div class="ar-strip__m">{{ number_format($pctGt60, 1) }}% of AR</div>
    </div>
    <div class="ar-strip__c">
        <div class="ar-strip__l">AR &gt; 90 days</div>
        <div class="ar-strip__v {{ $over90Amt > 0 ? 'is-neg' : '' }}">{{ $shortNum($over90Amt) }}<span class="u">{{ $shortUnit($over90Amt) }} {{ $ccy }}</span></div>
        <div class="ar-strip__m">{{ number_format($pctGt90, 1) }}% of AR</div>
    </div>
</div>

{{-- Grid: Evolution chart + aging donut ───────────────────────── --}}
<div class="ar-grid">
    <div class="ar-card span-8">
        <div class="ar-card__h">
            <div>
                <div class="ar-card__t">AR Evolution · {{ ($period['year'] ?? date('Y')) }}</div>
                <div class="ar-card__sub">Total AR · AR &gt; 60 days · AR &gt; 90 days · January → {{ now()->format('M') }}</div>
            </div>
            <div class="ar-card__leg">
                <span><span class="ar-lline" style="background:#0d6e6e;"></span>Total AR</span>
                <span><span class="ar-lline" style="background:#d97706;"></span>AR &gt; 60 d</span>
                <span><span class="ar-lline" style="background:#b91c1c;"></span>AR &gt; 90 d</span>
            </div>
        </div>
        <div class="ar-card__b ar-chart-h-320" id="ec-ar-evolution">
            @if (!$hasEvo)<div class="ar-empty">No evolution data yet.</div>@endif
        </div>
        <div class="ar-card__f">
            <b>Reading:</b>
            @if ($arTrendPct !== null)
              Total AR
              @if ($arTrendPct > 5)<span class="warn">up {{ number_format($arTrendPct, 1) }}%</span>@elseif ($arTrendPct < -5)<span class="pos">down {{ number_format(abs($arTrendPct), 1) }}%</span>@else<span>flat ({{ number_format($arTrendPct, 1) }}%)</span>@endif
              YTD.
            @endif
            @if ($pctGt90 > 5)
              <span class="neg">{{ number_format($pctGt90, 1) }}% of AR is over 90 days</span> — escalate collections.
            @elseif ($pctGt60 > 10)
              <span class="warn">{{ number_format($pctGt60, 1) }}% of AR is over 60 days</span> — monitor weekly.
            @else
              Aging profile is healthy.
            @endif
        </div>
    </div>

    <div class="ar-card span-4">
        <div class="ar-card__h">
            <div>
                <div class="ar-card__t">Aging Buckets · today</div>
                <div class="ar-card__sub">Snapshot · current, 1–30, 31–60, 61–90, 90+</div>
            </div>
        </div>
        <div class="ar-card__b ar-chart-h-320" id="ec-ar-aging">
            @if (empty($bucketSeries))<div class="ar-empty">No aging data.</div>@endif
        </div>
        <div class="ar-card__f">
            <b>Reading:</b> Top-5 customer concentration <b>{{ number_format($top5Pct, 1) }}%</b>.
        </div>
    </div>
</div>

{{-- Collapsible Top Offenders ─────────────────────────────────── --}}
@if (!empty($topPartners))
<details class="ar-reveal">
    <summary>
        <span style="display:inline-flex; align-items:center; gap:6px;">
            <span style="display:inline-block; width:3px; height:14px; background:var(--neg); border-radius:2px;"></span>
            Top Offender Scorecard
            <span class="ar-reveal__sub">{{ count($topPartners) }} customers · click to reveal</span>
        </span>
        <span class="ar-reveal__chip">Reveal</span>
    </summary>
    <div class="ar-reveal__body">
        <table class="ar-tab">
            <thead>
                <tr>
                    <th>Customer</th>
                    <th class="r">AR</th>
                    <th class="r">Overdue</th>
                    <th class="r">Share</th>
                    <th class="r">Max overdue</th>
                    <th>Rating</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($topPartners as $p)
                  @php
                      $level = $p['rating_level'] ?? 'watch';
                      $cls   = 'ar-rating--' . $level;
                  @endphp
                  <tr>
                      <td>
                          <span class="name">{{ $p['name'] ?? '—' }}</span>
                          <span class="meta">{{ $p['rating_reason'] ?? '' }}</span>
                      </td>
                      <td class="r">{{ ea_money((float) ($p['ar'] ?? 0), $ccy) }}</td>
                      <td class="r" style="color:{{ ($p['overdue'] ?? 0) > 0 ? 'var(--neg)' : 'var(--text-faint)' }}; font-weight:{{ ($p['overdue'] ?? 0) > 0 ? '700' : '500' }};">
                          {{ ($p['overdue'] ?? 0) > 0 ? ea_money((float) $p['overdue'], $ccy) : '—' }}
                      </td>
                      <td class="r">{{ number_format((float) ($p['share_pct'] ?? 0), 1) }}%</td>
                      <td class="r" style="color:var(--text-muted);">{{ (int) ($p['max_days_overdue'] ?? 0) }} d</td>
                      <td>
                          <span class="ar-rating {{ $cls }}">
                              <span class="dot"></span>{{ $p['rating_label'] ?? 'Watch' }}
                          </span>
                      </td>
                  </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</details>
@endif

@push('scripts')
<script>
(function () {
  const t = { pri:'#0d6e6e', pos:'#047857', neg:'#b91c1c', warn:'#d97706', warnLt:'#fbbf24', textM:'#6b7280', textF:'#9ca3af', grid:'#f3f4f6', base:'#d1d5db' };
  const FF = "'Segoe UI',-apple-system,BlinkMacSystemFont,system-ui,sans-serif";
  const tipShared = { trigger:'axis', backgroundColor:'#fff', borderColor:'#e5e7eb', borderWidth:1, padding:[6,10], textStyle:{color:'#111827',fontSize:11}, axisPointer:{type:'shadow'} };
  function fmt(v) { if (v==null) return '—'; const a=Math.abs(v); if(a>=1e6) return (v/1e6).toFixed(1).replace(/\.0$/,'')+'M'; if(a>=1e3) return (v/1e3).toFixed(0)+'K'; return Math.round(v).toString(); }

  /* Evolution — 3-line area chart, Jan → current */
  const evoEl = document.getElementById('ec-ar-evolution');
  const months = @json($evoMonths);
  const sTotal = @json($evoTotal);
  const sO60   = @json($evoOver60);
  const sO90   = @json($evoOver90);
  if (evoEl && months.length) {
    echarts.init(evoEl).setOption({
      textStyle: { fontFamily: FF, color:'#374151' },
      grid: { left: 56, right: 16, top: 16, bottom: 28, containLabel: false },
      tooltip: { ...tipShared, formatter: params => {
        const lines = [`<b>${params[0].name} ${@json($period['year'] ?? date('Y'))}</b>`];
        params.forEach(p => lines.push(`${p.marker} ${p.seriesName}: ${fmt(p.value)}`));
        return lines.join('<br>');
      }},
      legend: { show: false },
      xAxis: { type:'category', data: months, boundaryGap: false,
        axisLine:{ lineStyle:{ color: t.base } }, axisTick:{ show:false },
        axisLabel:{ color: t.textM, fontSize: 10 } },
      yAxis: { type:'value', axisLine:{ show:false }, axisTick:{ show:false },
        splitLine:{ lineStyle:{ color: t.grid } },
        axisLabel:{ color: t.textM, fontSize: 10, formatter: v => fmt(v) } },
      series: [
        { name:'Total AR', type:'line', data: sTotal, smooth: true,
          lineStyle:{ color: t.pri, width: 2.2 }, itemStyle:{ color: t.pri },
          areaStyle:{ color: 'rgba(13,110,110,0.12)' }, symbol:'circle', symbolSize: 5 },
        { name:'AR > 60 d', type:'line', data: sO60, smooth: true,
          lineStyle:{ color: t.warn, width: 2 }, itemStyle:{ color: t.warn },
          symbol:'circle', symbolSize: 4 },
        { name:'AR > 90 d', type:'line', data: sO90, smooth: true,
          lineStyle:{ color: t.neg, width: 2 }, itemStyle:{ color: t.neg },
          symbol:'circle', symbolSize: 4 },
      ],
    });
  }

  /* Aging buckets — donut */
  const agEl = document.getElementById('ec-ar-aging');
  const buckets = @json($bucketSeries);
  if (agEl && buckets.length) {
    const colorByName = {
      'Current': t.pri,
      '1–30 d': '#338f8f',
      '31–60 d': t.warn,
      '61–90 d': '#b45309',
      '90+ d': t.neg,
    };
    const data = buckets.map(b => ({ ...b, itemStyle: { color: colorByName[b.name] || '#9ca3af' } }));
    echarts.init(agEl).setOption({
      textStyle: { fontFamily: FF },
      tooltip: { trigger:'item', backgroundColor:'#fff', borderColor:'#e5e7eb', borderWidth:1, textStyle:{color:'#111827', fontSize:11},
        formatter: p => `<b>${p.name}</b>: ${fmt(p.value)} (${p.percent}%)` },
      legend: { orient:'vertical', right: 4, top: 'middle', icon: 'circle', itemWidth: 8, itemHeight: 8,
        textStyle: { color: '#6b7280', fontSize: 10, fontFamily: FF } },
      series: [{
        type: 'pie', radius: ['52%', '76%'], center: ['38%', '50%'],
        avoidLabelOverlap: false, label: { show: false }, labelLine: { show: false },
        data: data,
      }],
    });
  }

  function resizeAll() {
    document.querySelectorAll('[id^="ec-ar-"]').forEach(el => {
      const i = echarts.getInstanceByDom(el); if (i) i.resize();
    });
  }
  window.addEventListener('resize', resizeAll);
  setTimeout(resizeAll, 100);
})();
</script>
@endpush

@endif
@endsection
