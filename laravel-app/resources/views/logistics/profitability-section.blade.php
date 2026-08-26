{{-- Logistics sector: unit economics + débours reconciliation.
     Rendered on the Profitability page for tenants with dossier facts. --}}
@include('logistics._style')
@php
  $u = $logistics['unit'] ?? [];
  $r = $logistics['recon'] ?? [];
  $unit = fn ($v) => $v === null ? '—' : number_format((float) $v) . ' XAF';
@endphp

<div class="lg-card">
  <h3>Unit economics · YTD</h3>
  <div class="lg-tiles" style="margin:0">
    <div class="lg-tile good"><div class="l">Fee revenue · YTD</div><div class="v">{{ number_format($u['fees'] ?? 0) }}</div>
      <div class="s">XAF — débours are rebilled costs, not revenue · {{ number_format($u['tons'] ?? 0) }} t handled</div></div>
    <div class="lg-tile good"><div class="l">Fees / container</div><div class="v">{{ $unit($u['fees_per_ctn'] ?? null) }}</div>
      <div class="s">{{ number_format($u['containers'] ?? 0) }} containers</div></div>
    <div class="lg-tile"><div class="l">Fees / tonne</div><div class="v">{{ $unit($u['fees_per_ton'] ?? null) }}</div>
      <div class="s">own earnings</div></div>
    <div class="lg-tile"><div class="l">Débours billed / tonne</div><div class="v">{{ $unit($u['debours_per_ton'] ?? null) }}</div>
      <div class="s">recoverable costs billed</div></div>
    <div class="lg-tile"><div class="l">Advances / tonne</div><div class="v">{{ $unit($u['advances_per_ton'] ?? null) }}</div>
      <div class="s">recoverable costs incurred</div></div>
  </div>
</div>

<div class="lg-card">
  <h3>Débours reconciliation — billed to client vs incurred on file (all time)</h3>
  <div class="lg-tiles" style="margin:0 0 8px">
    <div class="lg-tile"><div class="l">Débours billed</div><div class="v">{{ number_format($r['billed'] ?? 0) }}</div><div class="s">XAF</div></div>
    <div class="lg-tile"><div class="l">Débours incurred</div><div class="v">{{ number_format($r['incurred'] ?? 0) }}</div><div class="s">XAF — cash advances on files</div></div>
    <div class="lg-tile good"><div class="l">Net variance</div>
      <div class="v">{{ ($r['billed'] ?? 0) - ($r['incurred'] ?? 0) >= 0 ? '+' : '' }}{{ number_format(($r['billed'] ?? 0) - ($r['incurred'] ?? 0)) }}</div>
      <div class="s">gain when positive — principle is nil</div></div>
    <div class="lg-tile good"><div class="l">Gain files</div><div class="v">{{ number_format($r['gain_n'] ?? 0) }}</div>
      <div class="s">+{{ number_format($r['gain'] ?? 0) }} XAF</div></div>
    <div class="lg-tile bad"><div class="l">Loss files</div><div class="v">{{ number_format($r['loss_n'] ?? 0) }}</div>
      <div class="s">−{{ number_format($r['loss'] ?? 0) }} XAF</div></div>
    <div class="lg-tile bad"><div class="l">Billed, no cost</div><div class="v">{{ number_format($r['billed_no_cost_n'] ?? 0) }}</div>
      <div class="s">files — check bank-paid débours</div></div>
    <div class="lg-tile bad"><div class="l">Cost, not billed</div><div class="v">{{ number_format($r['cost_no_billing_n'] ?? 0) }}</div>
      <div class="s">files — possible leakage</div></div>
  </div>
</div>

<div class="lg-grid2">
  <div class="lg-card">
    <h3>Best files by margin (fees + débours variance)</h3>
    <div class="lg-scroll"><table class="lg-dt">
      <tr><th>File</th><th>Client</th><th>Fees</th><th>Débours billed</th><th>Incurred</th><th>Margin</th></tr>
      @foreach ($logistics['top'] ?? [] as $f)
        <tr><td>{{ $f->code }}</td><td>{{ $f->client_name }}</td>
          <td class="num">{{ number_format((float) $f->fees) }}</td>
          <td class="num">{{ number_format((float) $f->deb) }}</td>
          <td class="num">{{ number_format((float) $f->adv) }}</td>
          <td class="num pos">{{ number_format((float) $f->margin) }}</td></tr>
      @endforeach
    </table></div>
  </div>
  <div class="lg-card">
    <h3>Worst files — money out, not (yet) billed</h3>
    <div class="lg-scroll"><table class="lg-dt">
      <tr><th>File</th><th>Client</th><th>Fees</th><th>Débours billed</th><th>Incurred</th><th>Margin</th></tr>
      @foreach ($logistics['bottom'] ?? [] as $f)
        <tr><td>{{ $f->code }}</td><td>{{ $f->client_name }}</td>
          <td class="num">{{ number_format((float) $f->fees) }}</td>
          <td class="num">{{ number_format((float) $f->deb) }}</td>
          <td class="num">{{ number_format((float) $f->adv) }}</td>
          <td class="num neg">{{ number_format((float) $f->margin) }}</td></tr>
      @endforeach
    </table></div>
  </div>
</div>

<div class="lg-note ok"><b>Method.</b> Fees are own earnings; débours are out-of-pocket costs rebilled
to the client and should reconcile to nil per file. "Incurred" counts cash advances only — débours a
bank paid directly are invisible until the provider exposes supplier payments per file. Margin is
billed-not-collected (payment history not yet loaded).</div>
