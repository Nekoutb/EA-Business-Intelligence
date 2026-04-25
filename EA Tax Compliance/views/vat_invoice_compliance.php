<?php
/** @var ?array $analysis */
/** @var ?array $period */
/** @var ?string $error */
?>
<div class="flex flex-wrap items-start justify-between gap-4 mb-6">
  <div>
    <h2 class="text-lg font-semibold text-slate-900">VAT Invoice Compliance</h2>
    <p class="text-sm text-slate-500">Conditions de fond & forme — Cameroon CGI compliance checks</p>
  </div>
</div>

<?php ea_render_partial('period_filter', [
  'action'     => '/vat-invoice-compliance',
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
  <?php $invoice = $analysis['invoice'] ?? null; ?>
  <?php if ($invoice): ?>
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
      <div class="bg-white border border-slate-200 rounded-lg p-4 shadow-sm">
        <p class="text-xs uppercase tracking-wider text-slate-500 font-medium mb-1">Invoice #</p>
        <p class="text-2xl font-bold text-slate-900"><?= ea_e($invoice['number']) ?></p>
      </div>
      <div class="bg-indigo-50 border border-indigo-200 rounded-lg p-4 shadow-sm">
        <p class="text-xs uppercase tracking-wider text-indigo-600 font-medium mb-1">Customer</p>
        <p class="text-2xl font-bold text-indigo-900"><?= ea_e($invoice['customer']) ?></p>
      </div>
      <div class="bg-slate-50 border border-slate-200 rounded-lg p-4 shadow-sm">
        <p class="text-xs uppercase tracking-wider text-slate-500 font-medium mb-1">Invoice Date</p>
        <p class="text-2xl font-bold text-slate-900"><?= ea_e($invoice['date']) ?></p>
      </div>
      <div class="bg-slate-900 text-white rounded-lg p-4 shadow-sm">
        <p class="text-xs uppercase tracking-wider text-white/70 font-medium mb-1">VAT Rate</p>
        <p class="text-2xl font-bold"><?= $invoice['vat_rate'] !== null ? number_format($invoice['vat_rate'], 2) . ' %' : '—' ?></p>
      </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6 text-sm">
      <div class="bg-white border border-slate-200 rounded-lg p-3 shadow-sm">
        <p class="text-xs uppercase tracking-wider text-slate-500 font-medium mb-1">Price HT</p>
        <p class="text-lg font-semibold text-slate-900"><?= number_format((float) $invoice['amount_untaxed'], 0, ',', ' ') ?> XAF</p>
      </div>
      <div class="bg-white border border-slate-200 rounded-lg p-3 shadow-sm">
        <p class="text-xs uppercase tracking-wider text-slate-500 font-medium mb-1">VAT Amount</p>
        <p class="text-lg font-semibold text-slate-900"><?= number_format((float) $invoice['vat_amount'], 0, ',', ' ') ?> XAF</p>
      </div>
      <div class="bg-white border border-slate-200 rounded-lg p-3 shadow-sm">
        <p class="text-xs uppercase tracking-wider text-slate-500 font-medium mb-1">Total TTC</p>
        <p class="text-lg font-semibold text-slate-900"><?= number_format((float) $invoice['amount_total'], 0, ',', ' ') ?> XAF</p>
      </div>
    </div>

    <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
      <h3 class="text-sm font-semibold text-slate-900 mb-3">Invoice Field Verification</h3>
      <div class="overflow-x-auto">
        <table class="w-full text-xs">
          <thead>
            <tr class="text-[11px] uppercase tracking-wider text-slate-500 border-b border-slate-100">
              <th class="text-left font-medium py-2">Field</th>
              <th class="text-left font-medium py-2">Value</th>
              <th class="text-center font-medium py-2">Status</th>
              <th class="text-left font-medium py-2">Reference</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($analysis['fields'] as $field): ?>
              <?php
                $statusClass = $field['status'] === 'pass'
                    ? 'bg-emerald-100 text-emerald-800'
                    : 'bg-red-100 text-red-800';
                $statusLabel = $field['status'] === 'pass' ? 'Present' : 'Missing';
              ?>
              <tr class="border-b border-slate-50 last:border-0 hover:bg-slate-50">
                <td class="py-3 font-medium text-slate-900"><?= ea_e($field['label']) ?></td>
                <td class="py-3 text-slate-700"><?= ea_e($field['value']) ?></td>
                <td class="py-3 text-center">
                  <span class="inline-block px-2 py-1 rounded text-[10px] font-semibold <?= $statusClass ?>"><?= $statusLabel ?></span>
                </td>
                <td class="py-3 text-slate-500"><?= ea_e($field['reference']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php else: ?>
    <div class="bg-yellow-50 border border-yellow-200 text-yellow-800 rounded-lg p-4 shadow-sm">
      <?= ea_e($analysis['note'] ?? 'No invoice was found for the selected period.'); ?>
    </div>
  <?php endif; ?>
<?php endif; ?>

<script>
  document.getElementById('refreshBtn')?.addEventListener('click', () => location.reload());
</script>
