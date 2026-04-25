<?php
/** @var ?array $fa */
/** @var ?string $error */
?>
<div class="flex items-center justify-between mb-6">
  <div>
    <h2 class="text-lg font-semibold text-slate-900">Fixed assets portfolio</h2>
    <p class="text-sm text-slate-500">Gross value · net book value · depreciation · asset aging</p>
  </div>
  <button id="refreshBtn" class="text-sm bg-white border border-slate-300 hover:border-indigo-400 hover:text-indigo-600 rounded px-3 py-2">Refresh</button>
</div>

<?php if ($error): ?>
  <div class="bg-red-50 border border-red-200 text-red-700 rounded p-4 mb-6">
    <div class="font-medium mb-1">Could not load fixed-assets data</div>
    <div class="text-sm"><?= ea_e($error) ?></div>
  </div>
<?php endif; ?>

<?php if ($fa && $fa['module'] === 'absent'): ?>
  <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded p-4 mb-6">
    <div class="font-medium mb-1">Assets module not available</div>
    <div class="text-sm"><?= ea_e($fa['message'] ?? '') ?></div>
    <div class="text-sm mt-2">
      Install the Odoo <span class="font-mono">account_asset</span> module (part of Accounting) and grant the API-key user read access on <span class="font-mono">account.asset</span>.
    </div>
  </div>
<?php endif; ?>

<?php if ($fa && $fa['module'] !== 'absent'): ?>
  <?php
    $cur = $fa['currency'];
    $k   = $fa['kpis'];
    $money = fn(float $v): string => ea_e(ea_money($v, $cur));
  ?>

  <div class="text-xs text-slate-500 mb-4 flex flex-wrap items-center gap-x-3 gap-y-1">
    <span>As of: <span class="font-medium text-slate-700"><?= ea_e($fa['as_of']) ?></span></span>
    <span>·</span>
    <span>Assets on record: <span class="font-medium text-slate-700"><?= number_format((int)$fa['asset_count']) ?></span></span>
    <span>·</span>
    <span>Currency: <span class="font-medium text-slate-700"><?= ea_e($cur) ?></span></span>
  </div>

  <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-8">
    <?php
      ea_render_partial('kpi_card', [
        'label'  => 'Gross Asset Value',
        'value'  => $money((float)$k['gross_value']),
        'sub'    => 'Original acquisition cost',
        'hint'   => 'Σ original_value on open/paused/closed assets',
        'accent' => 'indigo',
      ]);
      ea_render_partial('kpi_card', [
        'label'  => 'Net Book Value',
        'value'  => $money((float)$k['net_book_value']),
        'sub'    => 'Remaining carrying value',
        'hint'   => 'Σ value_residual on assets',
        'accent' => 'sky',
      ]);
      ea_render_partial('kpi_card', [
        'label'  => 'Accumulated Depreciation',
        'value'  => $money((float)$k['accumulated_depr']),
        'sub'    => number_format((float)$k['depreciation_ratio_pct'], 1) . '% of gross',
        'hint'   => '= Gross Value − Net Book Value',
        'accent' => 'violet',
      ]);
      ea_render_partial('kpi_card', [
        'label'  => 'Depreciation YTD',
        'value'  => $money((float)$k['depreciation_ytd']),
        'sub'    => 'P&amp;L charge year-to-date',
        'hint'   => 'GL entries in expense_depreciation accounts',
        'accent' => 'amber',
      ]);
      ea_render_partial('kpi_card', [
        'label'  => 'Active Assets',
        'value'  => number_format((int)$k['active_asset_count']),
        'sub'    => 'Running + paused · excludes disposed',
        'hint'   => 'state ∈ {open, paused}',
        'accent' => 'emerald',
      ]);
      ea_render_partial('kpi_card', [
        'label'  => 'Avg. Asset Age',
        'value'  => number_format((float)$k['avg_asset_age_years'], 1) . ' yrs',
        'sub'    => 'Weighted by original value',
        'hint'   => 'Portfolio freshness indicator',
        'accent' => ((float)$k['avg_asset_age_years'] > 8) ? 'amber' : 'sky',
      ]);
    ?>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
    <!-- By asset type -->
    <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
      <div class="mb-3">
        <h3 class="text-sm font-semibold text-slate-900">Portfolio by asset type</h3>
        <p class="text-xs text-slate-500">Gross value and net book value split</p>
      </div>
      <?php if (!empty($fa['by_type'])): ?>
        <table class="w-full text-sm">
          <thead>
            <tr class="text-[11px] uppercase tracking-wider text-slate-500 border-b border-slate-100">
              <th class="text-left font-medium py-2">Type</th>
              <th class="text-right font-medium py-2 w-16">#</th>
              <th class="text-right font-medium py-2">Gross</th>
              <th class="text-right font-medium py-2">NBV</th>
              <th class="text-right font-medium py-2 w-24">Depr %</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($fa['by_type'] as $type => $row):
              $dp = $row['gross'] > 0 ? (($row['gross'] - $row['nbv']) / $row['gross']) * 100 : 0;
            ?>
              <tr class="border-b border-slate-50 last:border-0">
                <td class="py-2 text-slate-800 capitalize"><?= ea_e($type) ?></td>
                <td class="py-2 text-right tabular-nums text-slate-600"><?= number_format((int)$row['count']) ?></td>
                <td class="py-2 text-right tabular-nums text-slate-800"><?= $money((float)$row['gross']) ?></td>
                <td class="py-2 text-right tabular-nums text-slate-800"><?= $money((float)$row['nbv']) ?></td>
                <td class="py-2 text-right tabular-nums text-slate-600"><?= number_format($dp, 1) ?>%</td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <p class="text-sm text-slate-500 py-4">No assets recorded.</p>
      <?php endif; ?>
    </div>

    <!-- Top assets -->
    <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
      <div class="mb-3">
        <h3 class="text-sm font-semibold text-slate-900">Top 10 assets by net book value</h3>
        <p class="text-xs text-slate-500">Largest remaining carrying amounts</p>
      </div>
      <?php if (!empty($fa['top_assets'])): ?>
        <div class="max-h-[380px] overflow-auto scrollbar-thin">
          <table class="w-full text-sm">
            <thead class="sticky top-0 bg-white">
              <tr class="text-[11px] uppercase tracking-wider text-slate-500 border-b border-slate-100">
                <th class="text-left font-medium py-2">Asset</th>
                <th class="text-right font-medium py-2">Gross</th>
                <th class="text-right font-medium py-2">NBV</th>
                <th class="text-right font-medium py-2 w-24">Depr %</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($fa['top_assets'] as $a): ?>
                <tr class="border-b border-slate-50 last:border-0">
                  <td class="py-2 text-slate-800 truncate max-w-[240px]" title="<?= ea_e($a['name']) ?>"><?= ea_e($a['name']) ?></td>
                  <td class="py-2 text-right tabular-nums text-slate-600"><?= $money((float)$a['gross']) ?></td>
                  <td class="py-2 text-right tabular-nums text-slate-800"><?= $money((float)$a['nbv']) ?></td>
                  <td class="py-2 text-right tabular-nums text-slate-600"><?= number_format((float)$a['depr_pct'], 1) ?>%</td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <p class="text-sm text-slate-500 py-4">No assets to show.</p>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php ea_render_partial('ai_advisors', ['page' => 'fixed_assets']); ?>

<script>
  document.getElementById('refreshBtn')?.addEventListener('click', () => location.reload());
</script>
