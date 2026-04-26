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
            <h2 class="text-sm font-semibold text-slate-900 mb-1">Odoo Connection</h2>
            <p class="text-xs text-slate-500 mb-4">Credentials used to pull data from your Odoo instance.</p>

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
            <h2 class="text-sm font-semibold text-slate-900 mb-1">Revenue Budget Benchmarks</h2>
            <p class="text-xs text-slate-500 mb-4">Used for RpD / RpO comparisons and budget overlays on charts.</p>
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
                    <label class="block text-xs font-medium text-slate-600 mb-1">Monthly COGS + Discounts</label>
                    <div class="relative">
                        <span class="absolute left-3 top-2.5 text-xs text-slate-400">XAF</span>
                        <input name="BUDGET_MONTHLY_COGS" value="{{ $values['BUDGET_MONTHLY_COGS'] ?? '' }}"
                               class="w-full border border-slate-300 rounded-lg pl-12 pr-3 py-2 text-sm" placeholder="2 134 289">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Monthly Discounts</label>
                    <div class="relative">
                        <span class="absolute left-3 top-2.5 text-xs text-slate-400">XAF</span>
                        <input name="BUDGET_MONTHLY_DISCOUNTS" value="{{ $values['BUDGET_MONTHLY_DISCOUNTS'] ?? '' }}"
                               class="w-full border border-slate-300 rounded-lg pl-12 pr-3 py-2 text-sm" placeholder="450 000">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Monthly Customers</label>
                    <input name="BUDGET_MONTHLY_CUSTOMERS" value="{{ $values['BUDGET_MONTHLY_CUSTOMERS'] ?? '' }}"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" placeholder="320">
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
@endsection
