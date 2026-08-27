{{-- Logistics sector: billing lag analyses (file→bill, débours→recharge —
     the OOP Billing Latency Index). Drill-down: click a client to load
     transaction detail (?lg_customer=). --}}
@include('logistics._style')
@php
  $fb = $lg['file_bill'] ?? null;
  $db = $lg['debours'] ?? null;
  $qs = fn ($c) => request()->fullUrlWithQuery(['lg_customer' => $c]) . '#lg-detail';
@endphp

<div class="lg-card">
  <h3>File Creation → Billing Mean Time <span style="text-transform:none;letter-spacing:0;color:#7c7466;font-weight:500">· incl. OOP Billing Latency Index</span></h3>
  <div class="lg-tiles" style="margin:0 0 8px">
    <div class="lg-tile"><div class="l">Average, all files</div>
      <div class="v">{{ $fb && $fb['avg'] !== null ? number_format($fb['avg'], 1) . ' d' : '—' }}</div>
      <div class="s">{{ number_format($fb['n'] ?? 0) }} billed files</div></div>
    <div class="lg-tile"><div class="l">OOP Billing Latency Index</div>
      <div class="v">{{ $db && $db['avg'] !== null ? number_format($db['avg'], 1) . ' d' : '—' }}</div>
      <div class="s">débours incurred → rebilled, name-matched within the file</div></div>
    <div class="lg-tile {{ ($db['match_rate'] ?? 1) < .5 ? 'bad' : '' }}"><div class="l">Débours match rate</div>
      <div class="v">{{ $db && $db['match_rate'] !== null ? number_format(100 * $db['match_rate']) . '%' : '—' }}</div>
      <div class="s">{{ number_format($db['matched'] ?? 0) }} matched · {{ number_format($db['unmatched'] ?? 0) }} without a name match</div></div>
  </div>

  <div class="lg-grid2">
    <div>
      <h3>File → bill · by customer (slowest first)</h3>
      <div class="lg-scroll"><table class="lg-dt sortable cell-select">
        <tr><th>Customer</th><th class="num">Files</th><th class="num">Avg days</th><th></th></tr>
        @foreach (array_slice($fb['by_client'] ?? [], 0, 12) as $c)
          <tr><td>{{ $c['client'] }}</td><td class="num">{{ number_format($c['n']) }}</td>
            <td class="num">{{ number_format($c['avg'], 1) }}</td>
            <td><a href="{{ $qs($c['client']) }}" style="color:#0d6e6e;font-size:11px">details →</a></td></tr>
        @endforeach
      </table></div>
    </div>
    <div>
      <h3>OOP Billing Latency Index · by customer (slowest first)</h3>
      <div class="lg-scroll"><table class="lg-dt sortable cell-select">
        <tr><th>Customer</th><th class="num">Matched pairs</th><th class="num">Avg days</th><th></th></tr>
        @foreach (array_slice($db['by_client'] ?? [], 0, 12) as $c)
          <tr><td>{{ $c['client'] }}</td><td class="num">{{ number_format($c['n']) }}</td>
            <td class="num">{{ number_format($c['avg'], 1) }}</td>
            <td><a href="{{ $qs($c['client']) }}" style="color:#0d6e6e;font-size:11px">details →</a></td></tr>
        @endforeach
      </table></div>
    </div>
  </div>
</div>

@if ($lgCustomer !== '')
  <div class="lg-card" id="lg-detail">
    <h3>Transaction detail · {{ $lgCustomer }}
      <a href="{{ request()->fullUrlWithQuery(['lg_customer' => null]) }}" style="float:right;color:#7c7466;font-size:11px">clear ✕</a></h3>

    <h3 style="margin-top:8px">Files → first bill</h3>
    <div class="lg-scroll"><table class="lg-dt sortable cell-select">
      <tr><th>File</th><th>Opened</th><th>First billed</th><th class="num">Days</th></tr>
      @forelse ($fb['detail'] ?? [] as $r)
        <tr><td>{{ $r->code }}</td><td>{{ $r->opened_date }}</td><td>{{ $r->first_bill }}</td>
          <td class="num {{ $r->days > 30 ? 'neg' : '' }}">{{ number_format($r->days) }}</td></tr>
      @empty
        <tr><td colspan="4" style="color:#7c7466">No billed files for this customer.</td></tr>
      @endforelse
    </table></div>

    <h3 style="margin-top:16px">Débours incurred on file → recharge on revenue invoice <span style="text-transform:none;letter-spacing:0;color:#7c7466;font-weight:500">(OOP Billing Latency Index detail)</span></h3>
    <div class="lg-scroll"><table class="lg-dt sortable cell-select">
      <tr><th>File</th><th>Expense on file</th><th>Paid to</th><th>Incurred</th><th class="num">Amount (XAF)</th>
          <th>Recharged as</th><th>Invoice</th><th>Billed</th><th class="num">Amount (XAF)</th><th class="num">Days</th></tr>
      @forelse ($db['detail'] ?? [] as $p)
        <tr>
          <td>{{ $p['file'] }}</td>
          <td>{{ $p['adv_label'] }}</td>
          <td>{{ $p['adv_who'] }}</td>
          <td>{{ $p['adv_date'] }}</td>
          <td class="num">{{ number_format($p['adv_amount']) }}</td>
          <td>{{ $p['bill_label'] }}</td>
          <td>{{ $p['bill_inv'] }}</td>
          <td>{{ $p['bill_date'] }}</td>
          <td class="num">{{ number_format($p['bill_amount']) }}</td>
          <td class="num {{ $p['days'] > 30 ? 'neg' : ($p['days'] < 0 ? 'pos' : '') }}">{{ number_format($p['days']) }}</td>
        </tr>
      @empty
        <tr><td colspan="10" style="color:#7c7466">No name-matched débours pairs for this customer.</td></tr>
      @endforelse
    </table></div>
    <p style="color:#7c7466;font-size:11.5px;margin:8px 0 0">Matching is by normalized name within the same
    file (shared word of 4+ letters), not exact — negative days mean the débours was billed before the
    cash actually went out.</p>
  </div>
@endif

@include('logistics._interactive')
@include('logistics._cellselect')
