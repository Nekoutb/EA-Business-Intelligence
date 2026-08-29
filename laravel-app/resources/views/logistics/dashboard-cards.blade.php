{{-- Logistics summary cards for the Dashboard grid. Each card is its own
     admin toggle (config/dashboard_kpis.php, section "logistics"); the
     controller only computes the pieces whose toggles are on. Rendered
     inside the dashboard's 12-column grid, so cards size with span-N. --}}
@php
  $lf  = $logistics['files']   ?? null;
  $lc  = $lf['closure'] ?? null;
  $lo  = $logistics['oop']     ?? null;
  $lv  = $logistics['volumes'] ?? null;
  $lu  = $logistics['unbilled'] ?? null;
  $n   = fn ($v) => number_format((float) $v);
  // Money in millions, like the rest of the dashboard.
  $xaf = fn ($v) => number_format((float) $v / 1_000_000, abs((float) $v) >= 100_000_000 ? 0 : 1) . ' M XAF';
@endphp
<style>
  .lgd-tiles { display:grid; grid-template-columns:repeat(4,1fr); gap:8px; flex:1; align-content:start; }
  .lgd-tile { border:1px solid var(--border-faint,#efece6); border-radius:8px; padding:7px 9px 6px; min-width:0; }
  .lgd-tile .l { font:600 9.5px/1.3 var(--font); letter-spacing:.07em; text-transform:uppercase; color:var(--text-subtle,#7c7466); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .lgd-tile .v { font:700 18px/1.15 var(--font); color:var(--text,#1e3d44); margin-top:4px; font-variant-numeric:tabular-nums; }
  .lgd-tile .s { font:400 10px/1.3 var(--font); color:var(--text-subtle,#7c7466); margin-top:2px; }
  .lgd-tile.bad .v { color:#9a3b2e; } .lgd-tile.good .v { color:#0d6e6e; }
  @media (max-width:900px) { .lgd-tiles { grid-template-columns:repeat(2,1fr); } }
</style>

@if ($kpiOn('logi_file_summary') && $lf)
<div class="v5-card span-6">
  <div class="v5-card__h"><div>
    <div class="v5-card__t">Files · {{ $periodLabel ?? '' }}</div>
    <div class="v5-card__sub">backlog as of {{ $lf['today']['as_of'] ?? '' }} · flow inside the period</div>
  </div></div>
  <div class="v5-card__b"><div class="lgd-tiles">
    <div class="lgd-tile bad"><div class="l">Open files</div><div class="v">{{ $n($lf['open_now'] ?? 0) }}</div><div class="s">as of period end</div></div>
    <div class="lgd-tile"><div class="l">Opened</div><div class="v">{{ $n($lf['opened'] ?? 0) }}</div><div class="s">in period</div></div>
    <div class="lgd-tile bad"><div class="l">Closed</div><div class="v">{{ $n($lf['closed'] ?? 0) }}</div><div class="s">vs {{ $n($lf['opened'] ?? 0) }} opened</div></div>
    <div class="lgd-tile bad"><div class="l">Median backlog age</div><div class="v">{{ ($lf['median_age'] ?? null) !== null ? $n($lf['median_age']) . ' d' : '—' }}</div><div class="s">of open files</div></div>
  </div></div>
  <div class="v5-card__f"><b>Reading:</b> {{ $n(($lf['today']['mix']['90+'] ?? 0)) }}% of the backlog is over 90 days old
    @if (($lf['baseline']['mix']['90+'] ?? null) !== null) ({{ $n($lf['baseline']['mix']['90+']) }}% at the baseline) @endif
    · <a href="{{ tenant_url('/logistics/dossiers') }}">open Files</a></div>
</div>
@endif

@if ($kpiOn('logi_closure_rate') && $lc)
<div class="v5-card span-6">
  <div class="v5-card__h"><div>
    <div class="v5-card__t">Closure rate · {{ $periodLabel ?? '' }}</div>
    <div class="v5-card__sub">{{ $n($lc['closed']) }} closed of {{ $n($lc['workload']) }} in play ({{ $n($lc['backlog_start']) }} open at start + {{ $n($lc['opened']) }} opened)</div>
  </div></div>
  <div class="v5-card__b"><div class="lgd-tiles">
    <div class="lgd-tile {{ ($lc['rate'] ?? 0) >= 50 ? 'good' : 'bad' }}"><div class="l">Closure rate</div><div class="v">{{ $lc['rate'] !== null ? number_format($lc['rate'], 1) . '%' : '—' }}</div><div class="s">of workload cleared</div></div>
    <div class="lgd-tile"><div class="l">Closed vs opened</div><div class="v">{{ $lc['closed_vs_opened'] !== null ? number_format($lc['closed_vs_opened'], 1) . '%' : '—' }}</div><div class="s">{{ $n($lc['closed']) }} / {{ $n($lc['opened']) }}</div></div>
    <div class="lgd-tile"><div class="l">Per working day</div><div class="v">{{ $lc['per_working_day'] !== null ? number_format($lc['per_working_day'], 2) : '—' }}</div><div class="s">{{ $lc['working_days'] }} working days</div></div>
    <div class="lgd-tile"><div class="l">Median days to close</div><div class="v">{{ $lc['median_days_to_close'] !== null ? $n($lc['median_days_to_close']) . ' d' : '—' }}</div><div class="s">files closed in period</div></div>
  </div></div>
  <div class="v5-card__f"><b>Reading:</b> backlog moved {{ $n($lc['backlog_start']) }} → {{ $n($lc['backlog_end']) }} files over the period.</div>
</div>
@endif

@if ($kpiOn('logi_oop_summary') && $lo)
@php
  $gap = (float) ($lo['recharged'] ?? 0) - (float) ($lo['incurred'] ?? 0);
  $gapPct = ($lo['incurred'] ?? 0) > 0 ? 100 * $gap / (float) $lo['incurred'] : null;
@endphp
<div class="v5-card span-6">
  <div class="v5-card__h"><div>
    <div class="v5-card__t">OOP compliance · {{ $periodLabel ?? '' }}</div>
    <div class="v5-card__sub">débours incurred vs recharged to clients, per file</div>
  </div></div>
  <div class="v5-card__b"><div class="lgd-tiles">
    <div class="lgd-tile"><div class="l">Incurred</div><div class="v">{{ $xaf($lo['incurred'] ?? 0) }}</div><div class="s">advances disbursed</div></div>
    <div class="lgd-tile"><div class="l">Recharged</div><div class="v">{{ $xaf($lo['recharged'] ?? 0) }}</div><div class="s">débours billed</div></div>
    <div class="lgd-tile {{ $gap >= 0 ? 'good' : 'bad' }}"><div class="l">Gap</div><div class="v">{{ ($gap >= 0 ? '+' : '−') . $xaf(abs($gap)) }}</div><div class="s">{{ $gapPct !== null ? ($gap >= 0 ? '+' : '−') . number_format(abs($gapPct), 1) . '% of incurred' : '' }}</div></div>
    <div class="lgd-tile"><div class="l">Files</div><div class="v">{{ $n(($lo['over_n'] ?? 0) + ($lo['under_n'] ?? 0) + ($lo['nil_n'] ?? 0)) }}</div><div class="s"><span style="color:#0d6e6e">{{ $n($lo['over_n'] ?? 0) }} over</span> · <span style="color:#9a3b2e">{{ $n($lo['under_n'] ?? 0) }} under</span> · {{ $n($lo['nil_n'] ?? 0) }} nil</div></div>
  </div></div>
  <div class="v5-card__f"><b>Reading:</b> a positive gap means clients were recharged more than was spent on their files; a negative one is cost Elimelec absorbed · <a href="{{ tenant_url('/logistics/oop') }}">open OOP</a></div>
</div>
@endif

@if ($kpiOn('logi_unbilled_debours') && $lu)
@php
  $files   = count(array_unique(array_map(fn ($i) => $i['file'], $lu['items'] ?? [])));
  $clients = count($lu['by_client'] ?? []);
  $top     = $lu['by_client'][0] ?? null;
@endphp
<div class="v5-card span-6">
  <div class="v5-card__h"><div>
    <div class="v5-card__t">Unbilled débours · {{ $periodLabel ?? '' }}</div>
    <div class="v5-card__sub">cash disbursed on files with no matching débours line billed to the client</div>
  </div></div>
  <div class="v5-card__b"><div class="lgd-tiles">
    <div class="lgd-tile bad"><div class="l">Value</div><div class="v">{{ $xaf($lu['total'] ?? 0) }}</div><div class="s">not yet recharged</div></div>
    <div class="lgd-tile"><div class="l">Transactions</div><div class="v">{{ $n($lu['count'] ?? 0) }}</div><div class="s">advance lines</div></div>
    <div class="lgd-tile"><div class="l">Files</div><div class="v">{{ $n($files) }}</div><div class="s">with unbilled débours</div></div>
    <div class="lgd-tile"><div class="l">Clients</div><div class="v">{{ $n($clients) }}</div><div class="s">{{ $top ? 'largest: ' . $top['client'] . ' · ' . $xaf($top['amt']) : '' }}</div></div>
  </div></div>
  <div class="v5-card__f"><b>Reading:</b> every franc here was paid out for a client and is not on any invoice yet — a direct cash-flow exposure · <a href="{{ tenant_url('/billing') }}">open Billing</a> for the per-file detail.</div>
</div>
@endif

@if ($kpiOn('logi_volume_trend') && $lv && !empty($lv['series']))
<div class="v5-card span-6">
  <div class="v5-card__h"><div>
    <div class="v5-card__t">Volumes · {{ $periodLabel ?? '' }}</div>
    <div class="v5-card__sub">{{ number_format($lv['tons'] ?? 0) }} t · {{ number_format($lv['containers'] ?? 0) }} containers · {{ number_format($lv['packages'] ?? 0) }} packages · {{ ($lv['grain'] ?? 'month') === 'month' ? 'per month' : 'per day' }}</div>
  </div></div>
  <div class="v5-card__b" style="overflow:hidden">
    @include('logistics._evolution', ['series' => $lv['series'], 'grain' => $lv['grain'] ?? 'month'])
  </div>
  <div class="v5-card__f"><a href="{{ tenant_url('/logistics/volumes') }}">open Volumes</a> for the shipment mix and importers.</div>
</div>
@endif
