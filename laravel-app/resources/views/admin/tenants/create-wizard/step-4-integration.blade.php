@extends('admin.layout')
@section('title', 'Create Tenant - Step 4')

@section('header')
    <div>
        <h1 class="text-2xl font-bold text-slate-900">Create Tenant</h1>
        <p class="text-xs text-slate-500 mt-1">Step 4 of 5 · Odoo Integration Setup</p>
    </div>
@endsection

@section('content')
<div class="max-w-2xl">
    @include('admin.tenants.create-wizard.stepper', ['step' => 4])

    <div class="panel p-6">
        @if ($needsOdoo)
            <h2 class="text-lg font-bold text-slate-900 mb-2">ERP Integration Setup</h2>
            <p class="text-sm text-slate-600 mb-6">The selected services require an ERP connection. Choose the ERP system and provide its credentials below.</p>

            @if ($errors->any())
                <div class="text-sm text-rose-700 bg-rose-50 border border-rose-200 rounded-lg p-4 mb-4">
                    <div class="font-semibold mb-1">Please fix the following errors:</div>
                    @foreach ($errors->all() as $err) <div>{{ $err }}</div> @endforeach
                </div>
            @endif

            <form method="POST" action="/admin/tenants/wizard-step" class="space-y-4">
                @csrf
                <input type="hidden" name="step" value="4">
                <input type="hidden" name="action" value="next">

                @php
                    $erpTypes = \App\Http\Controllers\Admin\TenantController::ERP_TYPES;
                    $erpType  = old('ERP_TYPE', $data['ERP_TYPE'] ?? 'odoo');
                    $erp2Type = old('ERP2_TYPE', $data['ERP2_TYPE'] ?? 'none');
                    $in = 'mt-1.5 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent';
                @endphp

                <div class="space-y-4">
                    <label class="block">
                        <span class="text-xs font-medium text-slate-600">ERP system <span class="text-red-500">*</span></span>
                        <select name="ERP_TYPE" id="ea-wizard-erp-type" data-erp-type-select="1" required
                                class="mt-1.5 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                            @foreach ($erpTypes as $key => $label)
                                <option value="{{ $key }}" {{ $erpType === $key ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="block" data-erp-name-field="1">
                        <span class="text-xs font-medium text-slate-600">ERP name <span class="text-red-500">*</span></span>
                        <input type="text" name="ERP_NAME" value="{{ old('ERP_NAME', $data['ERP_NAME'] ?? '') }}"
                               class="mt-1.5 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                               placeholder="Name of the ERP system">
                    </label>
                </div>

                {{-- Odoo (web API) credentials --}}
                <div class="space-y-4" data-erp-section="odoo" data-erp-group="1">
                    <label class="block">
                        <span class="text-xs font-medium text-slate-600">Odoo URL <span class="text-red-500">*</span></span>
                        <input type="url" name="ODOO_URL" value="{{ old('ODOO_URL', $data['ODOO_URL'] ?? '') }}" required
                               class="mt-1.5 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                               placeholder="https://odoo.example.com">
                    </label>

                    <label class="block">
                        <span class="text-xs font-medium text-slate-600">Database Name <span class="text-red-500">*</span></span>
                        <input type="text" name="ODOO_DB" value="{{ old('ODOO_DB', $data['ODOO_DB'] ?? '') }}" required
                               class="mt-1.5 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                               placeholder="production">
                    </label>

                    <label class="block">
                        <span class="text-xs font-medium text-slate-600">Username <span class="text-red-500">*</span></span>
                        <input type="text" name="ODOO_USERNAME" value="{{ old('ODOO_USERNAME', $data['ODOO_USERNAME'] ?? '') }}" required
                               class="mt-1.5 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                               placeholder="api@example.com">
                    </label>

                    <label class="block">
                        <span class="text-xs font-medium text-slate-600">API Key <span class="text-red-500">*</span></span>
                        <input type="password" name="ODOO_API_KEY" value="{{ old('ODOO_API_KEY', $data['ODOO_API_KEY'] ?? '') }}" required
                               class="mt-1.5 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                               placeholder="••••••••••">
                    </label>

                    <label class="block">
                        <span class="text-xs font-medium text-slate-600">Protocol <span class="text-red-500">*</span></span>
                        <select name="ODOO_PROTOCOL" required
                                class="mt-1.5 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                            <option value="json2" {{ old('ODOO_PROTOCOL', $data['ODOO_PROTOCOL'] ?? 'json2') === 'json2' ? 'selected' : '' }}>JSON-RPC v2</option>
                            <option value="jsonrpc" {{ old('ODOO_PROTOCOL', $data['ODOO_PROTOCOL'] ?? '') === 'jsonrpc' ? 'selected' : '' }}>JSON-RPC</option>
                            <option value="xmlrpc" {{ old('ODOO_PROTOCOL', $data['ODOO_PROTOCOL'] ?? '') === 'xmlrpc' ? 'selected' : '' }}>XML-RPC</option>
                        </select>
                    </label>
                </div>

                {{-- Direct database credentials (any non-Odoo ERP) --}}
                <div class="space-y-4" data-erp-section="db" data-erp-group="1">
                    <p class="text-xs text-slate-500">Enter the direct database access the ERP provider supplied (usually a BI replica). SSL mode "require" accepts self-signed certificates.</p>

                    <label class="block">
                        <span class="text-xs font-medium text-slate-600">Database engine <span class="text-red-500">*</span></span>
                        <select name="ERP_DB_DRIVER" required
                                class="mt-1.5 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                            @foreach (['pgsql' => 'PostgreSQL', 'mysql' => 'MySQL / MariaDB', 'sqlsrv' => 'SQL Server'] as $d => $label)
                                <option value="{{ $d }}" {{ old('ERP_DB_DRIVER', $data['ERP_DB_DRIVER'] ?? 'pgsql') === $d ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>

                    <div class="grid grid-cols-2 gap-4">
                        <label class="block">
                            <span class="text-xs font-medium text-slate-600">Host <span class="text-red-500">*</span></span>
                            <input type="text" name="ERP_DB_HOST" value="{{ old('ERP_DB_HOST', $data['ERP_DB_HOST'] ?? '') }}" required
                                   class="mt-1.5 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                                   placeholder="db.example.com">
                        </label>
                        <label class="block">
                            <span class="text-xs font-medium text-slate-600">Port <span class="text-red-500">*</span></span>
                            <input type="number" name="ERP_DB_PORT" value="{{ old('ERP_DB_PORT', $data['ERP_DB_PORT'] ?? '') }}" required min="1" max="65535"
                                   class="mt-1.5 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                                   placeholder="5432">
                        </label>
                    </div>

                    <label class="block">
                        <span class="text-xs font-medium text-slate-600">Database name <span class="text-red-500">*</span></span>
                        <input type="text" name="ERP_DB_DATABASE" value="{{ old('ERP_DB_DATABASE', $data['ERP_DB_DATABASE'] ?? '') }}" required
                               class="mt-1.5 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                    </label>

                    <div class="grid grid-cols-2 gap-4">
                        <label class="block">
                            <span class="text-xs font-medium text-slate-600">Username <span class="text-red-500">*</span></span>
                            <input type="text" name="ERP_DB_USERNAME" value="{{ old('ERP_DB_USERNAME', $data['ERP_DB_USERNAME'] ?? '') }}" required
                                   class="mt-1.5 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                        </label>
                        <label class="block">
                            <span class="text-xs font-medium text-slate-600">Password <span class="text-red-500">*</span></span>
                            <input type="password" name="ERP_DB_PASSWORD" value="{{ old('ERP_DB_PASSWORD', $data['ERP_DB_PASSWORD'] ?? '') }}" required
                                   class="mt-1.5 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                                   placeholder="••••••••••">
                        </label>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <label class="block">
                            <span class="text-xs font-medium text-slate-600">SSL mode</span>
                            <select name="ERP_DB_SSLMODE"
                                    class="mt-1.5 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                                @foreach (['prefer' => 'prefer (default)', 'require' => 'require (encrypted, self-signed OK)', 'disable' => 'disable'] as $m => $label)
                                    <option value="{{ $m }}" {{ old('ERP_DB_SSLMODE', $data['ERP_DB_SSLMODE'] ?? 'prefer') === $m ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-medium text-slate-600">Schema</span>
                            <input type="text" name="ERP_DB_SCHEMA" value="{{ old('ERP_DB_SCHEMA', $data['ERP_DB_SCHEMA'] ?? '') }}"
                                   class="mt-1.5 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                                   placeholder="public">
                        </label>
                    </div>
                </div>

                {{-- Optional second data source (ERP2_*): for clients running
                     two systems. Both sync into the same warehouse and every
                     report combines them (see App\Services\DataSource). --}}
                <div class="pt-4 mt-2 border-t border-slate-200 space-y-4">
                    <div>
                        <h3 class="text-sm font-semibold text-slate-900">Second data source <span class="text-slate-400 font-normal">(optional)</span></h3>
                        <p class="text-xs text-slate-500 mt-1">If the client runs two systems side by side, connect the second one here. Leave as "None" for a single source; it can be added later in Settings.</p>
                    </div>
                    <div class="space-y-4">
                        <label class="block">
                            <span class="text-xs font-medium text-slate-600">Second system</span>
                            <select name="ERP2_TYPE" data-erp-type-select="2" class="{{ $in }}">
                                @foreach (\App\Http\Controllers\Admin\TenantController::erp2Types() as $key => $label)
                                    <option value="{{ $key }}" {{ $erp2Type === $key ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block" data-erp-name-field="2">
                            <span class="text-xs font-medium text-slate-600">ERP name <span class="text-red-500">*</span></span>
                            <input type="text" name="ERP2_NAME" value="{{ old('ERP2_NAME', $data['ERP2_NAME'] ?? '') }}" class="{{ $in }}" placeholder="Name of the second system">
                        </label>
                    </div>

                    <div class="space-y-4" data-erp-section="odoo" data-erp-group="2">
                        <label class="block"><span class="text-xs font-medium text-slate-600">Odoo URL <span class="text-red-500">*</span></span>
                            <input type="url" name="ERP2_ODOO_URL" value="{{ old('ERP2_ODOO_URL', $data['ERP2_ODOO_URL'] ?? '') }}" required class="{{ $in }}" placeholder="https://odoo.example.com"></label>
                        <label class="block"><span class="text-xs font-medium text-slate-600">Database Name <span class="text-red-500">*</span></span>
                            <input type="text" name="ERP2_ODOO_DB" value="{{ old('ERP2_ODOO_DB', $data['ERP2_ODOO_DB'] ?? '') }}" required class="{{ $in }}"></label>
                        <label class="block"><span class="text-xs font-medium text-slate-600">Username <span class="text-red-500">*</span></span>
                            <input type="text" name="ERP2_ODOO_USERNAME" value="{{ old('ERP2_ODOO_USERNAME', $data['ERP2_ODOO_USERNAME'] ?? '') }}" required class="{{ $in }}"></label>
                        <label class="block"><span class="text-xs font-medium text-slate-600">API Key <span class="text-red-500">*</span></span>
                            <input type="password" name="ERP2_ODOO_API_KEY" value="{{ old('ERP2_ODOO_API_KEY', $data['ERP2_ODOO_API_KEY'] ?? '') }}" required class="{{ $in }}" placeholder="••••••••••"></label>
                        <label class="block"><span class="text-xs font-medium text-slate-600">Protocol <span class="text-red-500">*</span></span>
                            <select name="ERP2_ODOO_PROTOCOL" required class="{{ $in }}">
                                @foreach (['json2' => 'JSON-RPC v2', 'jsonrpc' => 'JSON-RPC', 'xmlrpc' => 'XML-RPC'] as $v => $l)
                                    <option value="{{ $v }}" {{ old('ERP2_ODOO_PROTOCOL', $data['ERP2_ODOO_PROTOCOL'] ?? 'json2') === $v ? 'selected' : '' }}>{{ $l }}</option>
                                @endforeach
                            </select></label>
                    </div>

                    <div class="space-y-4" data-erp-section="db" data-erp-group="2">
                        <label class="block"><span class="text-xs font-medium text-slate-600">Database engine <span class="text-red-500">*</span></span>
                            <select name="ERP2_DB_DRIVER" required class="{{ $in }}">
                                @foreach (['pgsql' => 'PostgreSQL', 'mysql' => 'MySQL / MariaDB', 'sqlsrv' => 'SQL Server'] as $d => $label)
                                    <option value="{{ $d }}" {{ old('ERP2_DB_DRIVER', $data['ERP2_DB_DRIVER'] ?? 'pgsql') === $d ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select></label>
                        <div class="grid grid-cols-2 gap-4">
                            <label class="block"><span class="text-xs font-medium text-slate-600">Host <span class="text-red-500">*</span></span>
                                <input type="text" name="ERP2_DB_HOST" value="{{ old('ERP2_DB_HOST', $data['ERP2_DB_HOST'] ?? '') }}" required class="{{ $in }}" placeholder="db.example.com"></label>
                            <label class="block"><span class="text-xs font-medium text-slate-600">Port <span class="text-red-500">*</span></span>
                                <input type="number" name="ERP2_DB_PORT" value="{{ old('ERP2_DB_PORT', $data['ERP2_DB_PORT'] ?? '') }}" required min="1" max="65535" class="{{ $in }}" placeholder="5432"></label>
                        </div>
                        <label class="block"><span class="text-xs font-medium text-slate-600">Database name <span class="text-red-500">*</span></span>
                            <input type="text" name="ERP2_DB_DATABASE" value="{{ old('ERP2_DB_DATABASE', $data['ERP2_DB_DATABASE'] ?? '') }}" required class="{{ $in }}"></label>
                        <div class="grid grid-cols-2 gap-4">
                            <label class="block"><span class="text-xs font-medium text-slate-600">Username <span class="text-red-500">*</span></span>
                                <input type="text" name="ERP2_DB_USERNAME" value="{{ old('ERP2_DB_USERNAME', $data['ERP2_DB_USERNAME'] ?? '') }}" required class="{{ $in }}"></label>
                            <label class="block"><span class="text-xs font-medium text-slate-600">Password <span class="text-red-500">*</span></span>
                                <input type="password" name="ERP2_DB_PASSWORD" value="{{ old('ERP2_DB_PASSWORD', $data['ERP2_DB_PASSWORD'] ?? '') }}" required class="{{ $in }}" placeholder="••••••••••"></label>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <label class="block"><span class="text-xs font-medium text-slate-600">SSL mode</span>
                                <select name="ERP2_DB_SSLMODE" class="{{ $in }}">
                                    @foreach (['prefer' => 'prefer (default)', 'require' => 'require (encrypted, self-signed OK)', 'disable' => 'disable'] as $m => $label)
                                        <option value="{{ $m }}" {{ old('ERP2_DB_SSLMODE', $data['ERP2_DB_SSLMODE'] ?? 'prefer') === $m ? 'selected' : '' }}>{{ $label }}</option>
                                    @endforeach
                                </select></label>
                            <label class="block"><span class="text-xs font-medium text-slate-600">Schema</span>
                                <input type="text" name="ERP2_DB_SCHEMA" value="{{ old('ERP2_DB_SCHEMA', $data['ERP2_DB_SCHEMA'] ?? '') }}" class="{{ $in }}" placeholder="public"></label>
                        </div>
                    </div>
                </div>

                <script>
                (function () {
                    // One handler for both sources: group 1 is the primary
                    // system, group 2 the optional second one ("none" hides
                    // both of its credential blocks).
                    function syncGroup(sel) {
                        const g = sel.dataset.erpTypeSelect, type = sel.value;
                        const isOdoo = type === 'odoo', off = type === 'none' || type === '';
                        document.querySelectorAll('[data-erp-section][data-erp-group="' + g + '"]').forEach(el => {
                            const show = !off && ((el.dataset.erpSection === 'odoo') === isOdoo);
                            el.classList.toggle('hidden', !show);
                            // Disable hidden inputs so their `required`
                            // attributes don't block submission and their
                            // values aren't submitted.
                            el.querySelectorAll('input, select').forEach(i => { i.disabled = !show; });
                        });
                        const nameField = document.querySelector('[data-erp-name-field="' + g + '"]');
                        if (nameField) {
                            const hide = isOdoo || off;
                            nameField.classList.toggle('hidden', hide);
                            const input = nameField.querySelector('input');
                            if (input) { input.disabled = hide; input.required = type === 'other'; }
                        }
                    }
                    document.querySelectorAll('[data-erp-type-select]').forEach(sel => {
                        sel.addEventListener('change', () => syncGroup(sel));
                        syncGroup(sel);
                    });
                })();
                </script>

                <div class="flex gap-3 pt-4 border-t border-slate-200">
                    <button type="submit" name="action" value="prev" class="px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 rounded-lg transition">
                        ← Back
                    </button>
                    <button type="submit" name="skip_integration" value="1" class="px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 rounded-lg transition">
                        Skip for now
                    </button>
                    <button type="submit" name="action" value="next" class="ml-auto px-6 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-medium rounded-lg text-sm transition">
                        Continue →
                    </button>
                </div>
            </form>
        @else
            <h2 class="text-lg font-bold text-slate-900 mb-2">Odoo Integration Setup</h2>
            <p class="text-sm text-slate-600 mb-6">The selected services do not require Odoo integration at this time. You can configure it later in the settings.</p>

            <form method="POST" action="/admin/tenants/wizard-step" class="space-y-4">
                @csrf
                <input type="hidden" name="step" value="4">
                <input type="hidden" name="action" value="next">

                <div class="flex gap-3 pt-4 border-t border-slate-200">
                    <button type="submit" name="action" value="prev" class="px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 rounded-lg transition">
                        ← Back
                    </button>
                    <button type="submit" name="action" value="next" class="ml-auto px-6 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-medium rounded-lg text-sm transition">
                        Continue →
                    </button>
                </div>
            </form>
        @endif
    </div>
</div>
@endsection
