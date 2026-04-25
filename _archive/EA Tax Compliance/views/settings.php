<?php
/** @var array $config */
/** @var ?array $flash */
$val = fn(string $k, string $d = '') => ea_e((string) ($config[$k] ?? $d));
$protocol = $config['ODOO_PROTOCOL'] ?? 'json2';
$source   = $config['REVENUE_SOURCE'] ?? 'invoice';
?>
<div class="max-w-3xl">
  <h1 class="text-2xl font-semibold text-slate-900 mb-1">Odoo Connection Settings</h1>
  <p class="text-sm text-slate-500 mb-6">Configure how this dashboard connects to your Odoo instance.</p>

  <?php if ($flash): ?>
    <div class="mb-4 text-sm px-3 py-2 rounded border
      <?= $flash['type'] === 'success' ? 'bg-emerald-50 border-emerald-200 text-emerald-700' : 'bg-red-50 border-red-200 text-red-700' ?>">
      <?= ea_e($flash['msg']) ?>
    </div>
  <?php endif; ?>

  <form method="post" action="/settings" enctype="multipart/form-data" class="bg-white border border-slate-200 rounded-xl p-6 shadow-sm space-y-4">
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <div class="md:col-span-2">
        <label class="block text-xs font-medium text-slate-600 mb-1">Odoo URL</label>
        <input name="ODOO_URL" value="<?= $val('ODOO_URL') ?>" placeholder="https://mycompany.odoo.com"
               class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
      </div>
      <div>
        <label class="block text-xs font-medium text-slate-600 mb-1">Database</label>
        <input name="ODOO_DB" value="<?= $val('ODOO_DB') ?>"
               class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
      </div>
      <div>
        <label class="block text-xs font-medium text-slate-600 mb-1">Username / Email</label>
        <input name="ODOO_USERNAME" value="<?= $val('ODOO_USERNAME') ?>"
               class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
      </div>
      <div class="md:col-span-2">
        <label class="block text-xs font-medium text-slate-600 mb-1">API Key
          <span class="text-slate-400 font-normal">(leave blank to keep current)</span>
        </label>
        <input name="ODOO_API_KEY" type="password" placeholder="••••••••••••"
               class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
      </div>
      <div>
        <label class="block text-xs font-medium text-slate-600 mb-1">Protocol</label>
        <select name="ODOO_PROTOCOL" class="w-full border border-slate-300 rounded px-3 py-2 text-sm bg-white">
          <option value="json2"   <?= $protocol === 'json2'   ? 'selected' : '' ?>>JSON-2 (Odoo 19+, recommended)</option>
          <option value="jsonrpc" <?= $protocol === 'jsonrpc' ? 'selected' : '' ?>>JSON-RPC (Odoo 13-19, deprecated in 19)</option>
          <option value="xmlrpc"  <?= $protocol === 'xmlrpc'  ? 'selected' : '' ?>>XML-RPC (Odoo 13-19, deprecated in 19)</option>
        </select>
      </div>
      <div>
        <label class="block text-xs font-medium text-slate-600 mb-1">Revenue Source</label>
        <select name="REVENUE_SOURCE" class="w-full border border-slate-300 rounded px-3 py-2 text-sm bg-white">
          <option value="invoice" <?= $source === 'invoice' ? 'selected' : '' ?>>Posted Customer Invoices (account.move)</option>
          <option value="sale"    <?= $source === 'sale'    ? 'selected' : '' ?>>Confirmed Sales Orders (sale.order)</option>
          <option value="pos"     <?= $source === 'pos'     ? 'selected' : '' ?>>Point-of-Sale Orders (pos.order)</option>
        </select>
      </div>
    </div>

    <div class="border-t border-slate-100 pt-5 mt-2">
      <h2 class="text-sm font-semibold text-slate-900 mb-1">Appearance</h2>
      <p class="text-xs text-slate-500 mb-4">Customize the branding shown in the sidebar and login page.</p>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <label class="block text-xs font-medium text-slate-600 mb-1">Brand name</label>
          <input name="APP_BRAND" value="<?= $val('APP_BRAND', 'EA Business Intelligence') ?>" placeholder="Your company name"
                 class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
        </div>
        <div>
          <label class="block text-xs font-medium text-slate-600 mb-1">Company logo
            <span class="text-slate-400 font-normal">(PNG / JPG / SVG / WEBP, max 2 MB)</span>
          </label>
          <div class="flex items-center gap-3">
            <?php $logoUrl = ea_logo_url(); ?>
            <?php if ($logoUrl): ?>
              <img src="<?= ea_e($logoUrl) ?>" alt="Current logo" class="w-12 h-12 object-contain bg-slate-50 border border-slate-200 rounded">
            <?php else: ?>
              <div class="w-12 h-12 flex items-center justify-center text-[10px] text-slate-400 bg-slate-50 border border-dashed border-slate-300 rounded">none</div>
            <?php endif; ?>
            <input type="file" name="logo" accept="image/png,image/jpeg,image/webp,image/svg+xml,image/gif"
                   class="text-sm text-slate-700 file:mr-3 file:px-3 file:py-1.5 file:rounded file:border-0 file:bg-slate-100 file:text-slate-700 file:text-xs hover:file:bg-slate-200">
          </div>
          <?php if ($logoUrl): ?>
            <label class="mt-2 inline-flex items-center gap-2 text-xs text-slate-500">
              <input type="checkbox" name="remove_logo" value="1" class="rounded border-slate-300">
              Remove current logo on save
            </label>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div id="ai" class="border-t border-slate-100 pt-5 mt-2">
      <h2 class="text-sm font-semibold text-slate-900 mb-1">Elite Advisors (AI Insights)</h2>
      <p class="text-xs text-slate-500 mb-4">
        Choose the LLM backend that powers the on-page <em>Elite Advisors</em> sections.
        These settings are never exposed to end-users, letting you swap providers
        purely to optimise cost.
      </p>
      <?php
        $aiProvider = $config['AI_PROVIDER'] ?? 'anthropic';
        $aiModel    = $config['AI_MODEL']    ?? '';
        $aiKey      = $config['AI_API_KEY']  ?? '';
      ?>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <label class="block text-xs font-medium text-slate-600 mb-1">Provider</label>
          <select name="AI_PROVIDER" class="w-full border border-slate-300 rounded px-3 py-2 text-sm bg-white">
            <option value="anthropic" <?= $aiProvider === 'anthropic' ? 'selected' : '' ?>>Anthropic (Claude — default)</option>
            <option value="openai"    <?= $aiProvider === 'openai'    ? 'selected' : '' ?>>OpenAI (GPT-4o family)</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-slate-600 mb-1">Model
            <span class="text-slate-400 font-normal">(blank = use provider default)</span>
          </label>
          <input name="AI_MODEL" value="<?= ea_e($aiModel) ?>"
                 placeholder="claude-sonnet-4-5-20250929 or gpt-4o-mini"
                 class="w-full border border-slate-300 rounded px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500">
        </div>
        <div class="md:col-span-2">
          <label class="block text-xs font-medium text-slate-600 mb-1">API Key
            <span class="text-slate-400 font-normal">(leave blank to keep current)</span>
          </label>
          <input name="AI_API_KEY" type="password"
                 placeholder="<?= $aiKey !== '' ? '•••••••••••••••••• (set)' : 'sk-ant-... or sk-...' ?>"
                 class="w-full border border-slate-300 rounded px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500">
          <p class="text-[11px] text-slate-400 mt-1">
            Stored locally in <span class="font-mono">.env</span> and never sent to the browser.
            Defaults: Anthropic → <span class="font-mono">claude-sonnet-4-5-20250929</span>;
            OpenAI → <span class="font-mono">gpt-4o-mini</span>.
          </p>
        </div>
      </div>
    </div>

    <div class="flex items-center gap-3 pt-2">
      <button class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded px-4 py-2">Save</button>
      <button type="button" id="testBtn" class="border border-slate-300 hover:border-indigo-400 hover:text-indigo-600 text-sm rounded px-4 py-2">Test connection</button>
      <span id="testResult" class="text-sm"></span>
    </div>
  </form>

  <div class="mt-6 bg-white border border-slate-200 rounded-xl p-6 shadow-sm">
    <h2 class="text-sm font-semibold text-slate-900 mb-1">Data synchronization</h2>
    <p class="text-xs text-slate-500 mb-3">
      Dashboards cache the last Odoo pull for 5 minutes to keep navigation fast.
      Press the <span class="font-medium">Sync now</span> button in the top bar on any page, or click below, to force an immediate refresh.
    </p>
    <div class="flex items-center gap-3">
      <form method="post" action="/sync">
        <input type="hidden" name="return" value="/settings">
        <button class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded px-4 py-2 inline-flex items-center gap-2">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10"/><path d="M20.49 15a9 9 0 0 1-14.85 3.36L1 14"/></svg>
          Sync now
        </button>
      </form>
      <span class="text-xs text-slate-500">Last synced <span class="font-medium text-slate-700"><?= ea_e(ea_last_sync_label()) ?></span></span>
    </div>
  </div>

  <p class="text-xs text-slate-400 mt-4">
    Tip: create a dedicated read-only user in Odoo and generate an API key from <em>Preferences &rarr; Account Security</em>.
  </p>
</div>

<script>
  document.getElementById('testBtn')?.addEventListener('click', async () => {
    const el = document.getElementById('testResult');
    el.textContent = 'Testing…';
    el.className = 'text-sm text-slate-500';
    try {
      const r = await fetch('/settings/test', { method: 'POST' });
      const j = await r.json();
      if (j.ok) {
        el.textContent = '✓ Connected (uid=' + j.uid + ')';
        el.className = 'text-sm text-emerald-600';
      } else {
        el.textContent = '✗ ' + (j.error || 'Failed');
        el.className = 'text-sm text-red-600';
      }
    } catch (e) {
      el.textContent = '✗ ' + e.message;
      el.className = 'text-sm text-red-600';
    }
  });
</script>
