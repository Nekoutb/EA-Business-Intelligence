@extends('layouts.app')
@section('title', 'Treasury')
@section('page-title', 'Treasury')

{{-- ════════════════════════════════════════════════════════════════════
     Treasury · as-of-now snapshot

     Four KPI sandboxes at the top — same visual language as the
     /dashboard cards (.v5-card, --surface, --text variables) — driven
     by GLAccountService. No filters: every figure is the latest
     posted balance. Methodology footer at the bottom spells out each
     OHADA prefix the engine reads.
   ════════════════════════════════════════════════════════════════════ --}}

@push('styles')
<style>
  /* Page-local dashboard-language overrides — copied verbatim from
     dashboard.blade.php so the four sandboxes match exactly. */
  .topbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; flex-shrink: 0; gap: 12px; }
  .topbar__t  { display: flex; align-items: baseline; gap: 12px; }
  .topbar__eb { font: 600 10px var(--font); color: var(--text-subtle); letter-spacing: .12em; text-transform: uppercase; display: flex; align-items: center; gap: 6px; }
  .topbar__eb::before { content:''; width:5px; height:5px; border-radius: 50%; background: var(--ea-primary); }
  .topbar__h  { font: 700 20px var(--font); color: var(--text); letter-spacing: -0.02em; margin-top: 2px; }
  .topbar__c  { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
  .topbar__meta { font: 500 11px var(--font); color: var(--text-subtle); }
  .topbar__meta b { color: var(--text); font-weight: 600; }

  /* Two-row grid: row 1 = the four KPI cards, row 2 reserved for
     the liquidity snapshot under col 1 only. grid-auto-rows:
     min-content keeps each row as tall as its tallest item, so the
     snapshot grows with its account list and the empty cells in
     row 2 cols 2-4 collapse to nothing. */
  .grid-treasury { display: grid; gap: 8px; grid-template-columns: repeat(4, 1fr); grid-auto-rows: min-content; margin-bottom: 12px; }
  @media (max-width: 1100px) { .grid-treasury { grid-template-columns: repeat(2, 1fr); } }
  @media (max-width: 600px)  { .grid-treasury { grid-template-columns: 1fr; } }

  .v5-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-lg); padding: 12px 14px; display: flex; flex-direction: column; min-height: 0; min-height: 130px; }
  .v5-card__h { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; margin-bottom: 4px; flex-shrink: 0; }
  .v5-card__t { font: 700 12px var(--font); color: var(--text); }
  .v5-card__sub { font: 400 10.5px var(--font); color: var(--text-subtle); margin-top: 1px; }
  .v5-card__b { flex: 1; min-height: 0; width: 100%; position: relative; padding: 8px 0; }
  .v5-card__f { font: 500 10px var(--font); color: var(--text-subtle); margin-top: 4px; padding-top: 6px; border-top: 1px solid var(--border-faint); flex-shrink: 0; line-height: 1.4; }
  .v5-card__f b { color: var(--text); font-weight: 600; }

  /* Headline number — bigger than the strip__v on dashboard so the four
     KPIs read at a glance. Same font + color tokens as the dashboard. */
  .kpi-hl { font: 700 26px var(--font); color: var(--text); line-height: 1.1; letter-spacing: -.02em; tabular-nums: true; }
  .kpi-hl .u { font: 500 12px var(--font); color: var(--text-subtle); margin-left: 4px; }
  .kpi-hl.is-pos { color: var(--pos); }
  .kpi-hl.is-neg { color: var(--neg); }
  .kpi-hl.is-warn { color: var(--warn); }

  .kpi-pair { display: flex; align-items: baseline; gap: 18px; }
  .kpi-pair__c { font: 700 22px var(--font); color: var(--text); line-height: 1.1; letter-spacing: -.02em; }
  .kpi-pair__c .l { display: block; font: 700 9px var(--font); color: var(--text-subtle); letter-spacing: .06em; text-transform: uppercase; margin-bottom: 2px; }

  .breakdown { font: 500 10px var(--font); color: var(--text-subtle); }
  .breakdown b { color: var(--text); font-weight: 600; }

  .empty-state { display: flex; align-items: center; justify-content: center; min-height: 60px; color: var(--text-faint); font: 500 11px var(--font); text-align: center; padding: 8px; }
  .error-card { padding: 12px 16px; background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; border-radius: var(--r); font-size: 12px; margin-bottom: 8px; }

  /* ── Liquidity snapshot · narrow column under the Cash card ──────
     Sits in row 2 col 1 of .grid-treasury so it aligns directly under
     "Cash & cash equivalents". One flat list of debit-positive 52+57
     accounts sorted high→low, with a single TOTAL that ties to the
     Cash headline above. Type sizes are 0.5px tighter than the full-
     width version so 30-char account names fit a 1/4-viewport column.
     The grid placement (rows 2-3 col 1) means tall lists overflow the
     row and stay narrow rather than stretching the row. */
  .snap { grid-column: 1 / 2; grid-row: 2 / span 1; background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-lg); }
  .snap__h { padding: 10px 14px 8px; border-bottom: 1px solid var(--border-faint); }
  .snap__t { font: 700 12px var(--font); color: var(--text); }
  .snap__sub { font: 400 10.5px var(--font); color: var(--text-subtle); margin-top: 1px; }
  .snap__hint { font: 500 9px var(--font); color: var(--text-faint); letter-spacing: .04em; margin-top: 4px; text-transform: uppercase; }
  .snap__list { padding: 4px 0; }
  .snap__row { display: grid; grid-template-columns: 1fr max-content; gap: 8px; align-items: baseline; padding: 5px 14px; }
  .snap__row + .snap__row { border-top: 1px solid var(--border-faint); }
  .snap__row.is-zero .snap__n,
  .snap__row.is-zero .snap__v { color: var(--text-faint); }
  .snap__n { font: 500 11.5px var(--font); color: var(--text); min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .snap__v { font: 600 11.5px var(--font); color: var(--text); text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
  .snap__tot { padding: 8px 14px; border-top: 2px solid var(--text); background: var(--border-faint); display: grid; grid-template-columns: 1fr max-content; gap: 8px; align-items: baseline; border-bottom-left-radius: var(--r-lg); border-bottom-right-radius: var(--r-lg); }
  .snap__tot .snap__n { font: 700 10px var(--font); letter-spacing: .08em; text-transform: uppercase; color: var(--text); }
  .snap__tot .snap__v { font: 700 13px var(--font); color: var(--text); }
  /* On narrow viewports where the grid collapses to 1-2 columns, the
     snapshot loses its column-1 anchor — let it span the available
     width naturally instead. */
  @media (max-width: 1100px) { .snap { grid-column: 1 / -1; grid-row: auto; } }

  /* ── Next 30-day disbursements · cols 2-3 of row 2 ────────────────
     Wider than the liquidity snapshot (spans 2 columns) because the
     row labels are longer ("Social insurance contributions",
     "Supplier invoices due (next 30d)"). Same vertical row layout
     and total-bar treatment as .snap so the two cards visually
     pair. Each row shows the projected amount on the right with an
     optional "ytd avg · N mo" basis hint underneath for the auditor. */
  .disb { grid-column: 2 / 4; grid-row: 2 / span 1; background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-lg); }
  .disb__h { padding: 10px 14px 8px; border-bottom: 1px solid var(--border-faint); display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap; }
  .disb__t { font: 700 12px var(--font); color: var(--text); }
  .disb__sub { font: 400 10.5px var(--font); color: var(--text-subtle); margin-top: 1px; }
  .disb__hint { font: 500 9px var(--font); color: var(--text-faint); letter-spacing: .04em; margin-top: 4px; text-transform: uppercase; text-align: right; }
  .disb__list { padding: 4px 0; }
  .disb__row { display: grid; grid-template-columns: 1fr max-content; gap: 12px; align-items: baseline; padding: 6px 14px; }
  .disb__row + .disb__row { border-top: 1px solid var(--border-faint); }
  .disb__n { font: 500 11.5px var(--font); color: var(--text); min-width: 0; }
  .disb__nb { display: block; font: 500 9.5px var(--font); color: var(--text-subtle); margin-top: 1px; }
  .disb__v { font: 600 11.5px var(--font); color: var(--text); text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
  .disb__tag { font: 600 8.5px var(--font); padding: 1px 5px; border-radius: 3px; margin-left: 6px; letter-spacing: .04em; vertical-align: middle; }
  .disb__tag.is-due { color: #ad1f1f; background: #fee2e2; border: 1px solid #fca5a5; }
  .disb__tot { padding: 8px 14px; border-top: 2px solid var(--text); background: var(--border-faint); display: grid; grid-template-columns: 1fr max-content; gap: 12px; align-items: baseline; border-bottom-left-radius: var(--r-lg); border-bottom-right-radius: var(--r-lg); }
  .disb__tot .disb__n { font: 700 10px var(--font); letter-spacing: .08em; text-transform: uppercase; color: var(--text); }
  .disb__tot .disb__v { font: 700 13px var(--font); color: var(--text); }
  /* Mobile: drop the column anchor so the card spans full width. */
  @media (max-width: 1100px) { .disb { grid-column: 1 / -1; grid-row: auto; } }

  .methodology { background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-lg); padding: 14px 16px; font: 500 11.5px var(--font); color: var(--text-muted); line-height: 1.6; }
  .methodology h3 { font: 700 12px var(--font); color: var(--text); margin-bottom: 6px; letter-spacing: -.01em; }
  .methodology code { background: var(--border-faint); color: var(--text); padding: 1px 5px; border-radius: 3px; font-size: 10.5px; }
  .methodology dl { display: grid; grid-template-columns: max-content 1fr; gap: 4px 14px; margin-top: 4px; }
  .methodology dt { font-weight: 600; color: var(--text); }
  .methodology dd { color: var(--text-muted); }
</style>
@endpush

@section('content')

@php
  // Defensive pulls — every figure can be null when Odoo hiccups.
  $t   = $treasury ?? null;
  $ccy = $t['currency'] ?? 'XAF';
  $asOf = $t['as_of'] ?? '';

  // Compact number formatter — same rules as the dashboard's
  // $shortNum / $shortUnit pair so the two pages format identically.
  $shortNum = function (?float $v): string {
      if ($v === null) return '—';
      $a = abs($v);
      if ($a >= 1_000_000_000) return number_format($v / 1_000_000_000, 2);
      if ($a >= 1_000_000)     return number_format($v / 1_000_000, 2);
      if ($a >= 1_000)         return number_format($v / 1_000, 0);
      return number_format($v, 0);
  };
  $shortUnit = function (?float $v, string $ccy = 'XAF'): string {
      if ($v === null) return '';
      $a = abs($v);
      $scale = $a >= 1_000_000_000 ? 'B ' : ($a >= 1_000_000 ? 'M ' : ($a >= 1_000 ? 'K ' : ''));
      return $scale . $ccy;
  };
  $ratio = function (?float $v): string {
      if ($v === null) return '—';
      return number_format($v, 2);
  };
@endphp

@if ($error)
  <div class="error-card">
    <strong>Error:</strong> {{ $error }} ·
    <a href="{{ tenant_url('/settings') }}" style="color:#991b1b; text-decoration:underline;">Check Odoo settings</a>
  </div>
@endif

<div class="topbar">
  <div class="topbar__t">
    <div>
      <div class="topbar__eb">Treasury · live</div>
      <div class="topbar__h">Cash, debt &amp; liquidity — as of now</div>
    </div>
  </div>
  <div class="topbar__c">
    <span class="topbar__meta">As of <b>{{ $asOf ?: 'now' }}</b> · live GL · no period filter</span>
  </div>
</div>

@if ($t)
<div class="grid-treasury">

  {{-- ── 1. Cash & cash equivalents ─────────────────────────── --}}
  <div class="v5-card">
    <div class="v5-card__h">
      <div>
        <div class="v5-card__t">Cash &amp; cash equivalents</div>
        <div class="v5-card__sub">OHADA classes 57 + 52 · debit-positive only</div>
      </div>
    </div>
    <div class="v5-card__b">
      @php $cash = $t['cash_equivalents'] ?? null; @endphp
      <div class="kpi-hl {{ $cash !== null && $cash > 0 ? 'is-pos' : '' }}">
        {{ $shortNum($cash) }}<span class="u">{{ $shortUnit($cash, $ccy) }}</span>
      </div>
      @php $cb = $t['cash_breakdown'] ?? []; @endphp
      <div class="breakdown" style="margin-top: 8px;">
        <span>Banks (52):</span> <b>{{ $shortNum($cb['banks'] ?? null) }}{{ $shortUnit($cb['banks'] ?? null, $ccy) }}</b><br>
        <span>Caisse (57):</span> <b>{{ $shortNum($cb['vault'] ?? null) }}{{ $shortUnit($cb['vault'] ?? null, $ccy) }}</b>
      </div>
    </div>
    <div class="v5-card__f">
      Σ debit-positive balances per account · overdrafts excluded.
    </div>
  </div>

  {{-- ── 2. Long-term debt ──────────────────────────────────── --}}
  <div class="v5-card">
    <div class="v5-card__h">
      <div>
        <div class="v5-card__t">Long-term debt</div>
        <div class="v5-card__sub">OHADA class 16 · emprunts &amp; dettes assimilées</div>
      </div>
    </div>
    <div class="v5-card__b">
      @php $ltd = $t['long_term_debt'] ?? null; @endphp
      <div class="kpi-hl {{ $ltd !== null && $ltd > 0 ? 'is-warn' : '' }}">
        {{ $shortNum($ltd) }}<span class="u">{{ $shortUnit($ltd, $ccy) }}</span>
      </div>
      <div class="breakdown" style="margin-top: 8px;">
        Net credit balance on
        <code style="background: var(--border-faint); padding: 1px 4px; border-radius: 2px; font: 600 10px var(--font);">16XX</code>
        across all sub-accounts.
      </div>
    </div>
    <div class="v5-card__f">
      Net liability magnitude · sign-flipped to display positive.
    </div>
  </div>

  {{-- ── 3. Short-term debt ─────────────────────────────────── --}}
  <div class="v5-card">
    <div class="v5-card__h">
      <div>
        <div class="v5-card__t">Short-term debt</div>
        <div class="v5-card__sub">OHADA classes 4 + 52 · per-account credit</div>
      </div>
    </div>
    <div class="v5-card__b">
      @php $std = $t['short_term_debt'] ?? null; @endphp
      <div class="kpi-hl {{ $std !== null && $std > 0 ? 'is-warn' : '' }}">
        {{ $shortNum($std) }}<span class="u">{{ $shortUnit($std, $ccy) }}</span>
      </div>
      @php $sb = $t['short_term_breakdown'] ?? []; @endphp
      <div class="breakdown" style="margin-top: 8px;">
        <span>3rd-party (4XXX):</span> <b>{{ $shortNum($sb['third_parties'] ?? null) }}{{ $shortUnit($sb['third_parties'] ?? null, $ccy) }}</b><br>
        <span>Bank overdraft (52):</span> <b>{{ $shortNum($sb['overdraft'] ?? null) }}{{ $shortUnit($sb['overdraft'] ?? null, $ccy) }}</b>
      </div>
    </div>
    <div class="v5-card__f">
      Σ per-account credit balances · receivables don't cancel payables.
    </div>
  </div>

  {{-- ── 4. Liquidity ratio ─────────────────────────────────── --}}
  <div class="v5-card">
    <div class="v5-card__h">
      <div>
        <div class="v5-card__t">Liquidity ratios</div>
        <div class="v5-card__sub">From Working Capital · live</div>
      </div>
    </div>
    <div class="v5-card__b">
      @php $lq = $t['liquidity'] ?? []; @endphp
      <div class="kpi-pair">
        <div class="kpi-pair__c">
          <span class="l">Current</span>
          {{ $ratio($lq['current'] ?? null) }}<span class="u" style="font: 500 11px var(--font); color: var(--text-subtle); margin-left: 2px;">x</span>
        </div>
        <div class="kpi-pair__c">
          <span class="l">Quick</span>
          {{ $ratio($lq['quick'] ?? null) }}<span class="u" style="font: 500 11px var(--font); color: var(--text-subtle); margin-left: 2px;">x</span>
        </div>
      </div>
      @if (!empty($lq['reason']))
        <div class="breakdown" style="margin-top: 8px;">{{ $lq['reason'] }}</div>
      @else
        <div class="breakdown" style="margin-top: 8px;">Higher = more cushion. Below 1.0 = liabilities exceed liquid assets.</div>
      @endif
    </div>
    <div class="v5-card__f">
      Mirrors <a href="{{ tenant_url('/working-capital') }}" style="color: var(--text); text-decoration: underline;">Working Capital</a> service exactly.
    </div>
  </div>

  {{-- ── Liquidity snapshot · per-account list under the Cash card ──
       Lives INSIDE .grid-treasury so its CSS grid-column/grid-row
       placement (1/2, 2/3) anchors it directly under the Cash card
       in column 1, row 2. Cells in row 2 cols 2-4 stay empty — the
       grid's `grid-auto-rows: min-content` collapses them so the
       methodology footer sits right under whichever ends taller
       (the snapshot or the row-1 cards). Detailed copy:
        • flat list, no subtotals
        • account name only (no code)
        • full-precision XAF amounts with thousands separators
        • sorted high→low; zeros greyed and sink to the bottom
        • TOTAL ties exactly to Cash & cash equivalents headline
        • overdrafts excluded (they live on the ST-debt card) --}}
  @php
    $snap   = $t['liquidity_snapshot'] ?? null;
    $rows   = $snap['rows'] ?? [];
    $snTot  = (float) ($snap['total'] ?? 0);
    // "XAF 47,250,000" formatter — same XAF-prefix style every row,
    // matching the user spec: "Description : XAF XXX amount".
    $xaf = function (float $v) use ($ccy): string {
        return $ccy . ' ' . number_format($v, 0, '.', ',');
    };
  @endphp
  @if (!empty($rows))
  <div class="snap">
    <div class="snap__h">
      <div class="snap__t">Liquidity snapshot</div>
      <div class="snap__sub">Per-account · sorted high → low</div>
      <div class="snap__hint">All amounts {{ $ccy }}</div>
    </div>
    <div class="snap__list">
      @foreach ($rows as $r)
        @php $isZero = ((float) $r['balance']) <= 0.5; @endphp
        <div class="snap__row {{ $isZero ? 'is-zero' : '' }}">
          <div class="snap__n" title="{{ $r['name'] }}">{{ $r['name'] }}</div>
          <div class="snap__v">{{ $xaf((float) $r['balance']) }}</div>
        </div>
      @endforeach
    </div>
    <div class="snap__tot">
      <div class="snap__n">Total</div>
      <div class="snap__v">{{ $xaf($snTot) }}</div>
    </div>
  </div>
  @endif

  {{-- ── Next 30-day disbursements · cols 2-3 of row 2 ────────────
       Forward-looking projection of cash outflows. Each row's
       "projection" is the YTD-monthly-average (Source 1+2 in
       methodology) or — for the supplier-invoices-due line —
       the actual amount due from the AP ledger (Source 3).
       Pairs visually with the liquidity snapshot in col 1: same
       row, same total-bar treatment, similar typography. --}}
  @php
    $disb       = $t['disbursements'] ?? null;
    $disbRows   = $disb['rows'] ?? [];
    $disbTotal  = (float) ($disb['total'] ?? 0);
    $disbBasis  = (int)   ($disb['months_basis'] ?? 0);
  @endphp
  @if (!empty($disbRows))
  <div class="disb">
    <div class="disb__h">
      <div>
        <div class="disb__t">Next 30-day disbursements</div>
        <div class="disb__sub">YTD-monthly-average projection · {{ $disbBasis }} mo basis · sorted high → low</div>
      </div>
      <div class="disb__hint">All amounts {{ $ccy }}</div>
    </div>
    <div class="disb__list">
      @foreach ($disbRows as $r)
        @php
          $hasAPDue = (bool) ($r['includes_ap_due'] ?? false);
          $basisHint = $disbBasis > 0
              ? 'YTD avg · ' . $disbBasis . ' mo basis'
              : 'YTD avg';
          // The supplier-payments row folds in actual AP invoices due
          // in the next 30 days on top of the YTD average. Surface
          // this in the row hint so the auditor knows the projection
          // is "average + due", not pure historical average.
          if ($hasAPDue) {
              $basisHint .= ' · plus AP due 30d (' . $xaf((float) $r['ap_due_30d']) . ')';
          }
        @endphp
        <div class="disb__row">
          <div class="disb__n">
            {{ $r['label'] }}
            <span class="disb__nb">{{ $basisHint }}</span>
          </div>
          <div class="disb__v">{{ $xaf((float) $r['projection']) }}</div>
        </div>
      @endforeach
    </div>
    <div class="disb__tot">
      <div class="disb__n">Total projected · next 30d</div>
      <div class="disb__v">{{ $xaf($disbTotal) }}</div>
    </div>
  </div>
  @endif

</div>

{{-- ── Methodology footer (single block, all four KPIs) ──────── --}}
<div class="methodology">
  <h3>How these figures are computed</h3>
  <p style="margin-bottom: 10px;">
    Every value reads <b>as of right now</b> from the latest Odoo refresh.
    No filters, no period selector. The engine queries posted move lines
    on the OHADA chart of accounts and aggregates server-side.
  </p>
  <dl>
    <dt>Cash &amp; equivalents</dt>
    <dd>Σ debit-positive balances per account, on classes <code>52</code> (banques) + <code>57</code> (caisse). Bank accounts in overdraft are excluded — their credit balances surface under Short-term debt instead.</dd>

    <dt>Long-term debt</dt>
    <dd>Net credit balance on class <code>16</code> (emprunts et dettes assimilées) — borrowings and similar liabilities recognised on the balance sheet.</dd>

    <dt>Short-term debt</dt>
    <dd>Σ per-account credit-positive balances on classes <code>4</code> (third parties: suppliers, social, tax, personnel, group) + <code>52</code> (bank overdrafts). Per-account aggregation prevents AR (411 in normal debit position) from offsetting AP (401 in normal credit position).</dd>

    <dt>Liquidity ratios</dt>
    <dd>Current Ratio = current assets ÷ current liabilities. Quick Ratio = (current assets − inventory) ÷ current liabilities. Both copied verbatim from the <a href="{{ tenant_url('/working-capital') }}" style="color: var(--text); text-decoration: underline;">Working Capital</a> service so the two pages can't disagree.</dd>

    <dt>Liquidity snapshot</dt>
    <dd>Per-account list of every <code>52</code> + <code>57</code> account with a positive debit balance, sorted high → low. Zero-balance and overdrawn accounts are excluded — overdrafts surface under Short-term debt instead.</dd>

    <dt>Next 30-day disbursements</dt>
    <dd>Forward-looking cash-outflow projection based on YTD history, by category. For each prefix (<code>16</code> long-term debt, <code>42</code> salaries, <code>43</code> social, <code>44</code> tax, <code>401</code> suppliers), sum debits posted via cash journals (type <code>cash</code> or <code>bank</code>) from <strong>1 January through the end of the last full month</strong>, divide by the number of full months elapsed → monthly run-rate. <strong>Supplier payments</strong> combine three components into a single row: (i) the <code>401</code> cash-journal average, (ii) the average of <em>direct expense-to-cash</em> entries (Dr 6XX / Cr 52|57 in the same move — petty-cash purchases that bypass the AP step), and (iii) the actual amount of AP invoices already posted with a due-date in the next 30 days (from the AP ledger). Class 2 (fixed assets) is excluded as non-recurrent; class 5 (cash) is excluded as internal transfers.</dd>
  </dl>
</div>

@else
  <div class="empty-state">
    Live GL data not available. Check Odoo connectivity.
  </div>
@endif

@endsection
