<?php
/** @var ?array $analysis */
/** @var ?array $topCustomers */
/** @var ?array $topProducts */
/** @var ?array $period */
/** @var ?string $error */
?>
<div class="flex flex-wrap items-start justify-between gap-4 mb-6">
  <div>
    <h2 class="text-lg font-semibold text-slate-900">Revenue deep-dive</h2>
    <p class="text-sm text-slate-500">7 KPIs · trend · top customers · top products</p>
  </div>
</div>

<?php ea_render_partial('period_filter', [
  'action'     => '/revenue',
  'period'     => $period ?? ['type' => 'ytd'],
  'labelShown' => $analysis['period']['label'] ?? '',
]); ?>

<?php if ($error): ?>
  <div class="bg-red-50 border border-red-200 text-red-700 rounded p-4 mb-6">
    <div class="font-medium mb-1">Could not load data from Odoo</div>
    <div class="text-sm"><?= ea_e($error) ?></div>
    <div class="text-sm mt-2">Go to <a href="/settings" class="underline">Settings</a> to verify your Odoo connection.</div>
  </div>
<?php endif; ?>

<?php if ($analysis): ?>
  <?php ea_render_partial('revenue_kpis', ['analysis' => $analysis, 'chartId' => 'revTrendChart']); ?>

  <?php $cur = $analysis['currency']; ?>
  <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
    <!-- Top customers -->
    <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
      <div class="flex items-center justify-between mb-3">
        <div>
          <h3 class="text-sm font-semibold text-slate-900">Top 5 customers (YTD)</h3>
          <p class="text-xs text-slate-500">By revenue share</p>
        </div>
      </div>
      <?php if (!empty($topCustomers)): ?>
        <table class="w-full text-sm">
          <thead>
            <tr class="text-[11px] uppercase tracking-wider text-slate-500 border-b border-slate-100">
              <th class="text-left font-medium py-2">Customer</th>
              <th class="text-right font-medium py-2">Revenue</th>
              <th class="text-right font-medium py-2 w-24">Share</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($topCustomers as $c): ?>
              <tr class="border-b border-slate-50 last:border-0">
                <td class="py-2 text-slate-800 truncate max-w-[280px]" title="<?= ea_e($c['name']) ?>"><?= ea_e($c['name']) ?></td>
                <td class="py-2 text-right tabular-nums text-slate-800"><?= ea_e(ea_money((float)$c['revenue'], $cur)) ?></td>
                <td class="py-2 text-right tabular-nums text-slate-600"><?= number_format((float)$c['share'], 1) ?>%</td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <p class="text-sm text-slate-500 py-4">No customer data in the YTD window.</p>
      <?php endif; ?>
    </div>

    <!-- Top products -->
    <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
      <div class="flex items-center justify-between mb-3">
        <div>
          <h3 class="text-sm font-semibold text-slate-900">Top 5 products (YTD)</h3>
          <p class="text-xs text-slate-500">By revenue share</p>
        </div>
      </div>
      <?php if (!empty($topProducts)): ?>
        <table class="w-full text-sm">
          <thead>
            <tr class="text-[11px] uppercase tracking-wider text-slate-500 border-b border-slate-100">
              <th class="text-left font-medium py-2">Product</th>
              <th class="text-right font-medium py-2 w-20">Qty</th>
              <th class="text-right font-medium py-2">Revenue</th>
              <th class="text-right font-medium py-2 w-24">Share</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($topProducts as $p): ?>
              <tr class="border-b border-slate-50 last:border-0">
                <td class="py-2 text-slate-800 truncate max-w-[260px]" title="<?= ea_e($p['name']) ?>"><?= ea_e($p['name']) ?></td>
                <td class="py-2 text-right tabular-nums text-slate-600"><?= number_format((float)$p['qty'], 0) ?></td>
                <td class="py-2 text-right tabular-nums text-slate-800"><?= ea_e(ea_money((float)$p['revenue'], $cur)) ?></td>
                <td class="py-2 text-right tabular-nums text-slate-600"><?= number_format((float)$p['share'], 1) ?>%</td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <p class="text-sm text-slate-500 py-4">Product breakdown is available for invoice/sale sources only.</p>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php ea_render_partial('ai_advisors', ['page' => 'revenue']); ?>

<script>
  document.getElementById('refreshBtn')?.addEventListener('click', () => location.reload());
</script>
