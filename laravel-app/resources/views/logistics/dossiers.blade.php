@extends('layouts.app')

@section('page-title', 'Logistics · Files')

@section('content')
@include('logistics._style')
@php
  $b   = $data['baseline'] ?? null;
  $tdy = $data['today'] ?? null;
  $cl  = $data['closure'] ?? null;
  $bucketColors = ['0-30' => '#1a9641', '31-60' => '#6dcf8a', '61-90' => '#e6e2da', '90+' => '#c8102e'];
  $pct = fn ($v) => $v === null ? '—' : number_format((float) $v, 1) . '%';
@endphp
<style>
  .lg-mix { display:grid; grid-template-columns:86px 1fr; gap:10px 12px; align-items:center; margin-top:8px; }
  .lg-mix .k { font-size:12px; color:#5c6a66; }
  .lg-mix .bar { display:flex; height:30px; border-radius:6px; overflow:hidden; background:#f1efe9; }
  .lg-mix .bar i { display:flex; align-items:center; justify-content:center; height:100%; min-width:0; color:#fff; font-size:12px; font-weight:700; white-space:nowrap; overflow:hidden; font-style:normal; }
  .lg-mix .bar i[data-k="61-90"] { color:#5c6a66; }
  .lg-mix .legend { grid-column:2; display:flex; gap:22px; font-size:11px; color:#7c7466; }
  .lg-mix .legend span::before { content:''; display:inline-block; width:9px; height:9px; border-radius:2px; margin-right:5px; vertical-align:-1px; background:var(--c); }
  .lg-delta { text-align:right; font-weight:700; font-size:12.5px; margin-top:6px; }
  .lg-delta.bad { color:#c8102e; } .lg-delta.good { color:#0d6e6e; }
  .dd tr.lvl1 td:first-child, .dd tr.lvl2 td:first-child { cursor:pointer; }
  .dd tr.lvl2 td:first-child { padding-left:28px; }
  .dd tr.lvl3 td { background:#faf9f6; font-size:12px; }
  .dd tr.lvl3 td:first-child { padding-left:48px; color:#5c6a66; }
</style>

<div class="lg-tiles">
  <div class="lg-tile bad"><div class="l">Open files · as of {{ $tdy['as_of'] ?? '' }}</div><div class="v">{{ number_format($data['open_now']) }}</div><div class="s">backlog at end of {{ $periodLabel }}</div></div>
  <div class="lg-tile"><div class="l">Opened · {{ $periodLabel }}</div><div class="v">{{ number_format($data['opened']) }}</div><div class="s">&nbsp;</div></div>
  <div class="lg-tile bad"><div class="l">Closed · {{ $periodLabel }}</div><div class="v">{{ number_format($data['closed']) }}</div><div class="s">vs {{ number_format($data['opened']) }} opened</div></div>
  <div class="lg-tile bad"><div class="l">Median backlog age</div><div class="v">{{ $data['median_age'] !== null ? number_format($data['median_age']) . ' d' : '—' }}</div><div class="s">of open files</div></div>
</div>

<div class="lg-card">
  <h3>Backlog ageing mix · baseline versus now</h3>
  @if ($b && $tdy)
    <div class="lg-mix">
      <span class="k" title="{{ $data['baseline_label'] }}">Baseline<br><small style="color:#7c7466">{{ $b['as_of'] }}</small></span>
      <div class="bar">
        @foreach ($b['mix'] as $k => $p)
          <i data-k="{{ $k }}" style="width:{{ $p }}%;background:{{ $bucketColors[$k] }}" title="{{ $k }} days · {{ number_format($b['buckets'][$k]) }} files · {{ $pct($p) }}">@if ($k === '90+' && $p >= 12){{ $pct($p) }} 90+@endif</i>
        @endforeach
      </div>
      <span class="k">Today<br><small style="color:#7c7466">{{ $tdy['as_of'] }}</small></span>
      <div class="bar">
        @foreach ($tdy['mix'] as $k => $p)
          <i data-k="{{ $k }}" style="width:{{ $p }}%;background:{{ $bucketColors[$k] }}" title="{{ $k }} days · {{ number_format($tdy['buckets'][$k]) }} files · {{ $pct($p) }}">@if ($k === '90+' && $p >= 12){{ $pct($p) }} 90+@endif</i>
        @endforeach
      </div>
      <div class="legend">
        @foreach ($bucketColors as $k => $c)<span style="--c:{{ $c }}">{{ $k }} days</span>@endforeach
      </div>
    </div>
    @php $delta = round(($tdy['mix']['90+'] ?? 0) - ($b['mix']['90+'] ?? 0), 1); @endphp
    <div class="lg-delta {{ $delta > 0 ? 'bad' : ($delta < 0 ? 'good' : '') }}">
      @if ($delta > 0) Older mix worsened by {{ number_format($delta, 1) }}pp
      @elseif ($delta < 0) Older mix improved by {{ number_format(abs($delta), 1) }}pp
      @else Older mix unchanged @endif
      <span style="font-weight:500;color:#7c7466"> · 90+ share {{ $pct($b['mix']['90+'] ?? 0) }} → {{ $pct($tdy['mix']['90+'] ?? 0) }} · backlog {{ number_format($b['n']) }} → {{ number_format($tdy['n']) }} files</span>
    </div>
    <p style="font-size:11px;color:#7c7466;margin:8px 0 0">Baseline: {{ $data['baseline_label'] }}. Both bars are the files open on that date, aged from their opening date.</p>
  @else
    <p style="color:#7c7466;font-size:12px">No backlog data.</p>
  @endif
</div>

<div class="lg-grid2">
  <div class="lg-card">
    <h3>Closure rate · {{ $periodLabel }}</h3>
    @if ($cl)
      <div class="lg-tiles" style="margin:0">
        <div class="lg-tile {{ ($cl['rate'] ?? 0) >= 50 ? 'good' : 'bad' }}"><div class="l">Closure rate</div><div class="v">{{ $pct($cl['rate']) }}</div><div class="s">{{ number_format($cl['closed']) }} closed of {{ number_format($cl['workload']) }} in play</div></div>
        <div class="lg-tile"><div class="l">Closed vs opened</div><div class="v">{{ $pct($cl['closed_vs_opened']) }}</div><div class="s">{{ number_format($cl['closed']) }} / {{ number_format($cl['opened']) }}</div></div>
        <div class="lg-tile"><div class="l">Closures per working day</div><div class="v">{{ $cl['per_working_day'] !== null ? number_format($cl['per_working_day'], 2) : '—' }}</div><div class="s">{{ $cl['working_days'] }} working days</div></div>
        <div class="lg-tile"><div class="l">Median days to close</div><div class="v">{{ $cl['median_days_to_close'] !== null ? number_format($cl['median_days_to_close']) . ' d' : '—' }}</div><div class="s">files closed in period</div></div>
      </div>
      <p style="font-size:11px;color:#7c7466;margin:10px 0 0">In play = {{ number_format($cl['backlog_start']) }} open at the start + {{ number_format($cl['opened']) }} opened during the period. Backlog moved {{ number_format($cl['backlog_start']) }} → {{ number_format($cl['backlog_end']) }}.</p>
    @endif
  </div>
  <div class="lg-card">
    <h3>Files opened · {{ ($data['grain'] ?? 'month') === 'month' ? 'per month' : 'per day' }}</h3>
    @php $max = max(array_map(fn ($r) => $r['n'], ($data['series'] ?? []) ?: [['n' => 1]])) ?: 1; @endphp
    @forelse (array_filter($data['series'] ?? [], fn ($r) => $r['n'] > 0) as $r)
      <div class="lg-brow"><span class="k">{{ $r['bucket'] }}</span>
        <span class="bar"><i style="width:{{ max(2, (int) (100 * $r['n'] / $max)) }}%"></i></span>
        <span class="n">{{ number_format($r['n']) }} files</span></div>
    @empty
      <p style="color:#7c7466;font-size:12px">No openings in this window.</p>
    @endforelse
  </div>
</div>

<div class="lg-card">
  <h3>Backlog ageing · open files as of {{ $tdy['as_of'] ?? '' }} <span style="float:right;font-weight:500;text-transform:none;letter-spacing:0;color:#7c7466;font-size:11px">click a bucket, then a customer</span></h3>
  <div class="lg-tiles" style="margin:0 0 10px">
    @foreach (($data['cum'] ?? []) as $k => $n)
      <div class="lg-tile bad"><div class="l">{{ $k }} days</div><div class="v">{{ number_format($n) }}</div>
        <div class="s">{{ $data['open_now'] > 0 ? number_format(100 * $n / $data['open_now']) . '% of open' : '' }}</div></div>
    @endforeach
  </div>
  <div class="lg-scroll"><table class="lg-dt dd cell-select" id="lg-backlog">
    <tr><th>Age bucket / customer / file</th><th class="num">Files</th><th class="num">Share</th><th>Type</th><th>Port</th><th>Opened</th><th class="num">Age</th><th>Follow-up</th></tr>
    @foreach (($data['aging'] ?? []) as $k => $n)
      @php $bi = $loop->index; @endphp
      <tr class="lvl1" data-b="{{ $bi }}">
        <td><span class="lg-arrow">▸</span> {{ $k }} days</td>
        <td class="num {{ in_array($k, ['61-90', '90+']) ? 'neg' : '' }}">{{ number_format($n) }}</td>
        <td class="num">{{ $data['open_now'] > 0 ? number_format(100 * $n / $data['open_now'], 1) . '%' : '' }}</td>
        <td colspan="5"></td>
      </tr>
      @foreach (($data['detail'][$k] ?? []) as $client => $files)
        @php $ci = $bi . '-' . $loop->index; @endphp
        <tr class="lvl2" data-b="{{ $bi }}" data-c="{{ $ci }}" hidden>
          <td><span class="lg-arrow">▸</span> {{ $client }}</td>
          <td class="num">{{ count($files) }}</td>
          <td class="num">{{ $n > 0 ? number_format(100 * count($files) / $n, 1) . '%' : '' }}</td>
          <td colspan="5"></td>
        </tr>
        @foreach ($files as $fl)
          <tr class="lvl3" data-c="{{ $ci }}" hidden>
            <td>{{ $fl['code'] }}</td><td></td><td></td>
            <td>{{ $fl['type'] }}</td><td>{{ $fl['port'] }}</td><td>{{ $fl['opened'] }}</td>
            <td class="num {{ $fl['age'] > 90 ? 'neg' : '' }}">{{ number_format($fl['age']) }} d</td>
            <td>{{ $fl['employee'] }}</td>
          </tr>
        @endforeach
      @endforeach
    @endforeach
  </table></div>
</div>

<div class="lg-card">
  <h3>Files opened in period by type × port</h3>
  <div class="lg-scroll"><table class="lg-dt sortable cell-select">
    <tr><th>File type</th><th>Port</th><th class="num">Still open</th><th class="num">Opened</th></tr>
    @forelse (($data['type_port'] ?? []) as $r)
      <tr><td>{{ $r->type }}</td><td>{{ $r->port }}</td>
        <td class="num">{{ number_format($r->open_n) }}</td><td class="num">{{ number_format($r->total) }}</td></tr>
    @empty
      <tr><td colspan="4" style="color:#7c7466">No files opened in this window.</td></tr>
    @endforelse
  </table></div>
</div>

@if ($data['finding'])
  <div class="lg-note"><b>Finding.</b> {{ $data['finding'] }}</div>
@endif

<script>
(function () {
  const t = document.getElementById('lg-backlog');
  if (!t) return;
  t.querySelectorAll('tr.lvl1 td:first-child').forEach(td => td.addEventListener('click', () => {
    const tr = td.parentElement, b = tr.dataset.b, open = tr.classList.toggle('open');
    td.querySelector('.lg-arrow').textContent = open ? '▾' : '▸';
    t.querySelectorAll('tr.lvl2[data-b="' + b + '"]').forEach(r => {
      r.hidden = !open;
      if (!open) { r.classList.remove('open'); r.querySelector('.lg-arrow').textContent = '▸'; }
    });
    if (!open) t.querySelectorAll('tr.lvl3').forEach(r => { if (r.dataset.c.indexOf(b + '-') === 0) r.hidden = true; });
  }));
  t.querySelectorAll('tr.lvl2 td:first-child').forEach(td => td.addEventListener('click', () => {
    const tr = td.parentElement, c = tr.dataset.c, open = tr.classList.toggle('open');
    td.querySelector('.lg-arrow').textContent = open ? '▾' : '▸';
    t.querySelectorAll('tr.lvl3[data-c="' + c + '"]').forEach(r => { r.hidden = !open; });
  }));
})();
</script>
@include('logistics._cellselect')
@endsection
