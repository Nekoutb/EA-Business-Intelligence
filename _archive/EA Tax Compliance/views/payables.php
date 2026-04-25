<?php
/** @var ?array $ap */
/** @var ?string $error */
?>
<div class="flex items-center justify-between mb-6">
  <div>
    <h2 class="text-lg font-semibold text-slate-900">Payables health</h2>
    <p class="text-sm text-slate-500">Six supplier-liquidity KPIs · aging · top suppliers</p>
  </div>
  <button id="refreshBtn" class="text-sm bg-white border border-slate-300 hover:border-indigo-400 hover:text-indigo-600 rounded px-3 py-2">Refresh</button>
</div>

<?php if ($error): ?>
  <div class="bg-red-50 border border-red-200 text-red-700 rounded p-4 mb-6">
    <div class="font-medium mb-1">Could not load payables data from Odoo</div>
    <div class="text-sm"><?= ea_e($error) ?></div>
    <div class="text-sm mt-2">Go to <a href="/settings" class="underline">Settings</a> and verify access to <span class="font-mono">account.move</span> for vendor bills.</div>
  </div>
<?php endif; ?>

<?php if ($ap): ?>
  <?php
    $cur = $ap['currency'];
    $k   = $ap['kpis'];
    $moneyHtml = fn(float $v): string => ea_e(ea_money($v, $cur));
  ?>

  <div class="text-xs text-slate-500 mb-4 flex flex-wrap items-center gap-x-3 gap-y-1">
    <span>As of: <span class="font-medium text-slate-700"><?= ea_e($ap['as_of']) ?></span></span>
    <span>·</span>
    <span>Open bills: <span class="font-medium text-slate-700"><?= number_format((int)$ap['open_bill_count']) ?></span></span>
    <span>·</span>
    <span>Currency: <span class="font-medium text-slate-700"><?= ea_e($cur) ?></span></span>
  </div>

  <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-8">
    <?php
      ea_render_partial('kpi_card', [
        'label'  => 'Total AP Outstanding',
        'value'  => $moneyHtml((float)$k['total_ap']),
        'sub'    => 'Money currently owed to suppliers',
        'hint'   => 'Σ amount_residual on posted vendor bills',
        'accent' => 'indigo',
      ]);
      ea_render_partial('kpi_card', [
        'label'  => 'Overdue AP',
        'value'  => $moneyHtml((float)$k['overdue_ap']),
        'sub'    => 'Past due · supplier-relationship risk',
        'hint'   => 'Residual where invoice_date_due < today',
        'accent' => ((float)$k['overdue_ap'] > 0) ? 'rose' : 'emerald',
      ]);
      ea_render_partial('kpi_card', [
        'label'  => 'Overdue Rate',
        'value'  => number_format((float)$k['overdue_rate_pct'], 1) . '%',
        'sub'    => 'Share of AP that is past due',
        'hint'   => '= Overdue / Total AP',
        'accent' => ((float)$k['overdue_rate_pct'] > 25) ? 'rose' :
                    (((float)$k['overdue_rate_pct'] > 10) ? 'amber' : 'emerald'),
      ]);

      $dpo = $k['dpo_days'];
      ea_render_partial('kpi_card', [
        'label'  => 'DPO (Days Payable Outstanding)',
        'value'  => $dpo === null ? '<span class="text-slate-400 text-lg">n/a</span>' : number_format((float)$dpo, 1) . ' days',
        'sub'    => 'Higher = more cash preserved',
        'hint'   => '= (AP / Total expenses YTD) × ' . (int)$ap['days_in_ytd'] . ' days',
        'accent' => 'sky',
      ]);
      ea_render_partial('kpi_card', [
        'label'  => 'Average Age of AP',
        'value'  => number_format((float)$k['avg_age_days'], 1) . ' days',
        'sub'    => 'Weighted by bill amount',
        'hint'   => '= Σ(age × residual) / Σ(residual)',
        'accent' => 'violet',
      ]);
      ea_render_partial('kpi_card', [
        'label'  => 'Top-5 Supplier Concentration',
        'value'  => number_format((float)$k['top5_concentration_pct'], 1) . '%',
        'sub'    => 'Supply-chain concentration',
        'hint'   => '= AP of top 5 suppliers / Total AP',
        'accent' => ((float)$k['top5_concentration_pct'] > 60) ? 'amber' : 'emerald',
      ]);
    ?>
  </div>

  <!-- Severe delinquency -->
  <div class="mt-2 mb-3">
    <h3 class="text-sm font-semibold text-slate-900">Severe delinquency</h3>
    <p class="text-xs text-slate-500">Bills aging past 60 / 90 days — supplier-relationship and legal-risk thresholds</p>
  </div>
  <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-8">
    <?php
      ea_render_partial('kpi_card', [
        'label'  => '% Payables > 60 days',
        'value'  => number_format((float)$k['over60_pct'], 1) . '%',
        'sub'    => 'Amount: <span class="font-medium text-slate-700">' . ea_e(ea_money((float)$k['over60_amount'], $cur)) . '</span>',
        'hint'   => '= (61-90 + 90+ buckets) / Total AP',
        'accent' => ((float)$k['over60_pct'] > 15) ? 'rose' :
                    (((float)$k['over60_pct'] > 5)  ? 'amber' : 'emerald'),
      ]);
      ea_render_partial('kpi_card', [
        'label'  => '% Payables > 90 days',
        'value'  => number_format((float)$k['over90_pct'], 1) . '%',
        'sub'    => 'Amount: <span class="font-medium text-slate-700">' . ea_e(ea_money((float)$k['over90_amount'], $cur)) . '</span>',
        'hint'   => 'Potential legal / credit-freeze risk',
        'accent' => ((float)$k['over90_pct'] > 5) ? 'rose' :
                    (((float)$k['over90_pct'] > 2) ? 'amber' : 'emerald'),
      ]);
    ?>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
    <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
      <div class="mb-3">
        <h3 class="text-sm font-semibold text-slate-900">AP Aging</h3>
        <p class="text-xs text-slate-500">Outstanding liability distribution by days past due</p>
      </div>
      <canvas id="apAgingChart" height="160"></canvas>
    </div>

    <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
      <div class="mb-3">
        <h3 class="text-sm font-semibold text-slate-900">Top 10 suppliers</h3>
        <p class="text-xs text-slate-500">Ranked by outstanding balance</p>
      </div>
      <?php if (!empty($ap['top_creditors'])): ?>
        <div class="max-h-[380px] overflow-auto scrollbar-thin">
          <table class="w-full text-sm">
            <thead class="sticky top-0 bg-white">
              <tr class="text-[11px] uppercase tracking-wider text-slate-500 border-b border-slate-100">
                <th class="text-left font-medium py-2">Supplier</th>
                <th class="text-right font-medium py-2">Outstanding</th>
                <th class="text-right font-medium py-2">Overdue</th>
                <th class="text-right font-medium py-2 w-20">Bills</th>
                <th class="text-right font-medium py-2 w-24">Max days</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($ap['top_creditors'] as $d): ?>
                <tr class="border-b border-slate-50 last:border-0">
                  <td class="py-2 text-slate-800 truncate max-w-[240px]" title="<?= ea_e($d['name']) ?>"><?= ea_e($d['name']) ?></td>
                  <td class="py-2 text-right tabular-nums text-slate-800"><?= ea_e(ea_money((float)$d['residual'], $cur)) ?></td>
                  <td class="py-2 text-right tabular-nums <?= ((float)$d['overdue'] > 0) ? 'text-rose-700' : 'text-slate-500' ?>"><?= ea_e(ea_money((float)$d['overdue'], $cur)) ?></td>
                  <td class="py-2 text-right tabular-nums text-slate-600"><?= (int)$d['invoices'] ?></td>
                  <td class="py-2 text-right tabular-nums text-slate-600"><?= (int)$d['max_days_overdue'] ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <p class="text-sm text-slate-500 py-4">No open payables.</p>
      <?php endif; ?>
    </div>
  </div>

  <script>
    (function () {
      const buckets = <?= json_encode($ap['aging']) ?>;
      const labels  = Object.values(buckets).map(b => b.label);
      const amounts = Object.values(buckets).map(b => +b.amount);
      const colors  = ['#10b981', '#fbbf24', '#f59e0b', '#f97316', '#e11d48'];
      new Chart(document.getElementById('apAgingChart'), {
        type: 'bar',
        data: { labels: labels, datasets: [{ label: 'Outstanding liability', data: amounts, backgroundColor: colors, borderRadius: 6 }] },
        options: {
          responsive: true,
          plugins: {
            legend: { display: false },
            tooltip: {
              callbacks: {
                label: (ctx) => '<?= addslashes($cur) ?> ' + ctx.parsed.y.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}),
                afterLabel: (ctx) => Object.values(buckets)[ctx.dataIndex].count + ' bills',
              }
            }
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

<?php ea_render_partial('ai_advisors', ['page' => 'payables']); ?>

<script>
  document.getElementById('refreshBtn')?.addEventListener('click', () => location.reload());
</script>
