<?php
/** @var string $content */
/** @var string|null $activeNav */
/** @var string|null $pageTitle */
$activeNav = $activeNav ?? 'dashboard';
$pageTitle = $pageTitle ?? 'Dashboard';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= ea_e($pageTitle) ?> · EA Business Intelligence</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <style>
    body { font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
    .scrollbar-thin::-webkit-scrollbar { width: 8px; height: 8px; }
    .scrollbar-thin::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
  </style>
</head>
<body class="bg-slate-50 text-slate-800">
  <?php if (ea_is_logged_in()): ?>
  <div class="flex min-h-screen">
    <?php include __DIR__ . '/_partials/sidebar.php'; ?>

    <div class="flex-1 flex flex-col min-w-0">
      <header class="bg-white border-b border-slate-200 px-8 py-4 flex items-center justify-between gap-4">
        <div class="min-w-0">
          <h1 class="text-xl font-semibold text-slate-900 truncate"><?= ea_e($pageTitle) ?></h1>
          <p class="text-xs text-slate-500">Real-time insights from Odoo · last synced <?= ea_e(ea_last_sync_label()) ?></p>
        </div>
        <div class="flex items-center gap-4">
          <form method="post" action="/sync" class="inline">
            <input type="hidden" name="return" value="<?= ea_e($_SERVER['REQUEST_URI'] ?? '/') ?>">
            <button type="submit" title="Force a fresh data pull from Odoo"
                    class="inline-flex items-center gap-2 text-sm bg-indigo-600 hover:bg-indigo-700 text-white font-medium rounded-lg px-3 py-2 shadow-sm">
              <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10"/><path d="M20.49 15a9 9 0 0 1-14.85 3.36L1 14"/></svg>
              Sync now
            </button>
          </form>
          <div class="text-xs text-slate-500 hidden sm:block">
            Signed in as <span class="font-medium text-slate-700"><?= ea_e($_SESSION['user'] ?? '') ?></span>
          </div>
        </div>
      </header>

      <main class="flex-1 overflow-auto scrollbar-thin px-8 py-8">
        <?= $content ?>
      </main>

      <footer class="px-8 py-4 text-xs text-slate-400 border-t border-slate-200 bg-white">
        EA Business Intelligence · Real-time Odoo insights
      </footer>
    </div>
  </div>
  <?php else: ?>
    <main class="max-w-7xl mx-auto px-6 py-8"><?= $content ?></main>
  <?php endif; ?>
</body>
</html>
