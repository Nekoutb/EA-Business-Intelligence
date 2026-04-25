<?php /** @var ?string $error */ ?>
<div class="min-h-[70vh] flex items-center justify-center">
  <div class="w-full max-w-sm bg-white border border-slate-200 rounded-xl shadow-sm p-8">
    <div class="flex items-center gap-2 mb-6">
      <span class="inline-block w-8 h-8 rounded bg-indigo-600 text-white flex items-center justify-center font-semibold">EA</span>
      <h1 class="text-xl font-semibold text-slate-900">Business Intelligence</h1>
    </div>
    <p class="text-sm text-slate-500 mb-6">Sign in to access your Odoo insights.</p>

    <?php if (!empty($error)): ?>
      <div class="mb-4 text-sm bg-red-50 border border-red-200 text-red-700 px-3 py-2 rounded"><?= ea_e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="/login" class="space-y-4">
      <div>
        <label class="block text-xs font-medium text-slate-600 mb-1">Username</label>
        <input name="username" class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" autocomplete="username" required>
      </div>
      <div>
        <label class="block text-xs font-medium text-slate-600 mb-1">Password</label>
        <input name="password" type="password" class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" autocomplete="current-password" required>
      </div>
      <button class="w-full bg-indigo-600 hover:bg-indigo-700 text-white rounded px-3 py-2 text-sm font-medium">Sign in</button>
    </form>
  </div>
</div>
