@extends('layouts.app')
@section('title', 'Settings')

@section('header')
    <h1 class="text-2xl font-bold text-slate-900">Settings</h1>
    <p class="text-xs text-slate-500 mt-1">Odoo connection, branding and the Claude AI advisor.</p>
@endsection

@section('content')
<div class="max-w-3xl">
    <form method="POST" action="/settings" class="space-y-6">
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
                    <input name="ODOO_API_KEY" type="password" placeholder="••••••••••••"
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

        {{-- ── Claude AI ──────────────────────────────────────────────── --}}
        <div id="ai" class="bg-gradient-to-br from-violet-50 via-white to-fuchsia-50 rounded-xl border border-violet-200 shadow-sm p-6 relative overflow-hidden">
            <div class="absolute top-0 right-0 w-32 h-32 bg-gradient-to-br from-violet-400 to-fuchsia-400 opacity-10 rounded-full -mr-16 -mt-16"></div>
            <div class="flex items-center gap-3 mb-3 relative">
                <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-violet-500 via-fuchsia-500 to-amber-500 flex items-center justify-center shadow-lg">
                    <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L9.5 8.5 3 11l6.5 2.5L12 20l2.5-6.5L21 11l-6.5-2.5z"/></svg>
                </div>
                <div>
                    <h2 class="text-sm font-semibold text-slate-900">Elite Advisor (AI Insights)</h2>
                    <p class="text-xs text-slate-500">Powers the right-rail commentary on every data page.</p>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 relative">
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Provider</label>
                    <select name="AI_PROVIDER" class="w-full border border-violet-200 rounded-lg px-3 py-2 text-sm bg-white">
                        @foreach (['claude' => 'Anthropic Claude', 'openai' => 'OpenAI'] as $v => $l)
                          <option value="{{ $v }}" {{ $values['AI_PROVIDER'] === $v ? 'selected' : '' }}>{{ $l }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Model</label>
                    <input name="AI_MODEL" value="{{ $values['AI_MODEL'] }}" placeholder="claude-sonnet-4-5"
                           class="w-full border border-violet-200 rounded-lg px-3 py-2 text-sm font-mono">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-xs font-medium text-slate-600 mb-1">
                        API Key <span class="text-slate-400 font-normal">(leave blank to keep current)</span>
                    </label>
                    <input name="AI_API_KEY" type="password"
                           placeholder="{{ $values['AI_API_KEY'] !== '' ? '•••• stored ••••' : 'sk-ant-api03-...' }}"
                           class="w-full border border-violet-200 rounded-lg px-3 py-2 text-sm font-mono">
                </div>
            </div>

            <div class="mt-4 p-3 bg-white/60 rounded-lg border border-violet-100 text-[11px] text-slate-600 leading-relaxed relative">
                <strong class="text-slate-800">Where to get a Claude key:</strong><br>
                Go to <a href="https://console.anthropic.com" target="_blank" class="text-violet-600 font-mono hover:underline">console.anthropic.com</a>,
                sign up, add at least $5 of prepaid credits under <em>Billing</em>, then create a key under <em>API Keys</em>.
                Typical cost on this dashboard: <strong>$0.01 – $0.05</strong> per page load (with 5-min cache).
                Recommended model: <code class="bg-violet-100 px-1 rounded">claude-sonnet-4-5</code>.
            </div>
        </div>

        <div class="flex justify-end">
            <button class="bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-700 hover:to-purple-700 text-white font-medium px-5 py-2.5 rounded-lg text-sm shadow-lg shadow-indigo-900/20 transition">
                Save settings
            </button>
        </div>
    </form>
</div>
@endsection
