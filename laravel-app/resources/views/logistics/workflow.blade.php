@extends('layouts.app')

@section('page-title', 'Logistics · Workflow')

@section('content')
@include('logistics._style')
<x-period-filter :period="$period" />

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
@endsection
