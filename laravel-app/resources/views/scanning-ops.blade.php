@extends('layouts.app')

@section('page-title', 'Operations')

@section('content')
@include('logistics._style')

@php
  $d = $data;
  $money = fn ($v) => number_format((float) $v);
@endphp

<div class="lg-tiles">
  <div class="lg-tile"><div class="l">Containers · {{ $periodLabel }}</div>
    <div class="v">{{ number_format($d['containers']) }}</div>
    <div class="s">billed container units</div></div>
  <div class="lg-tile good"><div class="l">Scanning revenue</div>
    <div class="v">{{ $money($d['revenue']) }}</div><div class="s">XAF, untaxed</div></div>
  <div class="lg-tile"><div class="l">Revenue per container</div>
    <div class="v">{{ $d['per_container'] !== null ? $money($d['per_container']) : '—' }}</div>
    <div class="s">XAF average yield</div></div>
  <div class="lg-tile"><div class="l">Billed lines</div>
    <div class="v">{{ number_format($d['lines']) }}</div><div class="s">container invoice lines</div></div>
</div>

<div class="lg-grid2">
  <div class="lg-card">
    <h3>Throughput per month <span style="float:right;font-weight:500;text-transform:none;letter-spacing:0;color:#7c7466;font-size:11px">containers · revenue</span></h3>
    @php $vmax = collect($d['by_month'])->max(fn ($r) => (float) $r->qty) ?: 1; @endphp
    @forelse ($d['by_month'] as $r)
      <div class="lg-brow"><span class="k">{{ $r->m }}</span>
        <span class="bar"><i style="width:{{ max(2, (int) (100 * (float) $r->qty / $vmax)) }}%"></i></span>
        <span class="n">{{ number_format((float) $r->qty) }} ctn · {{ $money($r->rev) }}</span></div>
    @empty
      <p style="color:#7c7466;font-size:12px">No container activity in this period.</p>
    @endforelse
  </div>

  <div class="lg-card">
    <h3>Voyage type <span style="float:right;font-weight:500;text-transform:none;letter-spacing:0;color:#7c7466;font-size:11px">Type_voyage</span></h3>
    @php $tmax = collect($d['by_voyage'])->max(fn ($r) => (float) $r->qty) ?: 1; @endphp
    @forelse ($d['by_voyage'] as $r)
      <div class="lg-brow"><span class="k">{{ $r->k }}</span>
        <span class="bar"><i style="width:{{ max(2, (int) (100 * (float) $r->qty / $tmax)) }}%"></i></span>
        <span class="n">{{ number_format((float) $r->qty) }} ctn · {{ $money($r->rev) }}</span></div>
    @empty
      <p style="color:#7c7466;font-size:12px">No voyage data in this period.</p>
    @endforelse
  </div>
</div>

<div class="lg-card">
  <h3>Container mix — size &amp; direction <span style="float:right;font-weight:500;text-transform:none;letter-spacing:0;color:#7c7466;font-size:11px">click a header to sort</span></h3>
  <div class="lg-scroll"><table class="lg-dt sortable cell-select">
    <tr><th>Category</th><th class="num">Containers</th><th class="num">Revenue (XAF)</th>
        <th class="num">Per container</th><th class="num">Lines</th></tr>
    @forelse ($d['by_category'] as $r)
      <tr><td>{{ $r->k }}</td>
        <td class="num">{{ number_format((float) $r->qty) }}</td>
        <td class="num">{{ $money($r->rev) }}</td>
        <td class="num">{{ (float) $r->qty > 0 ? $money((float) $r->rev / (float) $r->qty) : '—' }}</td>
        <td class="num">{{ number_format($r->n) }}</td></tr>
    @empty
      <tr><td colspan="5" style="color:#7c7466">No container categories in this period.</td></tr>
    @endforelse
  </table></div>
</div>

<div class="lg-card">
  <h3>Top customers by scanning revenue</h3>
  <div class="lg-scroll"><table class="lg-dt sortable cell-select">
    <tr><th>Customer</th><th class="num">Containers</th><th class="num">Revenue (XAF)</th><th class="num">Per container</th></tr>
    @forelse ($d['top_customers'] as $r)
      <tr><td>{{ $r->k }}</td>
        <td class="num">{{ number_format((float) $r->qty) }}</td>
        <td class="num">{{ $money($r->rev) }}</td>
        <td class="num">{{ (float) $r->qty > 0 ? $money((float) $r->rev / (float) $r->qty) : '—' }}</td></tr>
    @empty
      <tr><td colspan="4" style="color:#7c7466">No customer activity in this period.</td></tr>
    @endforelse
  </table></div>
</div>

@foreach ($d['notes'] as $n)
  <div class="lg-note">{{ $n }}</div>
@endforeach

<div class="lg-note ok"><b>How this is built.</b> This instance does not carry the vessel/equipment models
the original scanning page reads, so throughput is reconstructed from billed container lines: quantity
gives containers, the product category gives size and direction, and <span class="mono">Type_voyage</span>
on the invoice gives domestique vs transit. All figures are warehouse-backed and follow the master filter.</div>

@include('logistics._interactive')
@include('logistics._cellselect')
@endsection
