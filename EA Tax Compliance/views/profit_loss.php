<?php
/** @var ?array $pl */
/** @var ?array $period */
/** @var ?string $error */
?>
<div class="mb-6">
  <h2 class="text-lg font-semibold text-slate-900">Profit &amp; Loss analysis</h2>
  <p class="text-sm text-slate-500">Six performance metrics across the earnings waterfall · current period vs prior year</p>
</div>

<?php ea_render_partial('period_filter', [
  'action'     => '/profit-loss',
  'period'     => $period ?? ['type' => 'ytd'],
  'labelShown' => $pl['period']['label'] ?? '',
]); ?>

<?php if ($error): ?>
  <div class="bg-red-50 border border-red-200 text-red-700 rounded p-4 mb-6">
    <div class="font-medium mb-1">Could not load profit &amp; loss data</div>
    <div class="text-sm"><?= ea_e($error) ?></div>
    <div class="text-sm mt-2">
      This page reads directly from the general ledger. The Odoo API-key owner must have
      read access on <span class="font-mono">account.account</span> and
      <span class="font-mono">account.move.line</span>
      (typically granted via the <em>Accounting / Showcase</em> or <em>Accounting Manager</em> group).
    </div>
  </div>
<?php endif; ?>

<?php if ($pl): ?>
  <?php
    $cur = $pl['currency'];
    $c   = $pl['current'];
    $p   = $pl['previous'];
    $d   = $pl['delta'];
    $money = fn(float $v): string => ea_e(ea_money($v, $cur));
    $pp    = fn(float $x): string => ($x >= 0 ? '+' : '') . number_format($x, 1) . ' pp';
  ?>

  <div class="text-xs text-slate-500 mb-4 flex flex-wrap items-center gap-x-3 gap-y-1">
    <span>Window: <?= ea_e($pl['period']['from']) ?> → <?= ea_e($pl['period']['to']) ?></span>
    <span>·</span>
    <span>Currency: <span class="font-medium text-slate-700"><?= ea_e($cur) ?></span></span>
    <span>·</span>
    <span>Source: <span class="font-mono text-slate-700">account.move.line (posted)</span></span>
  </div>

  <!-- 6 KPI cards -->
  <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-8">
    <?php
      // 1. Revenue
      ea_render_partial('kpi_card', [
        'label'    => 'Revenue',
        'value'    => $money((float)$c['revenue']),
        'deltaPct' => $d['revenue_pct'],
        'sub'      => 'PY <span class="font-medium text-slate-600">' . $money((float)$p['revenue']) . '</span>',
        'hint'     => 'Σ income accounts (sign-flipped from GL balance)',
        'accent'   => 'indigo',
      ]);

      // 2. Gross Profit + margin
      ea_render_partial('kpi_card', [
        'label'    => 'Gross Profit',
        'value'    => $money((float)$c['gross_profit']),
        'deltaPct' => $d['gross_profit_pct'],
        'sub'      => 'GM <span class="font-medium text-slate-700">' . number_format((float)$c['gross_margin_pct'], 1) . '%</span> · PY ' . number_format((float)$p['gross_margin_pct'], 1) . '%',
        'hint'     => '= Revenue − COGS (direct cost accounts)',
        'accent'   => 'emerald',
      ]);

      // 3. EBITDA
      ea_render_partial('kpi_card', [
        'label'    => 'EBITDA',
        'value'    => $money((float)$c['ebitda']),
        'deltaPct' => $d['ebitda_pct'],
        'sub'      => 'Margin <span class="font-medium text-slate-700">' . number_format((float)$c['ebitda_margin_pct'], 1) . '%</span> · YoY ' . $pp((float)$d['ebitda_margin_pp']),
        'hint'     => '= Revenue − COGS − OpEx (pre D&A)',
        'accent'   => 'sky',
      ]);

      // 4. EBITDA Margin
      ea_render_partial('kpi_card', [
        'label'    => 'EBITDA Margin',
        'value'    => number_format((float)$c['ebitda_margin_pct'], 1) . '%',
        'sub'      => 'PY <span class="font-medium text-slate-700">' . number_format((float)$p['ebitda_margin_pct'], 1) . '%</span> · YoY ' . $pp((float)$d['ebitda_margin_pp']),
        'hint'     => '= EBITDA / Revenue — operating efficiency proxy',
        'accent'   => 'violet',
      ]);

      // 5. EBIT
      ea_render_partial('kpi_card', [
        'label'    => 'EBIT',
        'value'    => $money((float)$c['ebit']),
        'deltaPct' => $d['ebit_pct'],
        'sub'      => 'Margin <span class="font-medium text-slate-700">' . number_format((float)$c['ebit_margin_pct'], 1) . '%</span> · YoY ' . $pp((float)$d['ebit_margin_pp']),
        'hint'     => '= EBITDA − Depreciation & Amortization',
        'accent'   => 'amber',
      ]);

      // 6. Net Profit
      ea_render_partial('kpi_card', [
        'label'    => 'Net Profit',
        'value'    => $money((float)$c['net_profit']),
        'deltaPct' => $d['net_pct'],
        'sub'      => 'Margin <span class="font-medium text-slate-700">' . number_format((float)$c['net_margin_pct'], 1) . '%</span> · YoY ' . $pp((float)$d['net_margin_pp']),
        'hint'     => 'Bottom line (interest / tax lumped into OpEx)',
        'accent'   => ((float)$c['net_profit'] >= 0) ? 'emerald' : 'rose',
      ]);
    ?>
  </div>

  <!-- Earnings waterfall -->
  <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
    <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
      <div class="mb-3">
        <h3 class="text-sm font-semibold text-slate-900">Earnings waterfall (YTD)</h3>
        <p class="text-xs text-slate-500">From Revenue down to Net Profit</p>
      </div>
      <canvas id="plWaterfall" height="160"></canvas>
    </div>

    <!-- CY vs PY comparison table -->
    <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
      <div class="mb-3">
        <h3 class="text-sm font-semibold text-slate-900">CY vs PY detail</h3>
        <p class="text-xs text-slate-500">Absolute values and YoY deltas</p>
      </div>
      <table class="w-full text-sm">
        <thead>
          <tr class="text-[11px] uppercase tracking-wider text-slate-500 border-b border-slate-100">
            <th class="text-left font-medium py-2">Line</th>
            <th class="text-right font-medium py-2">CY</th>
            <th class="text-right font-medium py-2">PY</th>
            <th class="text-right font-medium py-2 w-24">YoY</th>
          </tr>
        </thead>
        <tbody>
          <?php
            $rows = [
              ['Revenue',      (float)$c['revenue'],      (float)$p['revenue'],      $d['revenue_pct']],
              ['COGS',         (float)$c['cogs'],         (float)$p['cogs'],         null],
              ['Gross Profit', (float)$c['gross_profit'], (float)$p['gross_profit'], $d['gross_profit_pct']],
              ['Operating Expenses', (float)$c['opex'],   (float)$p['opex'],         null],
              ['EBITDA',       (float)$c['ebitda'],       (float)$p['ebitda'],       $d['ebitda_pct']],
              ['Depreciation & Amortization', (float)$c['d_and_a'], (float)$p['d_and_a'], null],
              ['EBIT',         (float)$c['ebit'],         (float)$p['ebit'],         $d['ebit_pct']],
              ['Net Profit',   (float)$c['net_profit'],   (float)$p['net_profit'],   $d['net_pct']],
            ];
            foreach ($rows as $i => $r):
              $bold = in_array($r[0], ['Revenue','Gross Profit','EBITDA','EBIT','Net Profit'], true);
          ?>
            <tr class="border-b border-slate-50 last:border-0 <?= $bold ? 'font-medium' : '' ?>">
              <td class="py-2 text-slate-800"><?= ea_e($r[0]) ?></td>
              <td class="py-2 text-right tabular-nums text-slate-800"><?= $money($r[1]) ?></td>
              <td class="py-2 text-right tabular-nums text-slate-500"><?= $money($r[2]) ?></td>
              <td class="py-2 text-right tabular-nums <?= ($r[3] !== null && $r[3] >= 0) ? 'text-emerald-700' : (($r[3] !== null) ? 'text-rose-700' : 'text-slate-400') ?>">
                <?= $r[3] === null ? '—' : (($r[3] >= 0 ? '▲ ' : '▼ ') . number_format(abs($r[3]), 1) . '%') ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Analyst commentary -->
  <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm mb-4">
    <h3 class="text-sm font-semibold text-slate-900 mb-2">Top 6 performance signals</h3>
    <ul class="text-sm text-slate-700 space-y-2 list-disc list-inside">
      <li>
        <span class="font-medium">Gross Margin</span>
        at <span class="font-mono"><?= number_format((float)$c['gross_margin_pct'], 1) ?>%</span>
        (<?= $pp((float)$d['gross_margin_pp']) ?> vs PY).
        Direct-cost productivity; margin compression here usually signals input-cost or pricing issues.
      </li>
      <li>
        <span class="font-medium">EBITDA</span>
        at <span class="font-mono"><?= $money((float)$c['ebitda']) ?></span>
        (<span class="font-mono"><?= number_format((float)$c['ebitda_margin_pct'], 1) ?>%</span> margin).
        Operating cash-earnings proxy — the headline metric for scalability.
      </li>
      <li>
        <span class="font-medium">EBIT</span>
        at <span class="font-mono"><?= $money((float)$c['ebit']) ?></span>
        (<span class="font-mono"><?= number_format((float)$c['ebit_margin_pct'], 1) ?>%</span>).
        Post depreciation — reflects the true cost of maintaining the asset base.
      </li>
      <li>
        <span class="font-medium">Operating Leverage</span>
        OpEx <span class="font-mono"><?= $money((float)$c['opex']) ?></span>
        on revenue <span class="font-mono"><?= $money((float)$c['revenue']) ?></span>
        → OpEx intensity
        <span class="font-mono"><?= ((float)$c['revenue'] > 0) ? number_format(((float)$c['opex'] / (float)$c['revenue']) * 100, 1) . '%' : 'n/a' ?></span>.
      </li>
      <li>
        <span class="font-medium">Revenue momentum</span>
        <?= $d['revenue_pct'] === null ? '<span class="font-mono">n/a</span>' : (($d['revenue_pct'] >= 0 ? '+' : '') . '<span class="font-mono">' . number_format($d['revenue_pct'], 1) . '%</span>') ?> YoY.
      </li>
      <li>
        <span class="font-medium">Bottom-line resilience</span>
        Net margin
        <span class="font-mono"><?= number_format((float)$c['net_margin_pct'], 1) ?>%</span>
        (<?= $pp((float)$d['net_margin_pp']) ?>).
        Caveat: interest and tax are currently captured inside OpEx in your chart of accounts.
      </li>
    </ul>
  </div>

  <script>
    (function () {
      const labels = ['Revenue', '-COGS', 'Gross Profit', '-OpEx', 'EBITDA', '-D&A', 'EBIT'];
      const rev   = <?= json_encode((float)$c['revenue']) ?>;
      const cogs  = <?= json_encode((float)$c['cogs']) ?>;
      const gp    = <?= json_encode((float)$c['gross_profit']) ?>;
      const opex  = <?= json_encode((float)$c['opex']) ?>;
      const ebda  = <?= json_encode((float)$c['ebitda']) ?>;
      const da    = <?= json_encode((float)$c['d_and_a']) ?>;
      const ebit  = <?= json_encode((float)$c['ebit']) ?>;
      // Show each step as a bar; totals (green), subtractions (rose).
      const data = [rev, -cogs, gp, -opex, ebda, -da, ebit];
      const colors = ['#4f46e5', '#e11d48', '#10b981', '#e11d48', '#0ea5e9', '#e11d48', '#f59e0b'];
      new Chart(document.getElementById('plWaterfall'), {
        type: 'bar',
        data: { labels: labels, datasets: [{ data: data, backgroundColor: colors, borderRadius: 6 }] },
        options: {
          responsive: true,
          plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: (ctx) => '<?= addslashes($cur) ?> ' + ctx.parsed.y.toLocaleString(undefined, {minimumFractionDigits: 0, maximumFractionDigits: 0}) } }
          },
          scales: {
            x: { grid: { display: false }, ticks: { color: '#64748b' } },
            y: { ticks: { color: '#64748b', callback: (v) => v.toLocaleString() }, grid: { color: '#e2e8f0' } }
          }
        }
      });
    })();
  </script>
<?php endif; ?>

<?php ea_render_partial('ai_advisors', ['page' => 'profit_loss']); ?>
