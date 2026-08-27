@extends('layouts.app')

@section('page-title', 'OOP Compliance')

@section('content')
@include('logistics._style')
<style>
  .oop-gain { color:#0d6e6e; font-weight:600; } .oop-loss { color:#9a3b2e; font-weight:600; }
  input.oop-search { width:100%; max-width:320px; border:1px solid #e8e4de; padding:8px 12px;
    border-radius:9px; font-size:13px; margin-bottom:10px; }
</style>

@php $f = $data['files'] ?? []; @endphp

<div class="lg-tiles">
  <div class="lg-tile"><div class="l">Débours incurred</div><div class="v">{{ number_format($data['incurred'] ?? 0) }}</div><div class="s">XAF — cash advances on files</div></div>
  <div class="lg-tile"><div class="l">Débours recharged</div><div class="v">{{ number_format($data['recharged'] ?? 0) }}</div><div class="s">XAF — billed to clients</div></div>
  <div class="lg-tile good"><div class="l">Net gap</div>
    <div class="v">{{ ($data['recharged'] ?? 0) - ($data['incurred'] ?? 0) >= 0 ? '+' : '' }}{{ number_format(($data['recharged'] ?? 0) - ($data['incurred'] ?? 0)) }}</div>
    <div class="s">recharged − incurred, all time</div></div>
  <div class="lg-tile good"><div class="l">Over-recharged files</div><div class="v">{{ number_format($data['over_n'] ?? 0) }}</div><div class="s">recharge &gt; incurred</div></div>
  <div class="lg-tile bad"><div class="l">Under-recharged files</div><div class="v">{{ number_format($data['under_n'] ?? 0) }}</div><div class="s">recharge &lt; incurred</div></div>
  <div class="lg-tile"><div class="l">Matched (nil gap)</div><div class="v">{{ number_format($data['nil_n'] ?? 0) }}</div><div class="s">the compliance principle</div></div>
</div>

<div class="lg-card">
  <h3>Out of Pocket Expense Compliance — by file</h3>
  <input class="oop-search" id="oopq" type="search" placeholder="Filter by file code or customer…">
  <div class="lg-scroll"><table class="lg-dt" id="oopTable">
    <tr><th>File</th><th>Customer</th><th>Débours incurred (XAF)</th><th>Débours recharged (XAF)</th>
        <th>Gap (XAF)</th><th>Gap %</th></tr>
    @foreach ($f as $r)
      @php $over = $r['gap'] >= 0; @endphp
      <tr data-q="{{ mb_strtolower($r['code'] . ' ' . $r['client']) }}">
        <td>{{ $r['code'] }}</td><td>{{ $r['client'] }}</td>
        <td class="num">{{ number_format($r['incurred']) }}</td>
        <td class="num">{{ number_format($r['recharged']) }}</td>
        <td class="num {{ $over ? 'oop-gain' : 'oop-loss' }}">{{ $over ? '+' : '' }}{{ number_format($r['gap']) }}</td>
        <td class="num {{ $over ? 'oop-gain' : 'oop-loss' }}">
          {{ $r['gap_pct'] !== null ? ($over ? '+' : '') . number_format($r['gap_pct'], 1) . '%' : ($over ? '+∞' : '—') }}
        </td>
      </tr>
    @endforeach
  </table></div>
</div>

<div class="lg-note"><b>Reading.</b> Green % = Elimelec recharged the client <em>more</em> than the cash
actually paid out — a gain on débours. Red % = recharged <em>less</em> than incurred — a loss absorbed
by Elimelec. The principle is a nil gap: every out-of-pocket expense recovered exactly. "Incurred" counts
cash advances only — débours a bank paid directly are invisible until the provider exposes supplier
payments per file. Figures are all-time (files outlive any single period).</div>

<script>
document.getElementById('oopq').addEventListener('input', function (e) {
  var q = e.target.value.trim().toLowerCase();
  document.querySelectorAll('#oopTable tr[data-q]').forEach(function (tr) {
    tr.style.display = (!q || tr.dataset.q.indexOf(q) !== -1) ? '' : 'none';
  });
});
</script>
@endsection
