{{-- Logistics sector: open receivables split into commission fees vs
     débours, aged from the billing date, with per-customer and per-invoice
     drill-down. Rendered on the Receivables page for tenants with dossier
     facts. Expects $logistics from LogisticsOpsService::receivables(). --}}
@include('logistics._style')
@php
  $ar     = $logistics;
  $tot    = (float) ($ar['total'] ?? 0);
  $pct    = fn (float $v) => $tot > 0 ? number_format(100 * $v / $tot, 1) . '%' : '—';
  $money  = fn (float $v) => number_format($v) . ' XAF';
  $bk     = $ar['buckets'] ?? [];
  $bmax   = max(array_map(fn ($b) => (float) $b['total'], $bk ?: [['total' => 1]])) ?: 1;
@endphp
<style>
  .ar-lg .v-fee, .ar-lg .v-deb { display:none; }
  .ar-lg.mode-fee .v-all, .ar-lg.mode-fee .v-deb { display:none; } .ar-lg.mode-fee .v-fee { display:inline; }
  .ar-lg.mode-deb .v-all, .ar-lg.mode-deb .v-fee { display:none; } .ar-lg.mode-deb .v-deb { display:inline; }
  .ar-lg .lg-brow .bar i { transition:width .25s; }
  .ar-lg .lg-brow.b-fee .bar i { background:#0d6e6e; } .ar-lg .lg-brow.b-deb .bar i { background:#b7791f; }
  .lg-seg { display:inline-flex; gap:4px; float:right; }
  .lg-seg button { font:600 10.5px/1 inherit; letter-spacing:.06em; text-transform:uppercase; padding:5px 10px; border-radius:999px; border:1px solid #e8e4de; background:#fff; color:#7c7466; cursor:pointer; }
  .lg-seg button.on { background:#1e3d44; color:#fff; border-color:#1e3d44; }
  .ar-lg tr.cust td:first-child { cursor:pointer; }
  .ar-lg tr.inv td { background:#faf9f6; font-size:12px; }
  .ar-lg tr.inv td:first-child { padding-left:28px; color:#5c6a66; }
  .ar-lg .age-hot { color:#9a3b2e; font-weight:600; }
</style>

<div class="lg-card ar-lg" id="ar-lg" data-mode="all">
  <h3>Open receivables · fees vs débours <span style="float:right;font-weight:500;text-transform:none;letter-spacing:0;color:#7c7466;font-size:11px">as of {{ $ar['as_of'] ?? '' }}</span></h3>
  <div class="lg-tiles" style="margin:0 0 6px">
    <div class="lg-tile"><div class="l">Total open AR</div><div class="v">{{ $money($tot) }}</div><div class="s">{{ number_format($ar['n'] ?? 0) }} open invoices</div></div>
    <div class="lg-tile good"><div class="l">Commission fees AR</div><div class="v">{{ $money((float) ($ar['fee'] ?? 0)) }}</div><div class="s">{{ $pct((float) ($ar['fee'] ?? 0)) }} of open AR · margin at risk</div></div>
    <div class="lg-tile bad"><div class="l">Débours AR</div><div class="v">{{ $money((float) ($ar['deb'] ?? 0)) }}</div><div class="s">{{ $pct((float) ($ar['deb'] ?? 0)) }} of open AR · cash already paid out</div></div>
  </div>
  <p style="font-size:11.5px;color:#7c7466;margin:0 0 14px">Each invoice's open balance is split in the proportion of its own fee and débours lines. Débours AR is money Elimelec has already disbursed on the client's behalf — not collecting it is a cash-flow loss, not a margin loss.</p>

  <h3>Ageing · days since billing
    <span class="lg-seg" role="group" aria-label="Ageing basis">
      <button type="button" data-mode="all" class="on">All</button>
      <button type="button" data-mode="fee">Commission fees</button>
      <button type="button" data-mode="deb">Débours</button>
    </span>
  </h3>
  <div class="lg-brow" style="font-weight:700"><span class="k">Total</span><span></span>
    <span class="n" style="color:#1e3d44">
      <span class="v-all">{{ $money($tot) }}</span><span class="v-fee">{{ $money((float) ($ar['fee'] ?? 0)) }}</span><span class="v-deb">{{ $money((float) ($ar['deb'] ?? 0)) }}</span>
    </span></div>
  @foreach ($bk as $label => $b)
    <div class="lg-brow {{ $label === '>90' ? 'warn' : '' }}"><span class="k">{{ $label }} days</span>
      <span class="bar">
        <i class="v-all" style="width:{{ max(1, (int) (100 * (float) $b['total'] / $bmax)) }}%"></i>
        <i class="v-fee" style="width:{{ max(1, (int) (100 * (float) $b['fee'] / $bmax)) }}%"></i>
        <i class="v-deb" style="width:{{ max(1, (int) (100 * (float) $b['deb'] / $bmax)) }}%"></i>
      </span>
      <span class="n">
        <span class="v-all">{{ $money((float) $b['total']) }} · {{ $pct((float) $b['total']) }}</span>
        <span class="v-fee">{{ $money((float) $b['fee']) }} · {{ $pct((float) $b['fee']) }}</span>
        <span class="v-deb">{{ $money((float) $b['deb']) }} · {{ $pct((float) $b['deb']) }}</span>
      </span></div>
  @endforeach

  <div style="margin-top:14px">
    <button type="button" id="ar-lg-reveal" class="lg-seg" style="float:none"><span class="on" style="display:inline-block;padding:5px 12px;border-radius:999px">Reveal ageing per customer ▾</span></button>
  </div>
  <div id="ar-lg-cust" hidden style="margin-top:10px">
    <p style="font-size:11px;color:#7c7466;margin:0 0 6px">Click a customer to list its open invoices. The fees / débours switch above applies here too.</p>
    <div class="lg-scroll"><table class="lg-dt cell-select">
      <tr><th>Customer</th><th class="num">Open invoices</th>
        @foreach (array_keys($bk) as $label)<th class="num">{{ $label }} d</th>@endforeach
        <th class="num">Total</th></tr>
      @foreach (($ar['customers'] ?? []) as $ci => $c)
        <tr class="cust" data-c="{{ $ci }}">
          <td><span class="lg-arrow">▸</span> {{ $c['client'] }}</td>
          <td class="num">{{ number_format($c['n']) }}</td>
          @foreach ($bk as $label => $_)
            @php $cb = $c['buckets'][$label]; @endphp
            <td class="num {{ $label === '>90' && $cb['total'] > 0 ? 'neg' : '' }}">
              <span class="v-all">{{ $cb['total'] > 0 ? number_format($cb['total']) : '' }}</span>
              <span class="v-fee">{{ $cb['fee'] > 0.5 ? number_format($cb['fee']) : '' }}</span>
              <span class="v-deb">{{ $cb['deb'] > 0.5 ? number_format($cb['deb']) : '' }}</span></td>
          @endforeach
          <td class="num" style="font-weight:700">
            <span class="v-all">{{ number_format($c['total']) }}</span>
            <span class="v-fee">{{ number_format($c['fee']) }}</span>
            <span class="v-deb">{{ number_format($c['deb']) }}</span></td>
        </tr>
        @foreach ($c['invoices'] as $inv)
          <tr class="inv" data-c="{{ $ci }}" hidden>
            <td>{{ $inv['inv'] }} · billed {{ $inv['date'] }}{{ $inv['due'] ? ' · due ' . $inv['due'] : '' }}</td>
            <td class="num {{ $inv['age'] > 90 ? 'age-hot' : '' }}">{{ $inv['age'] }} d</td>
            @foreach ($bk as $label => $_)
              <td class="num">@if ($inv['bucket'] === $label)
                <span class="v-all">{{ number_format($inv['total']) }}</span>
                <span class="v-fee">{{ $inv['fee'] > 0.5 ? number_format($inv['fee']) : '' }}</span>
                <span class="v-deb">{{ $inv['deb'] > 0.5 ? number_format($inv['deb']) : '' }}</span>
              @endif</td>
            @endforeach
            <td class="num">
              <span class="v-all">{{ number_format($inv['total']) }}</span>
              <span class="v-fee">{{ number_format($inv['fee']) }}</span>
              <span class="v-deb">{{ number_format($inv['deb']) }}</span></td>
          </tr>
        @endforeach
      @endforeach
      @if (empty($ar['customers']))
        <tr><td colspan="{{ 3 + count($bk) }}" style="color:#7c7466">No open receivables.</td></tr>
      @endif
    </table></div>
  </div>
</div>

<script>
(function () {
  const root = document.getElementById('ar-lg');
  if (!root) return;
  // Fees / débours / all: pure CSS switch driven by a class on the card,
  // so the three views never diverge and the tables stay selectable.
  root.querySelectorAll('.lg-seg [data-mode]').forEach(btn => {
    btn.addEventListener('click', () => {
      const m = btn.dataset.mode;
      root.classList.remove('mode-fee', 'mode-deb');
      if (m !== 'all') root.classList.add('mode-' + m);
      root.querySelectorAll('.lg-seg [data-mode]').forEach(b => b.classList.toggle('on', b === btn));
    });
  });
  const reveal = document.getElementById('ar-lg-reveal'), cust = document.getElementById('ar-lg-cust');
  if (reveal && cust) reveal.addEventListener('click', () => {
    cust.hidden = !cust.hidden;
    reveal.querySelector('span').textContent = cust.hidden ? 'Reveal ageing per customer ▾' : 'Hide ageing per customer ▴';
  });
  root.querySelectorAll('tr.cust').forEach(tr => {
    tr.querySelector('td').addEventListener('click', () => {
      const id = tr.dataset.c, open = tr.classList.toggle('open');
      tr.querySelector('.lg-arrow').textContent = open ? '▾' : '▸';
      root.querySelectorAll('tr.inv[data-c="' + id + '"]').forEach(r => { r.hidden = !open; });
    });
  });
})();
</script>
