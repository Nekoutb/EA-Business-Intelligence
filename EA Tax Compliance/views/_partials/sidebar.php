<?php
/** @var string $activeNav */
$activeNav = $activeNav ?? 'dashboard';
$navClass = function (string $item) use ($activeNav): string {
    $base = 'group flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition-colors';
    return $item === $activeNav
        ? $base . ' bg-indigo-600 text-white shadow-sm'
        : $base . ' text-slate-300 hover:bg-slate-800 hover:text-white';
};
?>
<aside class="w-64 bg-slate-900 text-slate-200 flex flex-col min-h-screen">
  <?php
    $logoUrl = ea_logo_url();
    $brand   = (string) \EA\BI\Config::get('APP_BRAND', 'EA Business Intelligence');
  ?>
  <div class="px-5 py-5 border-b border-slate-800">
    <a href="/" class="flex items-center gap-2">
      <?php if ($logoUrl): ?>
        <img src="<?= ea_e($logoUrl) ?>" alt="Logo" class="w-10 h-10 object-contain rounded bg-white p-1">
      <?php else: ?>
        <span class="inline-flex items-center justify-center w-9 h-9 rounded-lg bg-indigo-600 text-white font-semibold">EA</span>
      <?php endif; ?>
      <div class="leading-tight min-w-0">
        <div class="font-semibold text-white text-sm truncate" title="<?= ea_e($brand) ?>"><?= ea_e($brand) ?></div>
        <div class="text-[11px] text-slate-400">Real-time insights</div>
      </div>
    </a>
  </div>

  <nav class="flex-1 px-3 py-4 space-y-1">
    <a href="/" class="<?= $navClass('dashboard') ?>">
      <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/></svg>
      Dashboard
    </a>


    <div class="pt-4 pb-1 px-3 text-[10px] font-semibold text-slate-500 uppercase tracking-wider">Tax Compliance</div>

    <a href="/vat-compliance-dashboard" class="<?= $navClass('vat-compliance-dashboard') ?>">
      <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 15l4-6 4 3 5-8"/></svg>
      VAT Compliance Dashboard
    </a>

    <a href="/vat-invoice-compliance" class="<?= $navClass('vat-invoice-compliance') ?>">
      <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>
      VAT Invoice Compliance
    </a>
  </nav>

  <div class="p-4 border-t border-slate-800">
    <a href="/settings" class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm text-slate-300 hover:bg-slate-800 hover:text-white">
      <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h0a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h0a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v0a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
      Settings
    </a>
    <a href="/logout" class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm text-slate-300 hover:bg-slate-800 hover:text-red-300">
      <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      Logout
    </a>
  </div>
</aside>
