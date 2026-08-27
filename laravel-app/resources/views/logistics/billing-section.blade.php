{{-- Logistics sector: billing lag analyses (file→bill, débours→recharge —
     the OOP Billing Latency Index). Drill-down: click a client to load
     transaction detail (?lg_customer=). --}}
@include('logistics._style')
<style>
  .lg-filechips { display:flex; flex-wrap:wrap; gap:6px; align-items:center; margin:10px 0 14px; }
  .lg-filechips__label { font:600 10px/1.4 -apple-system,sans-serif; letter-spacing:.05em; text-transform:uppercase; color:#7c7466; margin-right:4px; }
  .lg-filechip { border:1px solid #e8e4de; background:#fff; color:#1e3d44; font:600 11.5px "Consolas",monospace;
    padding:5px 10px; border-radius:16px; cursor:pointer; }
  .lg-filechip:hover { border-color:#0d6e6e; }
  .lg-filechip.active { background:#0d6e6e; border-color:#0d6e6e; color:#fff; }
  .ur-subhead { font:600 10px/1 -apple-system,sans-serif; letter-spacing:.07em; text-transform:uppercase; color:#7c7466; margin-bottom:4px; }
  tr.ur-unmatched td, .ur-unmatched { background: rgba(154,59,46,.12); color:#9a3b2e; font-weight:600; }
</style>
@php
  $fb = $lg['file_bill'] ?? null;
  $db = $lg['debours'] ?? null;
  $ud = $unbilledDeb ?? null;
  $ur = $unbilledRev ?? null;
  $qs = fn ($c) => request()->fullUrlWithQuery(['lg_customer' => $c]) . '#lg-detail';
@endphp

<div class="lg-card">
  <h3>File Creation → Billing Mean Time · {{ $periodLabel ?? '' }} <span style="text-transform:none;letter-spacing:0;color:#7c7466;font-weight:500">· incl. OOP Billing Latency Index</span></h3>
  <p style="color:#7c7466;font-size:11.5px;margin:-4px 0 10px">Scoped to bills <b>issued</b> in the selected
    period — switch the master filter (e.g. to August) to see that month's bills only.</p>
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
  @php
    $fileCodes = collect($fb['detail'] ?? [])->pluck('code')
      ->merge(collect($db['detail'] ?? [])->pluck('file'))
      ->filter()->unique()->sort()->values();
  @endphp
  <div class="lg-card" id="lg-detail">
    <h3>Transaction detail · {{ $lgCustomer }}
      <a href="{{ request()->fullUrlWithQuery(['lg_customer' => null]) }}" style="float:right;color:#7c7466;font-size:11px">clear ✕</a></h3>

    @if ($fileCodes->count() > 1)
      <div class="lg-filechips" id="lgFileChips">
        <span class="lg-filechips__label">Filter by file — click one to isolate it, click again for all:</span>
        @foreach ($fileCodes as $fc)
          <button type="button" class="lg-filechip" data-file="{{ $fc }}">{{ $fc }}</button>
        @endforeach
      </div>
    @endif

    <h3 style="margin-top:8px">Files → first bill</h3>
    <div class="lg-scroll"><table class="lg-dt sortable cell-select">
      <tr><th>File</th><th>Opened</th><th>First billed</th><th class="num">Days</th></tr>
      @forelse ($fb['detail'] ?? [] as $r)
        <tr data-file="{{ $r->code }}"><td>{{ $r->code }}</td><td>{{ $r->opened_date }}</td><td>{{ $r->first_bill }}</td>
          <td class="num {{ $r->days > 30 ? 'neg' : '' }}">{{ number_format($r->days) }}</td></tr>
      @empty
        <tr><td colspan="4" style="color:#7c7466">No billed files for this customer.</td></tr>
      @endforelse
      <tr class="lg-file-empty" style="display:none"><td colspan="4" style="color:#7c7466">No first-bill record for this file.</td></tr>
    </table></div>

    <h3 style="margin-top:16px">Débours incurred on file → recharge on revenue invoice <span style="text-transform:none;letter-spacing:0;color:#7c7466;font-weight:500">(OOP Billing Latency Index detail)</span></h3>
    <div class="lg-scroll"><table class="lg-dt sortable cell-select">
      <tr><th>File</th><th>Expense on file</th><th>Paid to</th><th>Incurred</th><th class="num">Amount (XAF)</th>
          <th>Recharged as</th><th>Invoice</th><th>Billed</th><th class="num">Amount (XAF)</th><th class="num">Days</th></tr>
      @forelse ($db['detail'] ?? [] as $p)
        <tr data-file="{{ $p['file'] }}">
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
      <tr class="lg-file-empty" style="display:none"><td colspan="10" style="color:#7c7466">No débours pair for this file.</td></tr>
    </table></div>
    <p style="color:#7c7466;font-size:11.5px;margin:8px 0 0">Matching is by normalized name within the same
    file (shared word of 4+ letters), not exact — negative days mean the débours was billed before the
    cash actually went out.</p>
  </div>
@endif

{{-- ── Sandbox: Unbilled Débours ──────────────────────────────────── --}}
<div class="lg-card">
  <h3>Unbilled Débours · {{ $periodLabel ?? '' }} <span style="float:right;font-weight:500;text-transform:none;letter-spacing:0;color:#7c7466;font-size:11px">click ▸ for a customer's transactions</span></h3>
  <p style="color:#7c7466;font-size:11.5px;margin:-4px 0 10px">Advances <b>disbursed</b> in the period that
    have never been name-matched to a débours line billed to the client (searched across all time — the
    recharge, if it ever happens, may land in a later period).</p>
  <div class="lg-tiles" style="margin:0 0 10px">
    <div class="lg-tile bad"><div class="l">Unbilled débours</div><div class="v">{{ number_format($ud['total'] ?? 0) }}</div><div class="s">XAF — cash out, nothing recovered yet</div></div>
    <div class="lg-tile"><div class="l">Transactions</div><div class="v">{{ number_format($ud['count'] ?? 0) }}</div><div class="s">unmatched advances</div></div>
  </div>
  <div class="lg-scroll"><table class="lg-dt sortable cell-select">
    <tr><th></th><th>Customer</th><th class="num">Transactions</th><th class="num">Amount (XAF)</th></tr>
    @forelse ($ud['by_client'] ?? [] as $i => $c)
      <tr class="lg-roll-row" data-target="ud-{{ $i }}">
        <td><span class="lg-arrow">▸</span></td>
        <td>{{ $c['client'] }}</td><td class="num">{{ number_format($c['n']) }}</td><td class="num">{{ number_format($c['amt']) }}</td>
      </tr>
      <tr id="ud-{{ $i }}" class="lg-roll-detail" style="display:none">
        <td colspan="4" style="padding:0 10px 12px 34px;background:#faf8f4">
          <table class="lg-dt sortable cell-select">
            <tr><th>File</th><th>Expense</th><th>Paid to</th><th>Disbursed</th><th class="num">Amount (XAF)</th></tr>
            @foreach (collect($ud['items'] ?? [])->where('client', $c['client'])->values() as $it)
              <tr><td>{{ $it['file'] }}</td><td>{{ $it['label'] ?: '—' }}</td><td>{{ $it['who'] }}</td>
                <td>{{ $it['date'] }}</td><td class="num">{{ number_format($it['amount']) }}</td></tr>
            @endforeach
          </table>
        </td>
      </tr>
    @empty
      <tr><td colspan="4" style="color:#7c7466">No unbilled débours disbursed in this period.</td></tr>
    @endforelse
  </table></div>
</div>

{{-- ── Sandbox: Unbilled Revenue / Commissions ────────────────────── --}}
<div class="lg-card">
  <h3>Unbilled Revenue / Commissions · {{ $periodLabel ?? '' }} <span style="float:right;font-weight:500;text-transform:none;letter-spacing:0;color:#7c7466;font-size:11px">click ▸ for files, then a file for its detail</span></h3>
  <p style="color:#7c7466;font-size:11.5px;margin:-4px 0 10px">Files with activity in the period (opened,
    invoiced, or an advance disbursed) whose all-time fee revenue is still <b>zero</b> — work done or cost
    incurred with no commission ever earned on that file. Opening a file compares what was <b>incurred</b>
    against what was <b>invoiced</b>; anything incurred but not traceable on an invoice is
    <span class="ur-unmatched" style="padding:1px 5px;border-radius:4px">highlighted</span>.</p>
  <div class="lg-tiles" style="margin:0 0 10px">
    <div class="lg-tile bad"><div class="l">Zero-fee files</div><div class="v">{{ number_format($ur['count'] ?? 0) }}</div><div class="s">active this period</div></div>
    <div class="lg-tile"><div class="l">Débours billed on them</div><div class="v">{{ number_format($ur['total_debours_billed'] ?? 0) }}</div><div class="s">XAF, all-time</div></div>
    <div class="lg-tile"><div class="l">Advances paid on them</div><div class="v">{{ number_format($ur['total_advances'] ?? 0) }}</div><div class="s">XAF, all-time</div></div>
  </div>
  <div class="lg-scroll"><table class="lg-dt sortable cell-select">
    <tr><th></th><th>Customer</th><th class="num">Files</th><th class="num">Débours billed (XAF)</th><th class="num">Advances paid (XAF)</th></tr>
    @forelse ($ur['by_client'] ?? [] as $i => $c)
      <tr class="lg-roll-row" data-target="ur-c-{{ $i }}">
        <td><span class="lg-arrow">▸</span></td>
        <td>{{ $c['client'] }}</td><td class="num">{{ number_format($c['n']) }}</td>
        <td class="num">{{ number_format($c['deb']) }}</td>
        <td class="num {{ $c['adv'] > 0.5 ? 'neg' : '' }}">{{ number_format($c['adv']) }}</td>
      </tr>
      <tr id="ur-c-{{ $i }}" class="lg-roll-detail" style="display:none">
        <td colspan="5" style="padding:0 10px 12px 34px;background:#faf8f4">
          <table class="lg-dt sortable cell-select">
            <tr><th></th><th>File</th><th>Type</th><th>Opened</th><th class="num">Débours billed (XAF)</th><th class="num">Advances paid (XAF)</th></tr>
            @foreach (collect($ur['files'] ?? [])->where('client', $c['client'])->values() as $j => $r)
              @php $d = $ur['detail'][$r['id']] ?? ['incurred' => [], 'invoiced' => []]; @endphp
              <tr class="lg-roll-row" data-target="ur-f-{{ $i }}-{{ $j }}">
                <td><span class="lg-arrow">▸</span></td>
                <td>{{ $r['code'] }}</td><td>{{ $r['type'] }}</td><td>{{ $r['opened'] }}</td>
                <td class="num">{{ number_format($r['debours_billed']) }}</td>
                <td class="num {{ $r['advances'] > 0.5 ? 'neg' : '' }}">{{ number_format($r['advances']) }}</td>
              </tr>
              <tr id="ur-f-{{ $i }}-{{ $j }}" class="lg-roll-detail" style="display:none">
                <td colspan="6" style="padding:0 10px 14px 34px;background:#fff">
                  <div class="lg-grid2">
                    <div>
                      <div class="ur-subhead">Débours incurred</div>
                      <table class="lg-dt sortable cell-select">
                        <tr><th>Expense</th><th>Paid to</th><th>Date</th><th class="num">Amount (XAF)</th></tr>
                        @forelse ($d['incurred'] as $x)
                          <tr class="{{ $x['matched'] ? '' : 'ur-unmatched' }}">
                            <td>{{ $x['label'] ?: '—' }}</td><td>{{ $x['who'] }}</td><td>{{ $x['date'] }}</td>
                            <td class="num">{{ number_format($x['amount']) }}</td>
                          </tr>
                        @empty
                          <tr><td colspan="4" style="color:#7c7466">No advances recorded on this file.</td></tr>
                        @endforelse
                      </table>
                    </div>
                    <div>
                      <div class="ur-subhead">Invoice issued</div>
                      @if (count($d['invoiced']))
                        <table class="lg-dt sortable cell-select">
                          <tr><th>Invoice</th><th>Line</th><th>Date</th><th class="num">Amount (XAF)</th></tr>
                          @foreach ($d['invoiced'] as $x)
                            <tr><td>{{ $x['inv'] }}</td><td>{{ $x['label'] ?: '—' }}</td><td>{{ $x['date'] }}</td>
                              <td class="num">{{ number_format($x['amount']) }}</td></tr>
                          @endforeach
                        </table>
                      @endif
                    </div>
                  </div>
                </td>
              </tr>
            @endforeach
          </table>
        </td>
      </tr>
    @empty
      <tr><td colspan="5" style="color:#7c7466">No zero-fee files active in this period.</td></tr>
    @endforelse
  </table></div>
</div>

@include('logistics._interactive')
@include('logistics._cellselect')
<script>
(function () {
  var chips = document.querySelectorAll('#lgFileChips .lg-filechip');
  if (!chips.length) return;
  var active = null;

  function apply() {
    document.querySelectorAll('#lg-detail table.lg-dt').forEach(function (table) {
      var rows = table.querySelectorAll('tr[data-file]');
      var anyVisible = false;
      rows.forEach(function (tr) {
        var show = !active || tr.dataset.file === active;
        tr.style.display = show ? '' : 'none';
        if (show) anyVisible = true;
      });
      var emptyRow = table.querySelector('tr.lg-file-empty');
      if (emptyRow) emptyRow.style.display = (rows.length && !anyVisible) ? '' : 'none';
    });
  }

  chips.forEach(function (chip) {
    chip.addEventListener('click', function () {
      active = (active === chip.dataset.file) ? null : chip.dataset.file;
      chips.forEach(function (c) { c.classList.toggle('active', c.dataset.file === active); });
      apply();
    });
  });
})();
</script>
