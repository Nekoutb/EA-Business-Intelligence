@extends('layouts.app')
@section('title', 'Settings')
@section('page-title', 'Settings')

@section('content')
<div class="max-w-3xl">
    <p class="text-xs text-slate-500 mb-4">Per-workspace configuration: company identity, Odoo connection, budget benchmarks. Secrets are encrypted at rest.</p>
    <form method="POST" action="{{ tenant_url('/settings') }}" enctype="multipart/form-data" class="space-y-6">
        @csrf

        {{-- ── Odoo connection ────────────────────────────────────────── --}}
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-6">
            <div class="flex items-center justify-between mb-1">
                <h2 class="text-sm font-semibold text-slate-900">Odoo Connection</h2>
                <button type="button" id="ea-test-odoo-btn"
                        class="inline-flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider text-indigo-700 border border-indigo-300 hover:border-indigo-500 bg-indigo-50/40 hover:bg-indigo-50 rounded-md px-3 py-1.5 transition disabled:opacity-50 disabled:cursor-not-allowed">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    <span data-label>Test connection</span>
                </button>
            </div>
            <p class="text-xs text-slate-500 mb-4">Credentials used to pull data from your Odoo instance.</p>

            {{-- Test result panel — populated by JS --}}
            <div id="ea-test-odoo-result" class="hidden mb-4 px-3 py-2.5 rounded-lg text-[12px] leading-relaxed border" role="status"></div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="md:col-span-2">
                    <label class="block text-xs font-medium text-slate-600 mb-1">Odoo URL</label>
                    <input name="ODOO_URL" value="{{ $values['ODOO_URL'] }}" placeholder="https://mycompany.odoo.com"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Database</label>
                    <input name="ODOO_DB" value="{{ $values['ODOO_DB'] }}" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Username</label>
                    <input name="ODOO_USERNAME" value="{{ $values['ODOO_USERNAME'] }}" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-xs font-medium text-slate-600 mb-1">
                        API Key <span class="text-slate-400 font-normal">(leave blank to keep current)</span>
                    </label>
                    <input name="ODOO_API_KEY" type="password"
                           placeholder="{{ !empty($values['__has_ODOO_API_KEY']) ? '•••• stored (leave blank to keep)' : '••••••••••••' }}"
                           autocomplete="off"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm font-mono">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Protocol</label>
                    <select name="ODOO_PROTOCOL" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white">
                        @foreach (['json2' => 'JSON-2 (Odoo 19+, recommended)', 'jsonrpc' => 'JSON-RPC', 'xmlrpc' => 'XML-RPC'] as $v => $l)
                          <option value="{{ $v }}" {{ $values['ODOO_PROTOCOL'] === $v ? 'selected' : '' }}>{{ $l }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Revenue Source</label>
                    <select name="REVENUE_SOURCE" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white">
                        @foreach (['invoice' => 'Customer Invoices', 'sale' => 'Sales Orders', 'pos' => 'POS Orders'] as $v => $l)
                          <option value="{{ $v }}" {{ $values['REVENUE_SOURCE'] === $v ? 'selected' : '' }}>{{ $l }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>

        {{-- ── Users link (visible to everyone; mutating routes
             gated to tenant admins by RequireTenantAdmin middleware) --}}
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-5 flex items-center justify-between gap-3">
            <div>
                <h2 class="text-sm font-semibold text-slate-900">Workspace Users</h2>
                <p class="text-xs text-slate-500 mt-0.5">Manage who can access this workspace, what role they have, and per-user permission overrides for services / modules / KPIs.</p>
            </div>
            <a href="{{ tenant_url('/settings/users') }}" class="text-[11px] font-semibold text-white bg-brand-600 hover:bg-brand-700 rounded px-3 py-2 flex-shrink-0">
                Manage users →
            </a>
        </div>

        {{-- ── Company identity ─────────────────────────────────────────
             Drives the sidebar brand block and the company badge in the
             top-right of every page. Logo file is stored under
             public/uploads/tenant-logos/ and served via the web server
             (no Laravel storage symlink needed).                      --}}
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-6">
            <h2 class="text-sm font-semibold text-slate-900 mb-1">Company Identity</h2>
            <p class="text-xs text-slate-500 mb-4">Logo and names shown in the sidebar (top-left) and the company badge (top-right of every page).</p>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                {{-- Logo column --}}
                <div class="md:col-span-1">
                    <label class="block text-xs font-medium text-slate-600 mb-1">Company Logo</label>
                    <div class="border border-dashed border-slate-300 rounded-lg p-3 bg-slate-50">
                        <div class="flex items-center gap-3 mb-3">
                            <div class="w-14 h-14 rounded-lg bg-gradient-to-br from-brand-500 to-brand-700 flex items-center justify-center text-white font-bold text-xl overflow-hidden flex-shrink-0">
                                @if (tenant_logo_url())
                                  <img src="{{ tenant_logo_url() }}" alt="" class="w-full h-full object-contain bg-white">
                                @else
                                  {{ tenant_brand_initial() }}
                                @endif
                            </div>
                            <div class="text-[11px] text-slate-600 leading-tight">
                                @if (tenant_logo_url())
                                  Current logo<br>
                                  <a href="{{ tenant_logo_url() }}" target="_blank" class="text-brand-600 underline">view file</a>
                                @else
                                  No logo set.<br>Letter avatar is used.
                                @endif
                            </div>
                        </div>
                        <input type="file" name="logo" accept="image/png,image/jpeg,image/svg+xml,image/webp"
                               class="w-full text-[11px] text-slate-700 file:bg-brand-600 file:text-white file:border-0 file:rounded file:px-2 file:py-1 file:text-[11px] file:font-semibold file:cursor-pointer file:mr-2 cursor-pointer">
                        <p class="text-[10px] text-slate-500 mt-2">PNG, JPG, SVG or WebP · max 2 MB. Square or near-square works best.</p>
                        @if (tenant_logo_url())
                          <label class="flex items-center gap-1.5 mt-2 text-[11px] text-rose-700 cursor-pointer">
                              <input type="checkbox" name="remove_logo" value="1" class="rounded border-rose-300">
                              Remove current logo on save
                          </label>
                        @endif
                    </div>
                </div>

                {{-- Name fields --}}
                <div class="md:col-span-2 grid grid-cols-1 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">
                            ETP <span class="text-slate-400 font-normal">· short name shown next to the logo</span>
                        </label>
                        <input name="TENANT_BRAND" value="{{ optional(tenant())->brand }}" placeholder="e.g. TADSA"
                               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">
                            Full Company Name <span class="text-slate-400 font-normal">· shown beneath the ETP</span>
                        </label>
                        <input name="TENANT_NAME" value="{{ optional(tenant())->name }}" placeholder="e.g. Transatlantic D S.A."
                               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">
                            Suite Brand <span class="text-slate-400 font-normal">· legacy fallback (login page, emails)</span>
                        </label>
                        <input name="APP_BRAND" value="{{ $values['APP_BRAND'] }}" placeholder="Your company name"
                               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
                    </div>
                </div>
            </div>
        </div>

        {{-- ── Budget Benchmarks ───────────────────────────────────────── --}}
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-6">
            <h2 class="text-sm font-semibold text-slate-900 mb-1">Budget Benchmarks</h2>
            <p class="text-xs text-slate-500 mb-4">Monthly targets used for budget overlays on KPIs &amp; charts.</p>

            {{-- Volume / revenue line items --}}
            <div class="mb-5">
                <h3 class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-2">Volume &amp; Revenue</h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Monthly Revenue Target</label>
                        <div class="relative">
                            <span class="absolute left-3 top-2.5 text-xs text-slate-400">XAF</span>
                            <input name="BUDGET_MONTHLY_REVENUE" value="{{ $values['BUDGET_MONTHLY_REVENUE'] ?? '' }}"
                                   class="w-full border border-slate-300 rounded-lg pl-12 pr-3 py-2 text-sm" placeholder="8 187 000">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Monthly Scanned Revenue Target <span class="text-slate-400 font-normal">(F1)</span></label>
                        <div class="relative">
                            <span class="absolute left-3 top-2.5 text-xs text-slate-400">XAF</span>
                            <input name="BUDGET_MONTHLY_SCANNED_REVENUE" value="{{ $values['BUDGET_MONTHLY_SCANNED_REVENUE'] ?? '' }}"
                                   class="w-full border border-slate-300 rounded-lg pl-12 pr-3 py-2 text-sm" placeholder="6 500 000">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Monthly Outfits</label>
                        <input name="BUDGET_MONTHLY_OUTFITS" value="{{ $values['BUDGET_MONTHLY_OUTFITS'] ?? '' }}"
                               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" placeholder="125">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Monthly Customers</label>
                        <input name="BUDGET_MONTHLY_CUSTOMERS" value="{{ $values['BUDGET_MONTHLY_CUSTOMERS'] ?? '' }}"
                               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" placeholder="320">
                    </div>
                </div>
            </div>

            {{-- Cost / P&L line items --}}
            <div>
                <h3 class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-2">Costs &amp; P&amp;L</h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Monthly Cost of Sales</label>
                        <div class="relative">
                            <span class="absolute left-3 top-2.5 text-xs text-slate-400">XAF</span>
                            <input name="BUDGET_MONTHLY_COST_OF_SALES" value="{{ $values['BUDGET_MONTHLY_COST_OF_SALES'] ?? '' }}"
                                   class="w-full border border-slate-300 rounded-lg pl-12 pr-3 py-2 text-sm" placeholder="3 200 000">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Monthly Direct Costs</label>
                        <div class="relative">
                            <span class="absolute left-3 top-2.5 text-xs text-slate-400">XAF</span>
                            <input name="BUDGET_MONTHLY_DIRECT_COSTS" value="{{ $values['BUDGET_MONTHLY_DIRECT_COSTS'] ?? '' }}"
                                   class="w-full border border-slate-300 rounded-lg pl-12 pr-3 py-2 text-sm" placeholder="1 800 000">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Monthly Indirect Costs</label>
                        <div class="relative">
                            <span class="absolute left-3 top-2.5 text-xs text-slate-400">XAF</span>
                            <input name="BUDGET_MONTHLY_INDIRECT_COSTS" value="{{ $values['BUDGET_MONTHLY_INDIRECT_COSTS'] ?? '' }}"
                                   class="w-full border border-slate-300 rounded-lg pl-12 pr-3 py-2 text-sm" placeholder="1 400 000">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Monthly D&amp;A</label>
                        <div class="relative">
                            <span class="absolute left-3 top-2.5 text-xs text-slate-400">XAF</span>
                            <input name="BUDGET_MONTHLY_DA" value="{{ $values['BUDGET_MONTHLY_DA'] ?? '' }}"
                                   class="w-full border border-slate-300 rounded-lg pl-12 pr-3 py-2 text-sm" placeholder="350 000">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Monthly Income Tax</label>
                        <div class="relative">
                            <span class="absolute left-3 top-2.5 text-xs text-slate-400">XAF</span>
                            <input name="BUDGET_MONTHLY_INCOME_TAX" value="{{ $values['BUDGET_MONTHLY_INCOME_TAX'] ?? '' }}"
                                   class="w-full border border-slate-300 rounded-lg pl-12 pr-3 py-2 text-sm" placeholder="600 000">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Monthly COGS + Discounts <span class="text-slate-400 font-normal">(legacy)</span></label>
                        <div class="relative">
                            <span class="absolute left-3 top-2.5 text-xs text-slate-400">XAF</span>
                            <input name="BUDGET_MONTHLY_COGS" value="{{ $values['BUDGET_MONTHLY_COGS'] ?? '' }}"
                                   class="w-full border border-slate-300 rounded-lg pl-12 pr-3 py-2 text-sm" placeholder="2 134 289">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Monthly Discounts <span class="text-slate-400 font-normal">(legacy)</span></label>
                        <div class="relative">
                            <span class="absolute left-3 top-2.5 text-xs text-slate-400">XAF</span>
                            <input name="BUDGET_MONTHLY_DISCOUNTS" value="{{ $values['BUDGET_MONTHLY_DISCOUNTS'] ?? '' }}"
                                   class="w-full border border-slate-300 rounded-lg pl-12 pr-3 py-2 text-sm" placeholder="450 000">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ── Tax Declarant Information ──────────────────────────────────
             Pre-filled into the DGI I/TVA-IR monthly declaration header so
             the principal does not retype it every month. ─────────────── --}}
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-6">
            <h2 class="text-sm font-semibold text-slate-900 mb-1">Tax Declarant Information</h2>
            <p class="text-xs text-slate-500 mb-4">Pre-filled into the monthly tax return (I/TVA-IR) header. Stored per workspace and shown on the printable declaration.</p>

            {{-- Tax centre / regime --}}
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">D.G.E. / C.R.I.</label>
                    <input name="TAX_DGE_CRI" value="{{ $values['TAX_DGE_CRI'] ?? '' }}" placeholder="e.g. DGE Yaoundé"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">CDI / CSI / CIME</label>
                    <input name="TAX_CDI_CSI_CIME" value="{{ $values['TAX_CDI_CSI_CIME'] ?? '' }}" placeholder="e.g. CIME Centre I"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Régime d'imposition</label>
                    <select name="TAX_REGIME" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white">
                        @foreach (['' => '— select —', 'reel' => 'Réel', 'simplifie' => 'Simplifié'] as $v => $l)
                            <option value="{{ $v }}" {{ ($values['TAX_REGIME'] ?? '') === $v ? 'selected' : '' }}>{{ $l }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">N.I.U. (Numéro Identifiant Unique)</label>
                    <input name="TAX_NIU" value="{{ $values['TAX_NIU'] ?? '' }}" placeholder="P000000000000A"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm font-mono">
                </div>
            </div>

            {{-- Identity --}}
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                <div class="md:col-span-2">
                    <label class="block text-xs font-medium text-slate-600 mb-1">Nom ou Raison sociale</label>
                    <input name="TAX_RAISON_SOCIALE" value="{{ $values['TAX_RAISON_SOCIALE'] ?? '' }}" placeholder="Société ABC SARL"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Sigle</label>
                    <input name="TAX_SIGLE" value="{{ $values['TAX_SIGLE'] ?? '' }}" placeholder="ABC"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
                </div>
            </div>

            {{-- Address --}}
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-4">
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Ville</label>
                    <input name="TAX_VILLE" value="{{ $values['TAX_VILLE'] ?? '' }}" placeholder="Yaoundé"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Commune</label>
                    <input name="TAX_COMMUNE" value="{{ $values['TAX_COMMUNE'] ?? '' }}" placeholder="Yaoundé I"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Quartier</label>
                    <input name="TAX_QUARTIER" value="{{ $values['TAX_QUARTIER'] ?? '' }}" placeholder="Bastos"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Lieu-dit</label>
                    <input name="TAX_LIEU_DIT" value="{{ $values['TAX_LIEU_DIT'] ?? '' }}" placeholder="—"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
                </div>
            </div>

            {{-- Contact --}}
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">B.P.</label>
                    <input name="TAX_BP" value="{{ $values['TAX_BP'] ?? '' }}" placeholder="1234"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Tél. fixe</label>
                    <input name="TAX_TEL_FIXE" value="{{ $values['TAX_TEL_FIXE'] ?? '' }}" placeholder="+237 222 00 00 00"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Tél. mobile</label>
                    <input name="TAX_TEL_MOBILE" value="{{ $values['TAX_TEL_MOBILE'] ?? '' }}" placeholder="+237 6 00 00 00 00"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-xs font-medium text-slate-600 mb-1">Adresse électronique (e-mail)</label>
                    <input name="TAX_EMAIL" type="email" value="{{ $values['TAX_EMAIL'] ?? '' }}" placeholder="contact@firm.cm"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">FAX</label>
                    <input name="TAX_FAX" value="{{ $values['TAX_FAX'] ?? '' }}" placeholder="+237 …"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
                </div>
            </div>
        </div>

        <div class="flex justify-end">
            <button class="bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-700 hover:to-purple-700 text-white font-medium px-5 py-2.5 rounded-lg text-sm shadow-lg shadow-indigo-900/20 transition">
                Save settings
            </button>
        </div>
    </form>
</div>

@push('scripts')
<script>
(function () {
    const btn   = document.getElementById('ea-test-odoo-btn');
    const out   = document.getElementById('ea-test-odoo-result');
    const label = btn?.querySelector('[data-label]');
    if (!btn || !out) return;

    // Read the CSRF token from the meta tag (or the form's hidden _token
    // if the meta isn't present). The page renders it at load time;
    // long-idle pages may end up with a stale token after the Laravel
    // session rotates, so we auto-refresh on 419 below.
    function currentToken() {
        return document.querySelector('meta[name="csrf-token"]')?.content
            || document.querySelector('input[name="_token"]')?.value
            || '';
    }

    // Fetch a fresh CSRF token from the server. Writes it back into
    // the meta tag so any subsequent AJAX call on this page uses the
    // refreshed value too.
    async function refreshToken() {
        const r = await fetch(@json(url('/csrf-refresh')), {
            method: 'GET',
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin',
            cache: 'no-store',
        });
        if (!r.ok) throw new Error('CSRF refresh failed (' + r.status + ')');
        const data = await r.json();
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) meta.setAttribute('content', data.token);
        const hidden = document.querySelector('input[name="_token"]');
        if (hidden) hidden.value = data.token;
        return data.token;
    }

    // POST the test request with a given token. Returns the raw Response.
    async function postTestOdoo(token) {
        return fetch(@json(tenant_url('/settings/test-odoo')), {
            method:  'POST',
            headers: {
                'Accept':       'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': token,
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });
    }

    btn.addEventListener('click', async () => {
        btn.disabled = true;
        if (label) label.textContent = 'Testing…';
        out.className = 'mb-4 px-3 py-2.5 rounded-lg text-[12px] leading-relaxed border bg-slate-50 border-slate-200 text-slate-700';
        out.textContent = 'Pinging Odoo…';

        try {
            // Attempt 1: use the token that was rendered into the page.
            let r = await postTestOdoo(currentToken());

            // 419 = CSRF token mismatch / page expired. Most commonly
            // means the page was idle long enough for the session to
            // rotate. We grab a fresh token + retry once.
            if (r.status === 419) {
                out.textContent = 'Session token expired — refreshing and retrying…';
                const fresh = await refreshToken();
                r = await postTestOdoo(fresh);
            }

            const data = await r.json();
            const ok = !!data.ok;
            out.className = 'mb-4 px-3 py-2.5 rounded-lg text-[12px] leading-relaxed border ' +
                (ok
                    ? 'bg-emerald-50 border-emerald-200 text-emerald-800'
                    : 'bg-rose-50 border-rose-200 text-rose-700');

            const lines = [];
            lines.push((ok ? '✓ ' : '✗ ') + (data.message || (ok ? 'Connected.' : 'Connection failed.')));
            const meta = [];
            if (data.protocol)       meta.push('protocol: ' + data.protocol);
            if (typeof data.latency_ms === 'number') meta.push('latency: ' + data.latency_ms + ' ms');
            if (data.user_id)        meta.push('user_id: ' + data.user_id);
            if (data.server_version) meta.push('server: ' + data.server_version);
            if (meta.length) lines.push(meta.join(' · '));
            out.textContent = lines.join('  ');
        } catch (err) {
            out.className = 'mb-4 px-3 py-2.5 rounded-lg text-[12px] leading-relaxed border bg-rose-50 border-rose-200 text-rose-700';
            out.textContent = '✗ Browser-side error reaching BMS: ' + (err?.message || err) +
                '. Try reloading the page and clicking Test connection again.';
        } finally {
            btn.disabled = false;
            if (label) label.textContent = 'Test connection';
        }
    });
})();
</script>
@endpush
@endsection
