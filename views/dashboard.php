<?php
/** @var ?array $analysis */
/** @var ?array $pl */
/** @var ?string $error */
?>
<div class="mb-6">
  <h2 class="text-lg font-semibold text-slate-900">Executive dashboard</h2>
  <p class="text-sm text-slate-500">Seven revenue KPIs and two profit &amp; loss KPIs · current year vs prior year</p>
</div>

<?php if ($error): ?>
  <div class="bg-red-50 border border-red-200 text-red-700 rounded p-4 mb-6">
    <div class="font-medium mb-1">Could not load data from Odoo</div>
    <div class="text-sm"><?= ea_e($error) ?></div>
    <div class="text-sm mt-2">Go to <a href="/settings" class="underline">Settings</a> to verify your Odoo connection.</div>
  </div>
<?php endif; ?>

<?php if ($analysis): ?>
  <?php ea_render_partial('revenue_kpis', ['analysis' => $analysis, 'chartId' => 'dashTrendChart', 'hideChart' => true]); ?>
<?php endif; ?>

<?php if ($pl): ?>
  <?php
    $cur = $pl['currency'];
    $c   = $pl['current'];
    $d   = $pl['delta'];
    $pp  = fn(float $pp): string => ($pp >= 0 ? '+' : '') . number_format($pp, 1) . ' pp';
  ?>
  <div class="mt-2 mb-3">
    <h3 class="text-sm font-semibold text-slate-900">Profit &amp; Loss snapshot</h3>
    <p class="text-xs text-slate-500">Two headline margins from the general ledger · <a href="/profit-loss" class="text-indigo-600 hover:underline">full P&amp;L →</a></p>
  </div>
  <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-8">
    <?php
      ea_render_partial('kpi_card', [
        'label'    => 'Gross Margin',
        'value'    => number_format((float)$c['gross_margin_pct'], 1) . '%',
        'deltaPct' => null,
        'sub'      => 'YoY change <span class="font-medium text-slate-700">' . ea_e($pp((float)$d['gross_margin_pp'])) . '</span>',
        'hint'     => '= (Revenue − COGS) / Revenue',
        'accent'   => ((float)$c['gross_margin_pct'] >= 0) ? 'emerald' : 'rose',
      ]);
      ea_render_partial('kpi_card', [
        'label'    => 'EBITDA Margin',
        'value'    => number_format((float)$c['ebitda_margin_pct'], 1) . '%',
        'sub'      => 'YoY change <span class="font-medium text-slate-700">' . ea_e($pp((float)$d['ebitda_margin_pp'])) . '</span>',
        'hint'     => '= EBITDA / Revenue',
        'accent'   => ((float)$c['ebitda_margin_pct'] >= 0) ? 'sky' : 'rose',
      ]);
    ?>
  </div>
<?php endif; ?>

<?php ea_render_partial('ai_advisors', ['page' => 'dashboard']); ?>
