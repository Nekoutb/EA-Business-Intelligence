<?php
/** @var ?array $analysis */
/** @var ?string $error */
?>
<div class="flex flex-wrap items-start justify-between gap-4 mb-6">
  <div>
    <h2 class="text-lg font-semibold text-slate-900">VAT Compliance</h2>
    <p class="text-sm text-slate-500">2026 Tax Regime based on 2025 Trial Balance (December 31)</p>
  </div>
</div>

<?php if ($error): ?>
  <div class="bg-red-50 border border-red-200 text-red-700 rounded p-4 mb-6">
    <div class="font-medium mb-1">Could not load data from Odoo</div>
    <div class="text-sm"><?= ea_e($error) ?></div>
    <div class="text-sm mt-2">Go to <a href="/settings" class="underline">Settings</a> to verify your Odoo connection.</div>
  </div>
<?php endif; ?>

<?php if ($analysis): ?>
  <?php $cur = $analysis['currency']; ?>
  
  <!-- Summary Boxes -->
  <div class="flex gap-4 mb-6">
    <!-- Prior Year Turnover Box -->
    <div class="bg-gradient-to-br from-slate-50 to-slate-100 border-2 border-slate-300 rounded-lg p-3 shadow-sm flex-1">
      <p class="text-[10px] uppercase tracking-widest text-slate-600 font-semibold mb-1">Prior Year Turnover (2025)</p>
      <p class="text-[10px] text-slate-600 mb-2">From Trial Balance Accounts 70XXX</p>
      <p class="text-xl font-bold text-slate-900"><?= number_format($analysis['regime_threshold'] / 1_000_000, 1) ?> M <?= ea_e($cur) ?></p>
    </div>

    <!-- Current Year Tax Regime Box -->
    <div class="bg-gradient-to-br from-indigo-50 to-blue-50 border-2 border-indigo-300 rounded-lg p-3 shadow-sm flex-1">
      <p class="text-[10px] uppercase tracking-widest text-indigo-600 font-semibold mb-1">2026 Applicable Tax Regime</p>
      <p class="text-[10px] text-indigo-600 mb-2">Based on 2025 Annual Assessment</p>
      <p class="text-xl font-bold text-indigo-900"><?= ea_e($analysis['tax_regime']) ?></p>
    </div>
  </div>

  <!-- 52-Week Tax Calendar -->
  <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
    <h3 class="text-lg font-semibold text-slate-900 mb-3">2026 VAT Tax Calendar</h3>
    
    <!-- Legend -->
    <div class="flex gap-4 mb-3 text-xs">
      <div class="flex items-center gap-1">
        <div class="w-3 h-3 bg-yellow-300 border border-yellow-500"></div>
        <span class="text-slate-700">Due Date</span>
      </div>
      <div class="flex items-center gap-1">
        <div class="w-3 h-3 bg-slate-200 border border-slate-400"></div>
        <span class="text-slate-700">Regular Week</span>
      </div>
    </div>

    <!-- Calendar Rows -->
    <div class="space-y-1" style="font-size: 0.75rem;">
      <!-- Monthly VAT Return -->
      <div class="flex gap-2">
        <div class="w-48 flex-shrink-0 pr-2 border-r border-slate-300">
          <div class="font-semibold text-slate-900">Monthly VAT Return</div>
          <div class="text-xs text-slate-600">15th of M+1 (DGI portal)</div>
        </div>
        <div style="display: grid; grid-template-columns: repeat(52, 1fr); gap: 0.125rem; flex: 1;">
          <?php
            $vatMonthlyWeeks = [
              1 => 'Declaration & payment of Dec 2025 VAT collected (Régime du Réel)',
              5 => 'Declaration & payment of Jan 2026 VAT collected (Régime du Réel)',
              9 => 'Declaration & payment of Feb 2026 VAT collected (Régime du Réel)',
              13 => 'Declaration & payment of Mar 2026 VAT collected (Régime du Réel)',
              17 => 'Declaration & payment of Apr 2026 VAT collected (Régime du Réel)',
              21 => 'Declaration & payment of May 2026 VAT collected (Régime du Réel)',
              26 => 'Declaration & payment of Jun 2026 VAT collected (Régime du Réel)',
              30 => 'Declaration & payment of Jul 2026 VAT collected (Régime du Réel)',
              34 => 'Declaration & payment of Aug 2026 VAT collected (Régime du Réel)',
              39 => 'Declaration & payment of Sep 2026 VAT collected (Régime du Réel)',
              43 => 'Declaration & payment of Oct 2026 VAT collected (Régime du Réel)',
              48 => 'Declaration & payment of Nov 2026 VAT collected (Régime du Réel)',
            ];
            for ($w = 1; $w <= 52; $w++) {
              $isDue = isset($vatMonthlyWeeks[$w]);
              $bgClass = $isDue ? 'bg-yellow-300 border-yellow-500' : 'bg-slate-200 border-slate-400';
              $tooltip = $isDue ? $vatMonthlyWeeks[$w] : '';
              echo '<div class="relative group">';
              echo '<div class="' . $bgClass . ' border rounded h-6 flex items-center justify-center text-[9px] font-bold text-slate-900 cursor-help">W' . $w . '</div>';
              if ($tooltip) {
                echo '<div class="absolute bottom-full left-1/2 transform -translate-x-1/2 mb-2 hidden group-hover:block bg-slate-900 text-white text-[10px] rounded p-2 w-40 z-10 shadow-lg whitespace-normal">' . $tooltip . '</div>';
              }
              echo '</div>';
            }
          ?>
        </div>
      </div>

      <!-- Definitive Prorata -->
      <div class="flex gap-2">
        <div class="w-48 flex-shrink-0 pr-2 border-r border-slate-300">
          <div class="font-semibold text-slate-900">Definitive Prorata</div>
          <div class="text-xs text-slate-600">31 March (DGI portal)</div>
        </div>
        <div style="display: grid; grid-template-columns: repeat(52, 1fr); gap: 0.125rem; flex: 1;">
          <?php
            $prorataWeeks = [10 => 'Compute definitive prorata of VAT deduction for 2025'];
            for ($w = 1; $w <= 52; $w++) {
              $isDue = isset($prorataWeeks[$w]);
              $bgClass = $isDue ? 'bg-yellow-300 border-yellow-500' : 'bg-slate-200 border-slate-400';
              $tooltip = $isDue ? $prorataWeeks[$w] : '';
              echo '<div class="relative group">';
              echo '<div class="' . $bgClass . ' border rounded h-6 flex items-center justify-center text-[9px] font-bold text-slate-900 cursor-help">W' . $w . '</div>';
              if ($tooltip) {
                echo '<div class="absolute bottom-full left-1/2 transform -translate-x-1/2 mb-2 hidden group-hover:block bg-slate-900 text-white text-[10px] rounded p-2 w-40 z-10 shadow-lg whitespace-normal">' . $tooltip . '</div>';
              }
              echo '</div>';
            }
          ?>
        </div>
      </div>

      <!-- Quarterly VAT Refund -->
      <div class="flex gap-2">
        <div class="w-48 flex-shrink-0 pr-2 border-r border-slate-300">
          <div class="font-semibold text-slate-900">Quarterly VAT Refund</div>
          <div class="text-xs text-slate-600">End of Q (if applicable)</div>
        </div>
        <div style="display: grid; grid-template-columns: repeat(52, 1fr); gap: 0.125rem; flex: 1;">
          <?php
            $quarterlyWeeks = [
              13 => 'End of Q1 - Quarterly VAT refund claim (if applicable)',
              26 => 'End of Q2 - Quarterly VAT refund claim (if applicable)',
              39 => 'End of Q3 - Quarterly VAT refund claim (if applicable)',
              52 => 'End of Q4 - Quarterly VAT refund claim (if applicable)',
            ];
            for ($w = 1; $w <= 52; $w++) {
              $isDue = isset($quarterlyWeeks[$w]);
              $bgClass = $isDue ? 'bg-yellow-300 border-yellow-500' : 'bg-slate-200 border-slate-400';
              $tooltip = $isDue ? $quarterlyWeeks[$w] : '';
              echo '<div class="relative group">';
              echo '<div class="' . $bgClass . ' border rounded h-6 flex items-center justify-center text-[9px] font-bold text-slate-900 cursor-help">W' . $w . '</div>';
              if ($tooltip) {
                echo '<div class="absolute bottom-full left-1/2 transform -translate-x-1/2 mb-2 hidden group-hover:block bg-slate-900 text-white text-[10px] rounded p-2 w-40 z-10 shadow-lg whitespace-normal">' . $tooltip . '</div>';
              }
              echo '</div>';
            }
          ?>
        </div>
      </div>

      <!-- Year-End Balance Test -->
      <div class="flex gap-2">
        <div class="w-48 flex-shrink-0 pr-2 border-r border-slate-300">
          <div class="font-semibold text-slate-900">Year-End Balance Test</div>
          <div class="text-xs text-slate-600">31 December (Internal)</div>
        </div>
        <div style="display: grid; grid-template-columns: repeat(52, 1fr); gap: 0.125rem; flex: 1;">
          <?php
            $yearEndWeeks = [52 => 'Year-end balance test on accounts 4431/4432/4433/4434'];
            for ($w = 1; $w <= 52; $w++) {
              $isDue = isset($yearEndWeeks[$w]);
              $bgClass = $isDue ? 'bg-yellow-300 border-yellow-500' : 'bg-slate-200 border-slate-400';
              $tooltip = $isDue ? $yearEndWeeks[$w] : '';
              echo '<div class="relative group">';
              echo '<div class="' . $bgClass . ' border rounded h-6 flex items-center justify-center text-[9px] font-bold text-slate-900 cursor-help">W' . $w . '</div>';
              if ($tooltip) {
                echo '<div class="absolute bottom-full left-1/2 transform -translate-x-1/2 mb-2 hidden group-hover:block bg-slate-900 text-white text-[10px] rounded p-2 w-40 z-10 shadow-lg whitespace-normal">' . $tooltip . '</div>';
              }
              echo '</div>';
            }
          ?>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>

<script>
  document.getElementById('refreshBtn')?.addEventListener('click', () => location.reload());
</script>
