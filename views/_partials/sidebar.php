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

    <div class="pt-4 pb-1 px-3 text-[10px] font-semibold text-slate-500 uppercase tracking-wider">Analysis</div>

    <a href="/revenue" class="<?= $navClass('revenue') ?>">
      <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 15l4-6 4 3 5-8"/></svg>
      Revenue
    </a>

    <a href="/receivables" class="<?= $navClass('receivables') ?>">
      <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 12h.01M18 12h.01"/></svg>
      Receivables
    </a>

    <a href="/profit-loss" class="<?= $navClass('profit_loss') ?>">
      <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M5 9h9a3 3 0 0 1 0 6H7a3 3 0 0 0 0 6h12"/></svg>
      Profit &amp; Loss
    </a>

    <a href="/payables" class="<?= $navClass('payables') ?>">
      <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M3 12h18M3 18h18"/><path d="M7 3v18"/></svg>
      Payables
    </a>

    <a href="/fixed-assets" class="<?= $navClass('fixed_assets') ?>">
      <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V7l7-4 7 4v14"/><path d="M9 21v-6h6v6"/></svg>
      Fixed Assets
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
