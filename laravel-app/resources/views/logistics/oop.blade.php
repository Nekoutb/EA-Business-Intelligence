@extends('layouts.app')

@section('page-title', 'OOP Compliance')

@section('content')
@include('logistics._style')
<style>
  .oop-gain { color:#0d6e6e; font-weight:600; } .oop-loss { color:#9a3b2e; font-weight:600; }
  input.oop-search { width:100%; max-width:320px; border:1px solid #e8e4de; padding:8px 12px;
    border-radius:9px; font-size:13px; margin-bottom:10px; }
</style>

@php $f = $data['files'] ?? []; $det = $data['detail'] ?? []; @endphp

<div class="lg-tiles">
  <div class="lg-tile"><div class="l">Débours incurred · {{ $periodLabel }}</div><div class="v">{{ number_format($data['incurred'] ?? 0) }}</div><div class="s">XAF — cash advances disbursed in the period</div></div>
  <div class="lg-tile"><div class="l">Débours recharged · {{ $periodLabel }}</div><div class="v">{{ number_format($data['recharged'] ?? 0) }}</div><div class="s">XAF — billed to clients in the period</div></div>
  <div class="lg-tile good"><div class="l">Net gap</div>
    <div class="v">{{ ($data['recharged'] ?? 0) - ($data['incurred'] ?? 0) >= 0 ? '+' : '' }}{{ number_format(($data['recharged'] ?? 0) - ($data['incurred'] ?? 0)) }}</div>
    <div class="s">recharged − incurred</div></div>
  <div class="lg-tile good"><div class="l">Over-recharged files</div><div class="v">{{ number_format($data['over_n'] ?? 0) }}</div><div class="s">recharge &gt; incurred</div></div>
  <div class="lg-tile bad"><div class="l">Under-recharged files</div><div class="v">{{ number_format($data['under_n'] ?? 0) }}</div><div class="s">recharge &lt; incurred</div></div>
  <div class="lg-tile"><div class="l">Matched (nil gap)</div><div class="v">{{ number_format($data['nil_n'] ?? 0) }}</div><div class="s">the compliance principle</div></div>
</div>

<div class="lg-card">
  <h3>Out of Pocket Expense Compliance · {{ $periodLabel }}
    <span style="float:right;font-weight:500;text-transform:none;letter-spacing:0;color:#7c7466;font-size:11px">click ▸ to expand · click a header to sort</span></h3>
  <input class="oop-search" id="oopq" type="search" placeholder="Filter by file code or customer…">
  <div class="lg-scroll"><table class="lg-dt sortable" id="oopTable">
    <tr><th></th><th>File</th><th>Customer</th><th class="num">Débours incurred (XAF)</th>
        <th class="num">Débours recharged (XAF)</th><th class="num">Gap (XAF)</th><th class="num">Gap %</th></tr>
    @forelse ($f as $r)
      @php $over = $r['gap'] >= 0; $d = $det[$r['id']] ?? ['incurred' => [], 'recharged' => []]; @endphp
      <tr class="lg-roll-row" data-target="oop-{{ $r['id'] }}" data-q="{{ mb_strtolower($r['code'] . ' ' . $r['client']) }}" style="cursor:pointer">
        <td><span class="lg-arrow">▸</span></td>
        <td>{{ $r['code'] }}</td><td>{{ $r['client'] }}</td>
        <td class="num">{{ number_format($r['incurred']) }}</td>
        <td class="num">{{ number_format($r['recharged']) }}</td>
        <td class="num {{ $over ? 'oop-gain' : 'oop-loss' }}">{{ $over ? '+' : '' }}{{ number_format($r['gap']) }}</td>
        <td class="num {{ $over ? 'oop-gain' : 'oop-loss' }}">
          {{ $r['gap_pct'] !== null ? ($over ? '+' : '') . number_format($r['gap_pct'], 1) . '%' : ($over ? '+∞' : '—') }}
        </td>
      </tr>
      <tr id="oop-{{ $r['id'] }}" class="lg-roll-detail" style="display:none">
        <td colspan="7" style="padding:0 10px 12px 34px;background:#faf8f4">
          <div class="lg-grid2">
            <div>
              <div style="font-size:10px;letter-spacing:.07em;text-transform:uppercase;color:#7c7466;margin-bottom:4px">Débours incurred — advances paid</div>
              <table class="lg-dt sortable" style="font-size:11.5px">
                <tr><th>Paid to</th><th>Line</th><th>Date</th><th class="num">Amount (XAF)</th></tr>
                @forelse ($d['incurred'] as $x)
                  <tr><td>{{ $x['who'] }}</td><td>{{ $x['label'] ?: '—' }}</td><td>{{ $x['date'] }}</td>
                    <td class="num">{{ number_format($x['amount']) }}</td></tr>
                @empty
                  <tr><td colspan="4" style="color:#7c7466">No advances in this period.</td></tr>
                @endforelse
              </table>
            </div>
            <div>
              <div style="font-size:10px;letter-spacing:.07em;text-transform:uppercase;color:#7c7466;margin-bottom:4px">Débours recharged — billed to client</div>
              <table class="lg-dt sortable" style="font-size:11.5px">
                <tr><th>Invoice</th><th>Line</th><th>Date</th><th class="num">Amount (XAF)</th></tr>
                @forelse ($d['recharged'] as $x)
                  <tr><td>{{ $x['inv'] }}</td><td>{{ $x['label'] ?: '—' }}</td><td>{{ $x['date'] }}</td>
                    <td class="num">{{ number_format($x['amount']) }}</td></tr>
                @empty
                  <tr><td colspan="4" style="color:#7c7466">No débours billed in this period.</td></tr>
                @endforelse
              </table>
            </div>
          </div>
        </td>
      </tr>
    @empty
      <tr><td colspan="7" style="color:#7c7466">No débours activity in this period.</td></tr>
    @endforelse
  </table></div>
</div>

<div class="lg-note"><b>Reading.</b> Green % = Elimelec recharged the client <em>more</em> than the cash
actually paid out in this period — a gain on débours. Red % = recharged <em>less</em> than incurred — a
loss absorbed by Elimelec. The principle is a nil gap: every out-of-pocket expense recovered exactly.
Figures reflect advances disbursed and débours billed <b>within the selected period</b> — switch the
master filter to see a different window. "Incurred" counts cash advances only — débours a bank paid
directly are invisible until the provider exposes supplier payments per file.</div>

@include('logistics._interactive')
<script>
document.getElementById('oopq').addEventListener('input', function (e) {
  var q = e.target.value.trim().toLowerCase();
  document.querySelectorAll('#oopTable tr.lg-roll-row').forEach(function (tr) {
    var show = !q || tr.dataset.q.indexOf(q) !== -1;
    tr.style.display = show ? '' : 'none';
    if (!show) {
      var det = document.getElementById(tr.dataset.target);
      if (det) det.style.display = 'none';
      var a = tr.querySelector('.lg-arrow');
      if (a) a.textContent = '▸';
    }
  });
});
</script>
@endsection
