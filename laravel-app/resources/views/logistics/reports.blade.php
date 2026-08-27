@extends('layouts.app')

@section('page-title', 'Reports · Logistics')

@section('content')
@include('logistics._style')

@php $c = $data['customers'] ?? []; @endphp

<div class="lg-tiles">
  <div class="lg-tile good"><div class="l">Fee revenue · {{ $periodLabel }}</div>
    <div class="v">{{ number_format($data['total'] ?? 0) }}</div>
    <div class="s">XAF — fees billed by Elimelec; débours excluded</div></div>
  <div class="lg-tile"><div class="l">Active customers</div>
    <div class="v">{{ number_format(count($c)) }}</div>
    <div class="s">{{ number_format($data['invoices'] ?? 0) }} invoices in the period</div></div>
  <div class="lg-tile {{ ($c[0]['share'] ?? 0) > 40 ? 'bad' : '' }}"><div class="l">Top customer share</div>
    <div class="v">{{ number_format($c[0]['share'] ?? 0, 1) }}%</div>
    <div class="s">{{ $c[0]['client'] ?? '—' }}</div></div>
</div>

<div class="lg-card">
  <h3>Revenue per customer · {{ $periodLabel }}</h3>
  @php $rmax = collect($c)->max('revenue') ?: 1; @endphp
  <div class="lg-scroll"><table class="lg-dt">
    <tr><th>Customer</th><th style="width:30%"></th><th>Invoices</th><th>Fee revenue (XAF)</th>
        <th>% of total</th><th>Cumulative %</th></tr>
    @forelse ($c as $r)
      <tr>
        <td>{{ $r['client'] }}</td>
        <td><span class="lg-brow" style="grid-template-columns:1fr;padding:0"><span class="bar"><i
            style="width:{{ max(2, (int) (100 * $r['revenue'] / $rmax)) }}%"></i></span></span></td>
        <td class="num">{{ number_format($r['invoices']) }}</td>
        <td class="num">{{ number_format($r['revenue']) }}</td>
        <td class="num">{{ number_format($r['share'], 1) }}%</td>
        <td class="num" style="color:#7c7466">{{ number_format(min($r['cum'], 100), 1) }}%</td>
      </tr>
    @empty
      <tr><td colspan="6" style="color:#7c7466">No billing in this period.</td></tr>
    @endforelse
  </table></div>
</div>

<div class="lg-note ok"><b>Definition.</b> Revenue here is the fee commission billed by Elimelec —
the fee portion of each invoice's lines. Débours (out-of-pocket costs rebilled to clients) are
excluded. Figures follow the master period filter and match the Dashboard's revenue.</div>
@endsection
