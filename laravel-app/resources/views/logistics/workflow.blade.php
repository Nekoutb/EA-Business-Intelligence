@extends('layouts.app')

@section('page-title', 'Logistics · Workflow')

@section('content')
@include('logistics._style')
@php
  $sg   = $stages ?? null;
  $keys = array_keys(\App\Services\LogisticsOpsService::STAGES);
  $short = ['open_to_request' => 'Open → débours req.', 'request_to_cash' => 'Request → cash', 'cash_to_billed' => 'Cash → billed',
            'open_to_billstart' => 'Open → billing start', 'open_to_invoice' => 'Open → invoice', 'invoice_to_deposit' => 'Invoice → deposit',
            'invoice_to_due' => 'Invoice → due', 'open_to_close' => 'Open → close'];
  $d1 = fn ($v) => $v === null ? '—' : number_format((float) $v, 1) . ' d';
  $heat = function ($v, $ref) { if ($v === null || !$ref) return ''; $r = $v / $ref; return $r >= 1.5 ? 'background:#f8e9e5;color:#9a3b2e;font-weight:600' : ($r >= 1.15 ? 'background:#fbf3e0' : ($r <= 0.7 ? 'background:#e6f3ef' : '')); };
  $fc = $sg['filter']['customer'] ?? null; $ff = $sg['filter']['file'] ?? null;
  $filtered = $fc || $ff;
@endphp
<style>
  .lg-journey { display:flex; height:34px; border-radius:6px; overflow:hidden; background:#f1efe9; margin:8px 0 4px; }
  .lg-journey i { display:flex; align-items:center; justify-content:center; font-style:normal; font-size:11px; font-weight:700; color:#fff; white-space:nowrap; overflow:hidden; min-width:0; }
  .lg-jlegend { display:flex; flex-wrap:wrap; gap:6px 16px; font-size:11px; color:#7c7466; }
  .lg-jlegend span::before { content:''; display:inline-block; width:9px; height:9px; border-radius:2px; margin-right:5px; vertical-align:-1px; background:var(--c); }
  .lg-filter { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin:0 0 12px; }
  .lg-filter select, .lg-filter input { font:inherit; font-size:12.5px; padding:6px 9px; border:1px solid #e8e4de; border-radius:8px; background:#fff; min-width:200px; }
  .lg-filter button, .lg-filter a.clear { font:600 11px/1 inherit; letter-spacing:.06em; text-transform:uppercase; padding:8px 12px; border-radius:999px; border:1px solid #1e3d44; background:#1e3d44; color:#fff; cursor:pointer; text-decoration:none; }
  .lg-filter a.clear { background:#fff; color:#7c7466; border-color:#e8e4de; }
  .lg-stage-tiles { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; }
  @media (max-width:1100px) { .lg-stage-tiles { grid-template-columns:repeat(2,1fr); } }
  .lg-stage-tiles .lg-tile { margin:0; }
  .lg-stage-tiles .lg-tile .f { font:400 10.5px/1.3 ui-monospace, Consolas, monospace; color:#7c7466; margin-top:4px; word-break:break-word; }
  .lg-stage-tiles .lg-tile.pending .v { color:#7c7466; }
  table.lg-dt td.worst { box-shadow: inset 3px 0 0 #9a3b2e; }
</style>

@php
  $stageColors = ['open_to_request' => '#0d6e6e', 'request_to_cash' => '#3d8a8a', 'cash_to_billed' => '#b7791f', 'open_to_billstart' => '#8a6d3b',
                  'open_to_invoice' => '#3d5a80', 'invoice_to_deposit' => '#6b8cae', 'invoice_to_due' => '#98a9bd', 'open_to_close' => '#1e3d44'];
@endphp

<div class="lg-card">
  <h3>Stage latency · files opened {{ $periodLabel }}
    <span style="float:right;font-weight:500;text-transform:none;letter-spacing:0;color:#7c7466;font-size:11px">{{ number_format($sg['files_total'] ?? 0) }} files{{ $filtered ? ' · filtered' : '' }}</span></h3>

  <form method="get" class="lg-filter" id="lg-stage-filter">
    <input type="hidden" name="period" value="{{ $period['type'] ?? 'ytd' }}"><input type="hidden" name="year" value="{{ $period['year'] ?? '' }}"><input type="hidden" name="month" value="{{ $period['month'] ?? '' }}">
    <select name="lg_customer">
      <option value="">All customers</option>
      @foreach (($sg['customer_list'] ?? []) as $c)<option value="{{ $c }}" {{ $fc === $c ? 'selected' : '' }}>{{ $c }}</option>@endforeach
    </select>
    <input type="text" name="lg_file" value="{{ $ff }}" placeholder="File reference (e.g. 2026IM0425)">
    <button type="submit">Apply</button>
    @if ($filtered)<a class="clear" href="?period={{ $period['type'] ?? 'ytd' }}&year={{ $period['year'] ?? '' }}&month={{ $period['month'] ?? '' }}">Clear</a>@endif
  </form>

  @if ($sg && ($sg['files_total'] ?? 0) > 0)
    @php
      $seq = ['open_to_request', 'request_to_cash', 'cash_to_billed', 'open_to_invoice', 'invoice_to_due'];
      $segs = array_filter($seq, fn ($k) => ($sg['stages'][$k]['avg'] ?? null) !== null && $sg['stages'][$k]['avg'] > 0);
      $sum = array_sum(array_map(fn ($k) => $sg['stages'][$k]['avg'], $segs)) ?: 1;
    @endphp
    <div class="lg-journey">
      @foreach ($segs as $k)
        @php $w = 100 * $sg['stages'][$k]['avg'] / $sum; @endphp
        <i style="width:{{ $w }}%;background:{{ $stageColors[$k] }}" title="{{ $short[$k] }} · {{ $d1($sg['stages'][$k]['avg']) }} average">@if ($w >= 9){{ $d1($sg['stages'][$k]['avg']) }}@endif</i>
      @endforeach
    </div>
    <div class="lg-jlegend">@foreach ($segs as $k)<span style="--c:{{ $stageColors[$k] }}">{{ $short[$k] }}</span>@endforeach<span style="--c:#f1efe9">width = share of the average journey</span></div>

    <div class="lg-stage-tiles" style="margin-top:14px">
      @foreach ($sg['stages'] as $k => $st)
        @php $pending = in_array($k, $sg['pending'] ?? [], true); @endphp
        <div class="lg-tile {{ $pending ? 'pending' : ($st['avg'] !== null && $st['avg'] > 60 ? 'bad' : '') }}">
          <div class="l">{{ $short[$k] }}</div>
          <div class="v">{{ $pending ? 'awaiting sync' : $d1($st['avg']) }}</div>
          <div class="s">{{ $pending ? 'dates arrive with the next ERP sync' : ($st['n'] > 0 ? 'median ' . $d1($st['median']) . ' · ' . number_format($st['n']) . ' of ' . number_format($sg['files_total']) . ' files' : 'no file has both dates') }}</div>
          <div class="f">{{ $st['fields'] }}</div>
        </div>
      @endforeach
    </div>
    <p style="font-size:11px;color:#7c7466;margin:10px 0 0">Average days a file spends between the two dates of each step. Only files that have both dates count towards a step's average — the "n of files" tells you how representative it is. Open → close is near-empty because files are rarely marked closed.</p>
  @else
    <p style="color:#7c7466;font-size:12px">No files opened in this window{{ $filtered ? ' for this filter' : '' }}.</p>
  @endif
</div>

@if ($sg && ($sg['files_total'] ?? 0) > 0)
<div class="lg-card">
  <h3>Where each customer is impacted <span style="float:right;font-weight:500;text-transform:none;letter-spacing:0;color:#7c7466;font-size:11px">average days per stage · red = 1.5× the overall average, amber = 1.15×, green = under 0.7× · click a customer to list its files</span></h3>
  <div class="lg-scroll"><table class="lg-dt sortable cell-select" id="lg-cust-stages">
    <tr><th>Customer</th><th class="num">Files</th>@foreach ($keys as $k)<th class="num">{{ $short[$k] }}</th>@endforeach<th class="num">Avg journey</th><th>Worst stage</th></tr>
    @foreach ($sg['customers'] as $c)
      <tr class="lg-cust-row" data-client="{{ $c['client'] }}">
        <td style="cursor:pointer"><span class="lg-arrow">▸</span> {{ $c['client'] }}</td>
        <td class="num">{{ number_format($c['n']) }}</td>
        @foreach ($keys as $k)
          <td class="num {{ $c['worst'] === $k ? 'worst' : '' }}" style="{{ $heat($c['stages'][$k], $sg['stages'][$k]['avg']) }}">{{ $d1($c['stages'][$k]) }}</td>
        @endforeach
        <td class="num" style="font-weight:700">{{ $d1($c['journey']) }}</td>
        <td>{{ $c['worst'] ? $short[$c['worst']] . ' · ' . number_format($c['worst_ratio'], 1) . '×' : '—' }}</td>
      </tr>
    @endforeach
  </table></div>
</div>

<div class="lg-card" id="lg-files-card" {{ $filtered ? '' : 'hidden' }}>
  <h3>Files <span id="lg-files-scope" style="font-weight:500;text-transform:none;letter-spacing:0;color:#7c7466;font-size:11px">{{ $filtered ? '· ' . ($fc ?: '') . ($ff ? ' · ' . $ff : '') : '' }}</span></h3>
  <div class="lg-scroll"><table class="lg-dt sortable cell-select" id="lg-file-stages">
    <tr><th>File</th><th>Customer</th><th>Opened</th>@foreach ($keys as $k)<th class="num">{{ $short[$k] }}</th>@endforeach<th class="num">Journey</th></tr>
    @foreach ($sg['files'] as $r)
      <tr data-client="{{ $r['client'] }}">
        <td>{{ $r['code'] }}</td><td>{{ $r['client'] }}</td><td>{{ $r['opened'] }}</td>
        @foreach ($keys as $k)
          <td class="num" style="{{ $heat($r['stages'][$k], $sg['stages'][$k]['avg']) }}">{{ $d1($r['stages'][$k]) }}</td>
        @endforeach
        <td class="num" style="font-weight:700">{{ $d1($r['journey']) }}</td>
      </tr>
    @endforeach
  </table></div>
</div>
@endif

<div class="lg-tiles">
  <div class="lg-tile"><div class="l">Documents through circuits</div><div class="v">{{ number_format($data['docs']) }}</div><div class="s">{{ $periodLabel }}</div></div>
  <div class="lg-tile bad"><div class="l">Pending validations</div><div class="v">{{ number_format($data['pending']) }}</div><div class="s">documents at an uncleared step</div></div>
</div>

<div class="lg-grid2">
  <div class="lg-card">
    <h3>Approval circuits by document type</h3>
    <div class="lg-scroll"><table class="lg-dt">
      <tr><th>Document type</th><th>Docs</th><th>Avg clearance</th><th>Slowest</th></tr>
      @forelse ($data['circuits'] as $r)
        <tr><td>{{ $r->doc }}</td><td class="num">{{ number_format($r->docs) }}</td>
          <td class="num">{{ number_format(24 * (float) $r->avg_days, 1) }} h</td>
          <td class="num">{{ number_format((float) $r->max_days, 1) }} d</td></tr>
      @empty
        <tr><td colspan="4" style="color:#7c7466">No circuit activity in this window.</td></tr>
      @endforelse
    </table></div>
  </div>
  <div class="lg-card">
    <h3>Average lag per approval step</h3>
    @php $smax = collect($data['steps'])->max(fn ($r) => (float) $r->lag) ?: 1; @endphp
    @forelse ($data['steps'] as $r)
      <div class="lg-brow"><span class="k">{{ $r->circuit }} · step {{ $r->rang }}</span>
        <span class="bar"><i style="width:{{ max(2, (int) (100 * (float) $r->lag / $smax)) }}%"></i></span>
        <span class="n">{{ number_format(24 * (float) $r->lag, 1) }} h · {{ number_format($r->n) }}</span></div>
    @empty
      <p style="color:#7c7466;font-size:12px">No step activity in this window.</p>
    @endforelse
  </div>
</div>

@if ($data['note'])
  <div class="lg-note ok"><b>Scope note.</b> {{ $data['note'] }}</div>
@endif

<script>
(function () {
  // Clicking a customer reveals its files (already in the page) without a reload.
  const card = document.getElementById('lg-files-card'), scope = document.getElementById('lg-files-scope');
  const rows = Array.from(document.querySelectorAll('#lg-file-stages tr[data-client]'));
  document.querySelectorAll('#lg-cust-stages tr.lg-cust-row td:first-child').forEach(td => td.addEventListener('click', () => {
    const tr = td.parentElement, client = tr.dataset.client, on = !tr.classList.contains('open');
    document.querySelectorAll('#lg-cust-stages tr.lg-cust-row').forEach(r => { r.classList.remove('open'); r.querySelector('.lg-arrow').textContent = '▸'; });
    if (!on) { card.hidden = true; return; }
    tr.classList.add('open'); td.querySelector('.lg-arrow').textContent = '▾';
    rows.forEach(r => { r.hidden = r.dataset.client !== client; });
    card.hidden = false; scope.textContent = '· ' + client;
    card.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }));
})();
</script>
@include('logistics._interactive')
@include('logistics._cellselect')
@endsection
