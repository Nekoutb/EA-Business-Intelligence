@extends('layouts.app')
@section('title', 'Settings')

@section('header')
    <h1 class="text-2xl font-bold text-slate-900">Settings</h1>
    <p class="text-xs text-slate-500 mt-1">Per-workspace configuration: Odoo connection, branding, budget benchmarks. Secrets are encrypted at rest.</p>
@endsection

@section('content')
<div class="max-w-3xl">
    <form method="POST" action="{{ tenant_url('/settings') }}" class="space-y-6">
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

        {{-- ── Branding ───────────────────────────────────────────────── --}}
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-6">
            <h2 class="text-sm font-semibold text-slate-900 mb-1">Appearance</h2>
            <p class="text-xs text-slate-500 mb-4">Brand name shown in the sidebar and login page.</p>
            <input name="APP_BRAND" value="{{ $values['APP_BRAND'] }}" placeholder="Your company name"
                   class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
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

        {{-- Elite Advisor (AI Insights) is configured platform-wide by the super
             admin. Nothing for tenants to manage here. --}}

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

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content
              || document.querySelector('input[name="_token"]')?.value
              || '';

    btn.addEventListener('click', async () => {
        btn.disabled = true;
        if (label) label.textContent = 'Testing…';
        out.className = 'mb-4 px-3 py-2.5 rounded-lg text-[12px] leading-relaxed border bg-slate-50 border-slate-200 text-slate-700';
        out.textContent = 'Pinging Odoo…';

        try {
            const r = await fetch(@json(tenant_url('/settings/test-odoo')), {
                method:  'POST',
                headers: {
                    'Accept':       'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });
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
            out.textContent = '✗ Browser-side error reaching BMS: ' + (err?.message || err);
        } finally {
            btn.disabled = false;
            if (label) label.textContent = 'Test connection';
        }
    });
})();
</script>
@endpush
@endsection
