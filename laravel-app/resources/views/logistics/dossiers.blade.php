@extends('layouts.app')

@section('page-title', 'Logistics · Dossiers')

@section('content')
@include('logistics._style')
<x-period-filter :period="$period" />

<div class="lg-tiles">
  <div class="lg-tile bad"><div class="l">Open files today</div><div class="v">{{ number_format($data['open_now']) }}</div><div class="s">all vintages</div></div>
  <div class="lg-tile"><div class="l">Opened · {{ $periodLabel }}</div><div class="v">{{ number_format($data['opened']) }}</div><div class="s">&nbsp;</div></div>
  <div class="lg-tile bad"><div class="l">Closed · {{ $periodLabel }}</div><div class="v">{{ number_format($data['closed']) }}</div><div class="s">vs {{ number_format($data['opened']) }} opened</div></div>
  <div class="lg-tile bad"><div class="l">Median backlog age</div><div class="v">{{ $data['median_age'] !== null ? number_format($data['median_age']) . ' d' : '—' }}</div><div class="s">of open files</div></div>
</div>

<div class="lg-grid2">
  <div class="lg-card">
    <h3>Files opened per month</h3>
    @php $max = max(array_values($data['monthly'] ?: [1])); @endphp
    @forelse ($data['monthly'] as $m => $n)
      <div class="lg-brow"><span class="k">{{ $m }}</span>
        <span class="bar"><i style="width:{{ max(2, (int) (100 * $n / max($max, 1))) }}%"></i></span>
        <span class="n">{{ number_format($n) }} files</span></div>
    @empty
      <p style="color:#7c7466;font-size:12px">No openings in this window.</p>
    @endforelse
  </div>
  <div class="lg-card">
    <h3>Backlog aging — open files</h3>
    @php $amax = max(array_values($data['aging'] ?: [1])); @endphp
    @foreach ($data['aging'] as $k => $n)
      <div class="lg-brow {{ in_array($k, ['61-90', '90+']) ? 'warn' : '' }}"><span class="k">{{ $k }} d</span>
        <span class="bar"><i style="width:{{ max(2, (int) (100 * $n / max($amax, 1))) }}%"></i></span>
        <span class="n">{{ number_format($n) }} files</span></div>
    @endforeach
    <div class="lg-tiles" style="margin-top:12px">
      @foreach ($data['cum'] as $k => $n)
        <div class="lg-tile bad"><div class="l">{{ $k }} days</div><div class="v">{{ number_format($n) }}</div>
          <div class="s">{{ $data['open_now'] > 0 ? number_format(100 * $n / $data['open_now']) . '% of open' : '' }}</div></div>
      @endforeach
    </div>
  </div>
</div>

<div class="lg-card">
  <h3>Files by type × port</h3>
  <div class="lg-scroll"><table class="lg-dt">
    <tr><th>File type</th><th>Port</th><th>Open</th><th>Total</th></tr>
    @foreach ($data['type_port'] as $r)
      <tr><td>{{ $r->type }}</td><td>{{ $r->port }}</td>
        <td class="num">{{ number_format($r->open_n) }}</td><td class="num">{{ number_format($r->total) }}</td></tr>
    @endforeach
  </table></div>
</div>

@if ($data['finding'])
  <div class="lg-note"><b>Finding.</b> {{ $data['finding'] }}</div>
@endif
@endsection
