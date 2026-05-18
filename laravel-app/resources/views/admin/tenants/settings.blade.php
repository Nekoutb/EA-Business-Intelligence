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
        $groups = [
            'Odoo connection'    => ['ODOO_URL','ODOO_DB','ODOO_USERNAME','ODOO_API_KEY','ODOO_PROTOCOL','REVENUE_SOURCE'],
            'Branding'           => ['APP_BRAND'],
        ];
    @endphp

    @foreach ($groups as $heading => $keys)
        <div class="panel p-5">
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-sm font-semibold text-slate-900">{{ $heading }}</h2>
                @if ($heading === 'Odoo connection')
                    <button type="button" id="ea-admin-test-odoo-btn"
                            class="inline-flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider text-rose-700 border border-rose-300 hover:border-rose-500 bg-rose-50/40 hover:bg-rose-50 rounded-md px-3 py-1.5 transition disabled:opacity-50 disabled:cursor-not-allowed">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        <span data-label>Test connection</span>
                    </button>
                @endif
            </div>
            @if ($heading === 'Odoo connection')
                <div id="ea-admin-test-odoo-result" class="hidden mb-4 px-3 py-2.5 rounded-lg text-[12px] leading-relaxed border" role="status"></div>
            @endif
            <div class="grid md:grid-cols-2 gap-4">
                @foreach ($keys as $k)
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
    @endforeach

    <div class="flex justify-end gap-2">
        <a href="/admin/tenants/{{ $tenant->id }}" class="text-sm text-slate-500 hover:text-slate-800 px-3 py-2">Cancel</a>
        <button class="bg-gradient-to-r from-rose-600 to-orange-600 hover:from-rose-700 hover:to-orange-700 text-white font-semibold px-4 py-2 rounded-lg text-sm shadow transition">
            Save settings
        </button>
    </div>
</form>

<script>
(function () {
    const btn   = document.getElementById('ea-admin-test-odoo-btn');
    const out   = document.getElementById('ea-admin-test-odoo-result');
    const label = btn?.querySelector('[data-label]');
    if (!btn || !out) return;

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

    async function postTest(token) {
        return fetch('/admin/tenants/{{ $tenant->id }}/settings/test-odoo', {
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
        out.textContent = 'Pinging Odoo as this tenant…';

        try {
            let r = await postTest(currentToken());

            // 419 = CSRF token mismatch / page expired. The page was
            // probably idle long enough for the session to rotate;
            // grab a fresh token + retry once.
            if (r.status === 419) {
                out.textContent = 'Session token expired — refreshing and retrying…';
                const fresh = await refreshToken();
                r = await postTest(fresh);
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
@endsection
