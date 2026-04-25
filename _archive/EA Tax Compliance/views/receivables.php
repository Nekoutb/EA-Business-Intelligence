<?php
/** @var ?array $ar */
/** @var ?string $error */
?>
<div class="flex items-center justify-between mb-6">
  <div>
    <h2 class="text-lg font-semibold text-slate-900">Receivables health</h2>
    <p class="text-sm text-slate-500">Six liquidity KPIs · aging · top debtors</p>
  </div>
  <button id="refreshBtn" class="text-sm bg-white border border-slate-300 hover:border-indigo-400 hover:text-indigo-600 rounded px-3 py-2">Refresh</button>
</div>

<?php if ($error): ?>
  <div class="bg-red-50 border border-red-200 text-red-700 rounded p-4 mb-6">
    <div class="font-medium mb-1">Could not load receivables data from Odoo</div>
    <div class="text-sm"><?= ea_e($error) ?></div>
    <div class="text-sm mt-2">Go to <a href="/settings" class="underline">Settings</a> to verify your Odoo connection and access rights on <span class="font-mono">account.move</span>.</div>
  </div>
<?php endif; ?>

<?php if ($ar): ?>
  <?php
    $cur = $ar['currency'];
    $k   = $ar['kpis'];
    $moneyHtml = fn(float $v): string => ea_e(ea_money($v, $cur));
  ?>

  <div class="text-xs text-slate-500 mb-4 flex flex-wrap items-center gap-x-3 gap-y-1">
    <span>As of: <span class="font-medium text-slate-700"><?= ea_e($ar['as_of']) ?></span></span>
    <span>·</span>
    <span>Open invoices: <span class="font-medium text-slate-700"><?= number_format((int)$ar['open_invoice_count']) ?></span></span>
    <span>·</span>
    <span>Currency: <span class="font-medium text-slate-700"><?= ea_e($cur) ?></span></span>
  </div>

  <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-8">
    <?php
      // 1. Total AR
      ea_render_partial('kpi_card', [
        'label'  => 'Total AR Outstanding',
        'value'  => $moneyHtml((float)$k['total_ar']),
        'sub'    => 'Money currently owed to the business',
        'hint'   => 'Sum of amount_residual on posted customer invoices',
        'accent' => 'indigo',
      ]);

      // 2. Overdue AR
      ea_render_partial('kpi_card', [
        'label'  => 'Overdue AR',
        'value'  => $moneyHtml((float)$k['overdue_ar']),
        'sub'    => 'Past due date · collection priority',
        'hint'   => 'Residual where invoice_date_due < today',
        'accent' => ((float)$k['overdue_ar'] > 0) ? 'rose' : 'emerald',
      ]);

      // 3. Overdue rate
      ea_render_partial('kpi_card', [
        'label'  => 'Overdue Rate',
        'value'  => number_format((float)$k['overdue_rate_pct'], 1) . '%',
        'sub'    => 'Share of AR that is past due',
        'hint'   => '= Overdue / Total AR',
        'accent' => ((float)$k['overdue_rate_pct'] > 25) ? 'rose' :
                    (((float)$k['overdue_rate_pct'] > 10) ? 'amber' : 'emerald'),
      ]);

      // 4. DSO
      $dso = $k['dso_days'];
      ea_render_partial('kpi_card', [
        'label'  => 'DSO (Days Sales Outstanding)',
        'value'  => $dso === null ? '<span class="text-slate-400 text-lg">n/a</span>' : number_format((float)$dso, 1) . ' days',
        'sub'    => 'Lower = faster cash conversion',
        'hint'   => '= (AR / Revenue YTD) × ' . (int)$ar['days_in_ytd'] . ' days',
        'accent' => 'sky',
      ]);

      // 5. Avg age
      ea_render_partial('kpi_card', [
        'label'  => 'Average Age of AR',
        'value'  => number_format((float)$k['avg_age_days'], 1) . ' days',
        'sub'    => 'Weighted by invoice amount',
        'hint'   => '= Σ(age × residual) / Σ(residual)',
        'accent' => 'violet',
      ]);

      // 6. Top-5 concentration
      ea_render_partial('kpi_card', [
        'label'  => 'Top-5 Customer Concentration',
        'value'  => number_format((float)$k['top5_concentration_pct'], 1) . '%',
        'sub'    => 'Credit-risk concentration',
        'hint'   => '= AR of top 5 partners / Total AR',
        'accent' => ((float)$k['top5_concentration_pct'] > 60) ? 'amber' : 'emerald',
      ]);
    ?>
  </div>

  <!-- Severe-delinquency section: explicit Mc-Kinsey-style "bad-debt risk" view -->
  <div class="mt-2 mb-3">
    <h3 class="text-sm font-semibold text-slate-900">Severe delinquency</h3>
    <p class="text-xs text-slate-500">Receivables aging past 60 and 90 days — typical write-off / provisioning threshold</p>
  </div>
  <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-8">
    <?php
      ea_render_partial('kpi_card', [
        'label'  => '% Receivables > 60 days',
        'value'  => number_format((float)$k['over60_pct'], 1) . '%',
        'sub'    => 'Amount: <span class="font-medium text-slate-700">' . ea_e(ea_money((float)$k['over60_amount'], $cur)) . '</span>',
        'hint'   => '= (61-90 + 90+ buckets) / Total AR',
        'accent' => ((float)$k['over60_pct'] > 15) ? 'rose' :
                    (((float)$k['over60_pct'] > 5)  ? 'amber' : 'emerald'),
      ]);
      ea_render_partial('kpi_card', [
        'label'  => '% Receivables > 90 days',
        'value'  => number_format((float)$k['over90_pct'], 1) . '%',
        'sub'    => 'Amount: <span class="font-medium text-slate-700">' . ea_e(ea_money((float)$k['over90_amount'], $cur)) . '</span>',
        'hint'   => 'Typical bad-debt / write-off candidates',
        'accent' => ((float)$k['over90_pct'] > 5) ? 'rose' :
                    (((float)$k['over90_pct'] > 2) ? 'amber' : 'emerald'),
      ]);
    ?>
  </div>

  <!-- Aging chart -->
  <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
    <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
      <div class="mb-3">
        <h3 class="text-sm font-semibold text-slate-900">AR Aging</h3>
        <p class="text-xs text-slate-500">Distribution of outstanding balance by overdue bucket</p>
      </div>
      <canvas id="agingChart" height="160"></canvas>
    </div>

    <!-- Top debtors table -->
    <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
      <div class="mb-3">
        <h3 class="text-sm font-semibold text-slate-900">Top 10 debtors</h3>
        <p class="text-xs text-slate-500">Ranked by outstanding balance</p>
      </div>
      <?php if (!empty($ar['top_debtors'])): ?>
        <div class="max-h-[380px] overflow-auto scrollbar-thin">
          <table class="w-full text-sm">
            <thead class="sticky top-0 bg-white">
              <tr class="text-[11px] uppercase tracking-wider text-slate-500 border-b border-slate-100">
                <th class="text-left font-medium py-2">Customer</th>
                <th class="text-right font-medium py-2">Outstanding</th>
                <th class="text-right font-medium py-2">Overdue</th>
                <th class="text-right font-medium py-2 w-20">Inv.</th>
                <th class="text-right font-medium py-2 w-24">Max days</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($ar['top_debtors'] as $d): ?>
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
        <p class="text-sm text-slate-500 py-4">No open receivables.</p>
      <?php endif; ?>
    </div>
  </div>

  <script>
    (function () {
      const buckets = <?= json_encode($ar['aging']) ?>;
      const labels = Object.values(buckets).map(b => b.label);
      const amounts = Object.values(buckets).map(b => +b.amount);
      const colors = ['#10b981', '#fbbf24', '#f59e0b', '#f97316', '#e11d48'];
      new Chart(document.getElementById('agingChart'), {
        type: 'bar',
        data: {
          labels: labels,
          datasets: [{ label: 'Outstanding balance', data: amounts, backgroundColor: colors, borderRadius: 6 }],
        },
        options: {
          responsive: true,
          plugins: {
            legend: { display: false },
            tooltip: {
              callbacks: {
                label: (ctx) => '<?= addslashes($cur) ?> ' + ctx.parsed.y.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}),
                afterLabel: (ctx) => Object.values(buckets)[ctx.dataIndex].count + ' invoices',
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

<?php ea_render_partial('ai_advisors', ['page' => 'receivables']); ?>

<script>
  document.getElementById('refreshBtn')?.addEventListener('click', () => location.reload());
</script>
