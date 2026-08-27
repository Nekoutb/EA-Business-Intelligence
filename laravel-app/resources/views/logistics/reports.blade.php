@extends('layouts.app')

@section('page-title', 'Reports · Logistics')

@section('content')
@include('logistics._style')

@php
  $c = $data['customers'] ?? [];
  $byInv = $data['invoices_by_client'] ?? [];
@endphp

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
  <h3>Revenue per customer · {{ $periodLabel }} <span style="float:right;font-weight:500;text-transform:none;letter-spacing:0;color:#7c7466;font-size:11px">click ▸ to expand · click a header to sort</span></h3>
  @php $rmax = collect($c)->max('revenue') ?: 1; @endphp
  <div class="lg-scroll"><table class="lg-dt sortable" id="rlTable">
    <tr><th></th><th>Customer</th><th></th><th class="num">Invoices</th><th class="num">Fee revenue (XAF)</th>
        <th class="num">% of total</th><th class="num">Cumulative %</th></tr>
    @forelse ($c as $i => $r)
      <tr class="lg-roll-row" data-target="rl-{{ $i }}" style="cursor:pointer">
        <td><span class="lg-arrow">▸</span></td>
        <td>{{ $r['client'] }}</td>
        <td><span class="bar" style="display:block"><i style="width:{{ max(2, (int) (100 * $r['revenue'] / $rmax)) }}%"></i></span></td>
        <td class="num">{{ number_format($r['invoices']) }}</td>
        <td class="num">{{ number_format($r['revenue']) }}</td>
        <td class="num">{{ number_format($r['share'], 1) }}%</td>
        <td class="num" style="color:#7c7466">{{ number_format(min($r['cum'], 100), 1) }}%</td>
      </tr>
      <tr id="rl-{{ $i }}" class="lg-roll-detail" style="display:none">
        <td colspan="7" style="padding:0 10px 12px 34px;background:#faf8f4">
          <table class="lg-dt sortable" style="font-size:11.5px">
            <tr><th>Invoice</th><th>Date</th><th class="num">Fee (XAF)</th><th class="num">TTC (XAF)</th><th class="num">Outstanding (XAF)</th></tr>
            @foreach (($byInv[$r['client']] ?? []) as $inv)
              <tr><td>{{ $inv['inv'] }}</td><td>{{ $inv['d'] }}</td>
                <td class="num">{{ number_format($inv['fee']) }}</td>
                <td class="num">{{ number_format($inv['ttc']) }}</td>
                <td class="num {{ $inv['outstanding'] > 0.5 ? 'neg' : '' }}">{{ number_format($inv['outstanding']) }}</td></tr>
            @endforeach
          </table>
        </td>
      </tr>
    @empty
      <tr><td colspan="7" style="color:#7c7466">No billing in this period.</td></tr>
    @endforelse
  </table></div>
  <table class="lg-dt" style="margin-top:2px">
    <tr style="font-weight:600">
      <td style="width:1%"></td><td>Total</td><td style="width:22%"></td>
      <td class="num">{{ number_format($data['invoices'] ?? 0) }}</td>
      <td class="num">{{ number_format($data['total'] ?? 0) }}</td>
      <td class="num">100.0%</td><td></td>
    </tr>
  </table>
</div>

<div class="lg-note ok"><b>Definition.</b> Revenue here is the fee commission billed by Elimelec —
the fee portion of each invoice's lines. Débours (out-of-pocket costs rebilled to clients) are
excluded. Figures follow the master period filter and match the Dashboard's revenue.</div>

@include('logistics._interactive')
@endsection
