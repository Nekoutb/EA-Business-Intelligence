@extends('admin.layout')
@section('title', $tenant->name . ' settings')

@section('header')
    <div>
        <div class="flex items-center gap-2 mb-1">
            <a href="/admin/tenants/{{ $tenant->id }}" class="text-xs text-slate-500 hover:text-slate-800">← {{ $tenant->name }}</a>
        </div>
        <h1 class="text-2xl font-bold text-slate-900">Settings</h1>
        <p class="text-xs text-slate-500 mt-1">Per-tenant configuration. Secrets are encrypted at rest. Leave secret fields blank to keep the existing value.</p>
        <p class="text-[11px] text-slate-400 mt-1">Note: budget benchmarks are managed by the tenant on their own settings page (<code class="bg-slate-100 px-1 py-0.5 rounded">/t/{{ $tenant->slug }}/settings</code>) — not editable here.</p>
    </div>
@endsection

@section('content')
<form method="POST" action="/admin/tenants/{{ $tenant->id }}/settings" class="space-y-4 max-w-3xl">
    @csrf

    @php
        // AI provider config is platform-global (managed at /admin/settings/ai),
        // so it's intentionally not exposed on a per-tenant page.
        // Budget benchmarks are tenant-managed on /t/{slug}/settings (not here).
        $erpTypes = \App\Http\Controllers\Admin\TenantController::ERP_TYPES;
        $erpType  = $values['ERP_TYPE'] !== '' ? $values['ERP_TYPE'] : 'odoo';
        $erp2Type = ($values['ERP2_TYPE'] ?? '') !== '' ? $values['ERP2_TYPE'] : 'none';
    @endphp

    {{-- ERP system selection: Odoo talks over the web API; every other
         ERP connects straight to a database the provider exposes. --}}
    <div class="panel p-5">
        <h2 class="text-sm font-semibold text-slate-900 mb-3">ERP system</h2>
        <div class="grid md:grid-cols-2 gap-4">
            <label class="block">
                <span class="text-[11px] font-medium text-slate-600 font-mono">ERP_TYPE</span>
                <select name="ERP_TYPE" id="ea-erp-type" data-erp-type-select="1"
                        class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm font-mono">
                    @foreach ($erpTypes as $key => $label)
                        <option value="{{ $key }}" {{ $erpType === $key ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block" data-erp-name-field="1">
                <span class="text-[11px] font-medium text-slate-600 font-mono">ERP_NAME <span class="text-slate-400 normal-case">(when "Other")</span></span>
                <input name="ERP_NAME" type="text" value="{{ $values['ERP_NAME'] }}"
                       placeholder="e.g. Elimelec custom ERP"
                       class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm font-mono">
            </label>
        </div>
    </div>

    {{-- Odoo (web API) connection --}}
    <div class="panel p-5" data-erp-section="odoo" data-erp-group="1">
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-sm font-semibold text-slate-900">Odoo connection</h2>
            <button type="button" data-test-btn data-test-url="/admin/tenants/{{ $tenant->id }}/settings/test-odoo"
                    data-test-pending="Pinging Odoo as this tenant…"
                    class="inline-flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider text-rose-700 border border-rose-300 hover:border-rose-500 bg-rose-50/40 hover:bg-rose-50 rounded-md px-3 py-1.5 transition disabled:opacity-50 disabled:cursor-not-allowed">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                <span data-label>Test connection</span>
            </button>
        </div>
        <div data-test-result class="hidden mb-4 px-3 py-2.5 rounded-lg text-[12px] leading-relaxed border" role="status"></div>
        <div class="grid md:grid-cols-2 gap-4">
            @foreach (['ODOO_URL','ODOO_DB','ODOO_USERNAME','ODOO_API_KEY','ODOO_PROTOCOL'] as $k)
                @php $isSecret = preg_match('/(KEY|SECRET|PASSWORD|TOKEN)/i', $k); @endphp
                <label class="block">
                    <span class="text-[11px] font-medium text-slate-600 font-mono">{{ $k }}</span>
                    <input name="{{ $k }}"
                           type="{{ $isSecret ? 'password' : 'text' }}"
                           value="{{ $isSecret ? '' : $values[$k] }}"
                           placeholder="{{ $isSecret && !empty($values['__has_'.$k]) ? '•••• (already set, leave blank to keep)' : '' }}"
                           class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm font-mono">
                </label>
            @endforeach
        </div>
    </div>

    {{-- Direct database connection (any non-Odoo ERP) --}}
    <div class="panel p-5" data-erp-section="db" data-erp-group="1">
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-sm font-semibold text-slate-900">ERP database connection</h2>
            <button type="button" data-test-btn data-test-url="/admin/tenants/{{ $tenant->id }}/settings/test-erp-db"
                    data-test-pending="Connecting to the ERP database as this tenant…"
                    class="inline-flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider text-rose-700 border border-rose-300 hover:border-rose-500 bg-rose-50/40 hover:bg-rose-50 rounded-md px-3 py-1.5 transition disabled:opacity-50 disabled:cursor-not-allowed">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                <span data-label>Test connection</span>
            </button>
        </div>
        <p class="text-[11px] text-slate-500 mb-3">Credentials the ERP provider gave for direct read access (usually a BI replica). SSL mode "require" accepts self-signed certificates.</p>
        <div data-test-result class="hidden mb-4 px-3 py-2.5 rounded-lg text-[12px] leading-relaxed border" role="status"></div>
        <div class="grid md:grid-cols-2 gap-4">
            <label class="block">
                <span class="text-[11px] font-medium text-slate-600 font-mono">ERP_DB_DRIVER</span>
                <select name="ERP_DB_DRIVER" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm font-mono">
                    @foreach (['pgsql' => 'PostgreSQL', 'mysql' => 'MySQL / MariaDB', 'sqlsrv' => 'SQL Server'] as $d => $label)
                        <option value="{{ $d }}" {{ ($values['ERP_DB_DRIVER'] ?: 'pgsql') === $d ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            @foreach (['ERP_DB_HOST' => 'db.example.com', 'ERP_DB_PORT' => '5432', 'ERP_DB_DATABASE' => '', 'ERP_DB_USERNAME' => '', 'ERP_DB_PASSWORD' => '', 'ERP_DB_SCHEMA' => 'public'] as $k => $ph)
                @php $isSecret = preg_match('/(KEY|SECRET|PASSWORD|TOKEN)/i', $k); @endphp
                <label class="block">
                    <span class="text-[11px] font-medium text-slate-600 font-mono">{{ $k }}</span>
                    <input name="{{ $k }}"
                           type="{{ $isSecret ? 'password' : 'text' }}"
                           value="{{ $isSecret ? '' : $values[$k] }}"
                           placeholder="{{ $isSecret ? (!empty($values['__has_'.$k]) ? '•••• (already set, leave blank to keep)' : '') : $ph }}"
                           class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm font-mono">
                </label>
            @endforeach
            <label class="block">
                <span class="text-[11px] font-medium text-slate-600 font-mono">ERP_DB_SSLMODE</span>
                <select name="ERP_DB_SSLMODE" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm font-mono">
                    @foreach (['prefer' => 'prefer (default)', 'require' => 'require (encrypted, self-signed OK)', 'disable' => 'disable'] as $m => $label)
                        <option value="{{ $m }}" {{ ($values['ERP_DB_SSLMODE'] ?: 'prefer') === $m ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        </div>
    </div>

    <div class="panel p-5" data-erp-section="rest" data-erp-group="1">
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-sm font-semibold text-slate-900">REST API gateway</h2>
            <button type="button" data-test-btn data-test-url="/admin/tenants/{{ $tenant->id }}/settings/test-rest-api"
                    data-test-pending="Calling the gateway's test endpoint as this tenant…"
                    class="inline-flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider text-rose-700 border border-rose-300 hover:border-rose-500 bg-rose-50/40 hover:bg-rose-50 rounded-md px-3 py-1.5 transition disabled:opacity-50 disabled:cursor-not-allowed">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                <span data-label>Test connection</span>
            </button>
        </div>
        <p class="text-[11px] text-slate-500 mb-3">A read-only HTTPS API the ERP provider exposes (e.g. Sage 100 behind an API). The key is sent in the header named below on every request.</p>
        <div data-test-result class="hidden mb-4 px-3 py-2.5 rounded-lg text-[12px] leading-relaxed border" role="status"></div>
        <div class="grid md:grid-cols-2 gap-4">
            @foreach (['ERP_API_URL' => 'https://apisage.example.com', 'ERP_API_KEY_HEADER' => 'X-API-Key', 'ERP_API_KEY' => '', 'ERP_API_DATABASE' => 'Company database label (optional)'] as $k => $ph)
                @php $isSecret = preg_match('/(KEY$|SECRET|PASSWORD|TOKEN)/i', $k); @endphp
                <label class="block">
                    <span class="text-[11px] font-medium text-slate-600 font-mono">{{ $k }}</span>
                    <input name="{{ $k }}"
                           type="{{ $isSecret ? 'password' : 'text' }}"
                           value="{{ $isSecret ? '' : ($values[$k] ?? '') }}"
                           placeholder="{{ $isSecret ? (!empty($values['__has_'.$k]) ? '•••• (already set, leave blank to keep)' : '') : $ph }}"
                           class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm font-mono">
                </label>
            @endforeach
        </div>
    </div>

    {{-- Optional second data source. A client running two systems gets
         both synced into the same warehouse and combined in every report
         (see App\Services\DataSource). Same fields, ERP2_ prefix. --}}
    <div class="panel p-5">
        <h2 class="text-sm font-semibold text-slate-900 mb-1">Second data source <span class="text-slate-400 font-normal">(optional)</span></h2>
        <p class="text-[11px] text-slate-500 mb-3">For clients that run two systems side by side. Both are synced into the same warehouse and every report combines them. Leave as "None" for a single source.</p>
        <div class="grid md:grid-cols-2 gap-4">
            <label class="block">
                <span class="text-[11px] font-medium text-slate-600 font-mono">ERP2_TYPE</span>
                <select name="ERP2_TYPE" data-erp-type-select="2"
                        class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm font-mono">
                    @foreach (\App\Http\Controllers\Admin\TenantController::erp2Types() as $key => $label)
                        <option value="{{ $key }}" {{ $erp2Type === $key ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block" data-erp-name-field="2">
                <span class="text-[11px] font-medium text-slate-600 font-mono">ERP2_NAME <span class="text-slate-400 normal-case">(when "Other")</span></span>
                <input name="ERP2_NAME" type="text" value="{{ $values['ERP2_NAME'] ?? '' }}"
                       placeholder="e.g. Sage 100 (accounting)"
                       class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm font-mono">
            </label>
        </div>
    </div>

    <div class="panel p-5" data-erp-section="odoo" data-erp-group="2">
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-sm font-semibold text-slate-900">Second source · Odoo connection</h2>
            <button type="button" data-test-btn data-test-url="/admin/tenants/{{ $tenant->id }}/settings/test-odoo/2"
                    data-test-pending="Pinging the second Odoo as this tenant…"
                    class="inline-flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider text-rose-700 border border-rose-300 hover:border-rose-500 bg-rose-50/40 hover:bg-rose-50 rounded-md px-3 py-1.5 transition disabled:opacity-50 disabled:cursor-not-allowed">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                <span data-label>Test connection</span>
            </button>
        </div>
        <div data-test-result class="hidden mb-4 px-3 py-2.5 rounded-lg text-[12px] leading-relaxed border" role="status"></div>
        <div class="grid md:grid-cols-2 gap-4">
            @foreach (['ERP2_ODOO_URL','ERP2_ODOO_DB','ERP2_ODOO_USERNAME','ERP2_ODOO_API_KEY','ERP2_ODOO_PROTOCOL'] as $k)
                @php $isSecret = preg_match('/(KEY|SECRET|PASSWORD|TOKEN)/i', $k); @endphp
                <label class="block">
                    <span class="text-[11px] font-medium text-slate-600 font-mono">{{ $k }}</span>
                    <input name="{{ $k }}"
                           type="{{ $isSecret ? 'password' : 'text' }}"
                           value="{{ $isSecret ? '' : ($values[$k] ?? '') }}"
                           placeholder="{{ $isSecret && !empty($values['__has_'.$k]) ? '•••• (already set, leave blank to keep)' : '' }}"
                           class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm font-mono">
                </label>
            @endforeach
        </div>
    </div>

    <div class="panel p-5" data-erp-section="db" data-erp-group="2">
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-sm font-semibold text-slate-900">Second source · database connection</h2>
            <button type="button" data-test-btn data-test-url="/admin/tenants/{{ $tenant->id }}/settings/test-erp-db/2"
                    data-test-pending="Connecting to the second database as this tenant…"
                    class="inline-flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider text-rose-700 border border-rose-300 hover:border-rose-500 bg-rose-50/40 hover:bg-rose-50 rounded-md px-3 py-1.5 transition disabled:opacity-50 disabled:cursor-not-allowed">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                <span data-label>Test connection</span>
            </button>
        </div>
        <div data-test-result class="hidden mb-4 px-3 py-2.5 rounded-lg text-[12px] leading-relaxed border" role="status"></div>
        <div class="grid md:grid-cols-2 gap-4">
            <label class="block">
                <span class="text-[11px] font-medium text-slate-600 font-mono">ERP2_DB_DRIVER</span>
                <select name="ERP2_DB_DRIVER" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm font-mono">
                    @foreach (['pgsql' => 'PostgreSQL', 'mysql' => 'MySQL / MariaDB', 'sqlsrv' => 'SQL Server'] as $d => $label)
                        <option value="{{ $d }}" {{ (($values['ERP2_DB_DRIVER'] ?? '') ?: 'pgsql') === $d ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            @foreach (['ERP2_DB_HOST' => 'db.example.com', 'ERP2_DB_PORT' => '5432', 'ERP2_DB_DATABASE' => '', 'ERP2_DB_USERNAME' => '', 'ERP2_DB_PASSWORD' => '', 'ERP2_DB_SCHEMA' => 'public'] as $k => $ph)
                @php $isSecret = preg_match('/(KEY|SECRET|PASSWORD|TOKEN)/i', $k); @endphp
                <label class="block">
                    <span class="text-[11px] font-medium text-slate-600 font-mono">{{ $k }}</span>
                    <input name="{{ $k }}"
                           type="{{ $isSecret ? 'password' : 'text' }}"
                           value="{{ $isSecret ? '' : ($values[$k] ?? '') }}"
                           placeholder="{{ $isSecret ? (!empty($values['__has_'.$k]) ? '•••• (already set, leave blank to keep)' : '') : $ph }}"
                           class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm font-mono">
                </label>
            @endforeach
            <label class="block">
                <span class="text-[11px] font-medium text-slate-600 font-mono">ERP2_DB_SSLMODE</span>
                <select name="ERP2_DB_SSLMODE" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm font-mono">
                    @foreach (['prefer' => 'prefer (default)', 'require' => 'require (encrypted, self-signed OK)', 'disable' => 'disable'] as $m => $label)
                        <option value="{{ $m }}" {{ (($values['ERP2_DB_SSLMODE'] ?? '') ?: 'prefer') === $m ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        </div>
    </div>

    <div class="panel p-5" data-erp-section="rest" data-erp-group="2">
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-sm font-semibold text-slate-900">Second source · REST API gateway</h2>
            <button type="button" data-test-btn data-test-url="/admin/tenants/{{ $tenant->id }}/settings/test-rest-api/2"
                    data-test-pending="Calling the second gateway's test endpoint as this tenant…"
                    class="inline-flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider text-rose-700 border border-rose-300 hover:border-rose-500 bg-rose-50/40 hover:bg-rose-50 rounded-md px-3 py-1.5 transition disabled:opacity-50 disabled:cursor-not-allowed">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                <span data-label>Test connection</span>
            </button>
        </div>
        <p class="text-[11px] text-slate-500 mb-3">A read-only HTTPS API the ERP provider exposes (e.g. Sage 100 behind an API). The key is sent in the header named below on every request.</p>
        <div data-test-result class="hidden mb-4 px-3 py-2.5 rounded-lg text-[12px] leading-relaxed border" role="status"></div>
        <div class="grid md:grid-cols-2 gap-4">
            @foreach (['ERP2_API_URL' => 'https://apisage.example.com', 'ERP2_API_KEY_HEADER' => 'X-API-Key', 'ERP2_API_KEY' => '', 'ERP2_API_DATABASE' => 'Company database label (optional)'] as $k => $ph)
                @php $isSecret = preg_match('/(KEY$|SECRET|PASSWORD|TOKEN)/i', $k); @endphp
                <label class="block">
                    <span class="text-[11px] font-medium text-slate-600 font-mono">{{ $k }}</span>
                    <input name="{{ $k }}"
                           type="{{ $isSecret ? 'password' : 'text' }}"
                           value="{{ $isSecret ? '' : ($values[$k] ?? '') }}"
                           placeholder="{{ $isSecret ? (!empty($values['__has_'.$k]) ? '•••• (already set, leave blank to keep)' : '') : $ph }}"
                           class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm font-mono">
                </label>
            @endforeach
        </div>
    </div>

    {{-- Shared BI + branding settings --}}
    <div class="panel p-5">
        <h2 class="text-sm font-semibold text-slate-900 mb-3">General</h2>
        <div class="grid md:grid-cols-2 gap-4">
            @foreach (['REVENUE_SOURCE','APP_BRAND'] as $k)
                <label class="block">
                    <span class="text-[11px] font-medium text-slate-600 font-mono">{{ $k }}</span>
                    <input name="{{ $k }}" type="text" value="{{ $values[$k] }}"
                           class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm font-mono">
                </label>
            @endforeach
        </div>
    </div>

    <div class="flex justify-end gap-2">
        <a href="/admin/tenants/{{ $tenant->id }}" class="text-sm text-slate-500 hover:text-slate-800 px-3 py-2">Cancel</a>
        <button class="bg-gradient-to-r from-rose-600 to-orange-600 hover:from-rose-700 hover:to-orange-700 text-white font-semibold px-4 py-2 rounded-lg text-sm shadow transition">
            Save settings
        </button>
    </div>
</form>

<script>
(function () {
    // --- Show the connection panels that match each source's ERP type ---
    // Group 1 is the primary source, group 2 the optional second one
    // ("none" hides both of its panels).
    function syncGroup(sel) {
        const g = sel.dataset.erpTypeSelect;
        const type = sel.value;
        const off = type === 'none' || type === '';
        // Each ERP type maps to one connection panel: Odoo's web API, a
        // REST gateway, or a direct database (every other ERP).
        const want = type === 'odoo' ? 'odoo' : (type === 'rest' ? 'rest' : 'db');
        document.querySelectorAll('[data-erp-section][data-erp-group="' + g + '"]').forEach(el => {
            const show = !off && el.dataset.erpSection === want;
            el.classList.toggle('hidden', !show);
            // Hidden panels are disabled so switching ERP type never
            // silently rewrites the other connection's stored settings.
            el.querySelectorAll('input, select').forEach(i => { i.disabled = !show; });
        });
        const nameField = document.querySelector('[data-erp-name-field="' + g + '"]');
        if (nameField) nameField.classList.toggle('hidden', type === 'odoo' || off);
    }
    document.querySelectorAll('[data-erp-type-select]').forEach(sel => {
        sel.addEventListener('change', () => syncGroup(sel));
        syncGroup(sel);
    });

    // --- Connection testers (Odoo API + direct DB share one handler) ----
    // Read the CSRF token from the meta tag (or the form's hidden _token
    // if the meta isn't present). Long-idle pages may end up with a
    // stale token after the Laravel session rotates; the 419 retry
    // below grabs a fresh one transparently so the operator never sees
    // a "CSRF token mismatch" error after leaving the page open.
    function currentToken() {
        return document.querySelector('meta[name="csrf-token"]')?.content
            || document.querySelector('input[name="_token"]')?.value
            || '';
    }

    async function refreshToken() {
        const r = await fetch('/csrf-refresh', {
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

    async function postTest(url, token) {
        return fetch(url, {
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

    document.querySelectorAll('[data-test-btn]').forEach(btn => {
        const out   = btn.closest('.panel')?.querySelector('[data-test-result]');
        const label = btn.querySelector('[data-label]');
        if (!out) return;

        btn.addEventListener('click', async () => {
            btn.disabled = true;
            if (label) label.textContent = 'Testing…';
            out.className = 'mb-4 px-3 py-2.5 rounded-lg text-[12px] leading-relaxed border bg-slate-50 border-slate-200 text-slate-700';
            out.textContent = btn.dataset.testPending || 'Testing…';

            try {
                let r = await postTest(btn.dataset.testUrl, currentToken());

                // 419 = CSRF token mismatch / page expired. The page was
                // probably idle long enough for the session to rotate;
                // grab a fresh token + retry once.
                if (r.status === 419) {
                    out.textContent = 'Session token expired — refreshing and retrying…';
                    const fresh = await refreshToken();
                    r = await postTest(btn.dataset.testUrl, fresh);
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
    });
})();
</script>
@endsection
