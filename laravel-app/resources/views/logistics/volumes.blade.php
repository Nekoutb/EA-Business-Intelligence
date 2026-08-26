@extends('layouts.app')

@section('page-title', 'Logistics · Volumes')

@section('content')
@include('logistics._style')
<x-period-filter :period="$period" />

<div class="lg-tiles">
  <div class="lg-tile"><div class="l">Tonnage · {{ $periodLabel }}</div><div class="v">{{ number_format($data['tons']) }} t</div><div class="s">net</div></div>
  <div class="lg-tile"><div class="l">Containers</div><div class="v">{{ number_format($data['containers']) }}</div><div class="s">declared on files</div></div>
  <div class="lg-tile"><div class="l">Packages</div><div class="v">{{ number_format($data['packages']) }}</div><div class="s">colis</div></div>
  <div class="lg-tile"><div class="l">Cargo value</div><div class="v">{{ ea_money_short($data['cargo_value'], 'XAF') }}</div><div class="s">declared, where captured</div></div>
</div>

<div class="lg-grid2">
  <div class="lg-card">
    <h3>Tonnage per month</h3>
    @php $vmax = collect($data['monthly'])->max(fn ($r) => $r['tons']) ?: 1; @endphp
    @forelse ($data['monthly'] as $m => $r)
      <div class="lg-brow"><span class="k">{{ $m }}</span>
        <span class="bar"><i style="width:{{ max(2, (int) (100 * $r['tons'] / $vmax)) }}%"></i></span>
        <span class="n">{{ number_format($r['tons']) }} t · {{ number_format($r['containers']) }} ctn</span></div>
    @empty
      <p style="color:#7c7466;font-size:12px">No volume in this window.</p>
    @endforelse
  </div>
  <div class="lg-card">
    <h3>Shipment mode</h3>
    @php $emax = collect($data['embarquement'])->max(fn ($r) => (float) $r->t) ?: 1; @endphp
    @foreach ($data['embarquement'] as $r)
      <div class="lg-brow"><span class="k">{{ $r->k }}</span>
        <span class="bar"><i style="width:{{ max(2, (int) (100 * (float) $r->t / $emax)) }}%"></i></span>
        <span class="n">{{ number_format((float) $r->t) }} t</span></div>
    @endforeach
    <h3 style="margin-top:16px">Named importers</h3>
    <div class="lg-scroll"><table class="lg-dt">
      <tr><th>Importer</th><th>Files</th><th>Tonnes</th></tr>
      @forelse ($data['importers'] as $r)
        <tr><td>{{ $r->k }}</td><td class="num">{{ number_format($r->n) }}</td><td class="num">{{ number_format((float) $r->t) }}</td></tr>
      @empty
        <tr><td colspan="3" style="color:#7c7466">No named importer in this window.</td></tr>
      @endforelse
    </table></div>
  </div>
</div>

@if ($data['capture_note'])
  <div class="lg-note"><b>Capture gap.</b> {{ $data['capture_note'] }}</div>
@endif
@endsection
