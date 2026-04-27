<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Industry;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Models\TenantService;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Tenant management for super admins: list, create, suspend/activate,
 * toggle modules, override settings, manage users, impersonate.
 */
class TenantController extends Controller
{
    /**
     * Settings keys exposed in the per-tenant settings UI.
     *
     * AI provider keys (AI_PROVIDER / AI_MODEL / AI_API_KEY) are intentionally
     * absent: those are platform-global and live in `global_settings`, edited
     * via /admin/settings/ai. Each tenant DOES keep its own Odoo connection.
     */
    private const SETTING_FIELDS = [
        'ODOO_URL', 'ODOO_DB', 'ODOO_USERNAME', 'ODOO_API_KEY', 'ODOO_PROTOCOL',
        'REVENUE_SOURCE', 'APP_BRAND',
        'BUDGET_MONTHLY_REVENUE', 'BUDGET_MONTHLY_OUTFITS', 'BUDGET_MONTHLY_COGS',
        'BUDGET_MONTHLY_DISCOUNTS', 'BUDGET_MONTHLY_CUSTOMERS',
    ];

    public function index(Request $request)
    {
        $q       = trim((string) $request->query('q', ''));
        $status  = (string) $request->query('status', '');

        $query = Tenant::withCount(['users', 'modules' => fn ($q) => $q->where('enabled', true)]);
        if ($q !== '') {
            $query->where(fn ($w) => $w->where('slug', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%"));
        }
        if ($status !== '') {
            $query->where('status', $status);
        }

        $tenants = $query->orderBy('id', 'desc')->paginate(25)->withQueryString();

        return view('admin.tenants.index', [
            'tenants' => $tenants,
            'q'       => $q,
            'status'  => $status,
            'active'  => 'tenants',
        ]);
    }

    public function create()
    {
        // Cast to int explicitly. request('step', 1) returns the *string* '2'
        // when the URL has ?step=2, while the strict === comparisons below
        // expect integers. Without the cast, no branch matches and the
        // fallback redirect at the end of this method fires on every step
        // > 1, producing ERR_TOO_MANY_REDIRECTS.
        $step = (int) request('step', 1);
        $wizardData = session('wizard_data', []);
        $services = config('modules.services', []);
        $modules = config('modules.modules', []);

        if ($step === 1) {
            return view('admin.tenants.create-wizard.step-1-workspace', [
                'data'   => $wizardData['workspace'] ?? [],
                'active' => 'tenants',
            ]);
        } elseif ($step === 2) {
            return view('admin.tenants.create-wizard.step-2-owner', [
                'data'   => $wizardData['owner'] ?? [],
                'active' => 'tenants',
            ]);
        } elseif ($step === 3) {
            return view('admin.tenants.create-wizard.step-3-services', [
                'services' => $services,
                'modules'  => $modules,
                'data'     => $wizardData['services'] ?? [],
                'active'   => 'tenants',
            ]);
        } elseif ($step === 4) {
            $selectedServices = collect($wizardData['services'] ?? [])->flip();
            $needsOdoo = $selectedServices->has('business_intelligence') || $selectedServices->has('tax_compliance');
            return view('admin.tenants.create-wizard.step-4-integration', [
                'needsOdoo' => $needsOdoo,
                'data'      => $wizardData['integration'] ?? [],
                'active'    => 'tenants',
            ]);
        } elseif ($step === 5) {
            return view('admin.tenants.create-wizard.step-5-review', [
                'workspace'   => $wizardData['workspace'] ?? [],
                'owner'       => $wizardData['owner'] ?? [],
                'services'    => $wizardData['services'] ?? [],
                'integration' => $wizardData['integration'] ?? [],
                'allServices' => $services,
                'allModules'  => $modules,
                'active'      => 'tenants',
            ]);
        }

        return redirect('/admin/tenants/create?step=1');
    }

    public function wizardStep(Request $request)
    {
        $step = (int) $request->input('step', 1);
        $action = (string) $request->input('action', 'next');

        if ($action === 'cancel') {
            $request->session()->forget('wizard_data');
            return redirect('/admin/tenants')->with('flash', ['type' => 'info', 'msg' => 'Tenant creation cancelled.']);
        }

        if ($action === 'prev') {
            return redirect("/admin/tenants/create?step=" . max(1, $step - 1));
        }

        if ($action === 'next') {
            // Validate current step and save to session
            if ($step === 1) {
                $data = $request->validate([
                    'slug'        => ['required', 'string', 'max:64', 'alpha_dash', 'unique:tenants,slug'],
                    'name'        => ['required', 'string', 'max:191'],
                    'brand'       => ['nullable', 'string', 'max:191'],
                    'plan'        => ['nullable', 'string', 'max:32'],
                    'industry_id' => ['required', 'integer', 'exists:industries,id'],
                ]);
                $wizardData = $request->session()->get('wizard_data', []);
                $wizardData['workspace'] = $data;
                $request->session()->put('wizard_data', $wizardData);
            } elseif ($step === 2) {
                $data = $request->validate([
                    'owner_name'                => ['required', 'string', 'max:191'],
                    'owner_email'               => ['required', 'email'],
                    'owner_password'            => ['required', 'string', 'min:8'],
                    'owner_password_confirm'    => ['required', 'string', 'same:owner_password'],
                ]);
                $wizardData = $request->session()->get('wizard_data', []);
                $wizardData['owner'] = [
                    'owner_name'     => $data['owner_name'],
                    'owner_email'    => $data['owner_email'],
                    'owner_password' => $data['owner_password'],
                ];
                $request->session()->put('wizard_data', $wizardData);
            } elseif ($step === 3) {
                $data = $request->validate([
                    'services'   => ['array'],
                    'services.*' => ['string'],
                ]);
                $wizardData = $request->session()->get('wizard_data', []);
                $wizardData['services'] = $data['services'] ?? [];
                $request->session()->put('wizard_data', $wizardData);
            } elseif ($step === 4) {
                $selectedServices = collect(session('wizard_data.services', []))->flip();
                $needsOdoo = $selectedServices->has('business_intelligence') || $selectedServices->has('tax_compliance');

                $rules = ['skip_integration' => ['nullable', 'boolean']];
                if ($needsOdoo && !$request->has('skip_integration')) {
                    $rules = [
                        'ODOO_URL'      => ['required', 'url'],
                        'ODOO_DB'       => ['required', 'string'],
                        'ODOO_USERNAME' => ['required', 'string'],
                        'ODOO_API_KEY'  => ['required', 'string'],
                        'ODOO_PROTOCOL' => ['required', 'in:json2,jsonrpc,xmlrpc'],
                    ];
                }

                $data = $request->validate($rules);
                $wizardData = $request->session()->get('wizard_data', []);
                $wizardData['integration'] = $data;
                $request->session()->put('wizard_data', $wizardData);
            }

            $nextStep = min(5, $step + 1);
            return redirect("/admin/tenants/create?step={$nextStep}");
        }

        return back();
    }

    public function store(Request $request)
    {
        // Check if coming from wizard or legacy form
        $wizardData = session('wizard_data');

        if ($wizardData) {
            // Wizard flow - validate final submission
            $request->validate(['confirm' => ['required', 'accepted']]);

            $workspace = $wizardData['workspace'] ?? [];
            $owner = $wizardData['owner'] ?? [];
            $services = $wizardData['services'] ?? [];
            $integration = $wizardData['integration'] ?? [];

            $data = array_merge($workspace, $owner);
        } else {
            // Legacy form flow (backward compatibility)
            $data = $request->validate([
                'slug'           => ['required', 'string', 'max:64', 'alpha_dash', 'unique:tenants,slug'],
                'name'           => ['required', 'string', 'max:191'],
                'brand'          => ['nullable', 'string', 'max:191'],
                'plan'           => ['nullable', 'string', 'max:32'],
                'owner_email'    => ['required', 'email'],
                'owner_password' => ['required', 'string', 'min:8'],
                'owner_name'     => ['required', 'string', 'max:191'],
                'modules'        => ['array'],
                'modules.*'      => ['string'],
            ]);
            $services = [];
            $integration = [];
        }

        $tenant = Tenant::create([
            'slug'        => Str::lower($data['slug']),
            'name'        => $data['name'],
            'brand'       => $data['brand'] ?? $data['name'],
            'plan'        => $data['plan'] ?? 'standard',
            'status'      => Tenant::STATUS_ACTIVE,
            'industry_id' => $data['industry_id'] ?? null,
        ]);

        $adminUserId = $request->attributes->get('admin_user')?->id;

        // Apply industry defaults if industry is specified
        if ($tenant->industry_id) {
            $industry = $tenant->industry;

            // Get industry's module bundle
            $bundle = config("industries.bundles.{$industry->key}", []);
            $bundleModules = $bundle['modules'] ?? [];

            // Get industry's service bundle
            $bundleServices = $bundle['services'] ?? [];
            $selectedServiceKeys = collect($bundleServices)->flip();
        } else {
            // Fallback: use wizard-provided services (for backward compatibility)
            $selectedServiceKeys = collect($services)->flip();
            $bundleModules = [];
        }

        // Handle services
        foreach (config('modules.services', []) as $s) {
            $enabled = $selectedServiceKeys->has($s['key']);
            TenantService::create([
                'tenant_id'   => $tenant->id,
                'service_key' => $s['key'],
                'enabled'     => $enabled,
                'enabled_at'  => $enabled ? now() : null,
                'enabled_by'  => $adminUserId,
            ]);
        }

        // Create module records with industry defaults
        foreach (config('modules.modules', []) as $m) {
            $moduleKey = $m['key'];

            // Determine if module is enabled
            if ($bundleModules) {
                // Use industry bundle defaults
                $bundleDefault = $bundleModules[$moduleKey]['default'] ?? false;
                $enabled = $bundleDefault || !empty($m['core']);
            } else {
                // Fallback to registry defaults
                $serviceModules = collect(config('modules.modules', []))
                    ->filter(fn ($mod) => $selectedServiceKeys->has($mod['service'] ?? null))
                    ->pluck('key');
                $enabled = $serviceModules->contains($moduleKey) || !empty($m['core']);
            }

            TenantModule::create([
                'tenant_id'   => $tenant->id,
                'module_key'  => $moduleKey,
                'enabled'     => $enabled,
                'enabled_at'  => $enabled ? now() : null,
                'enabled_by'  => $adminUserId,
            ]);
        }

        // Bootstrap an owner user
        User::create([
            'tenant_id' => $tenant->id,
            'name'      => $data['owner_name'],
            'email'     => $data['owner_email'],
            'password'  => Hash::make($data['owner_password']),
            'role'      => User::ROLE_OWNER,
            'status'    => User::STATUS_ACTIVE,
        ]);

        // Save integration settings if provided
        if (!empty($integration) && !$integration['skip_integration'] ?? false) {
            foreach (['ODOO_URL', 'ODOO_DB', 'ODOO_USERNAME', 'ODOO_API_KEY', 'ODOO_PROTOCOL'] as $key) {
                if (!empty($integration[$key])) {
                    TenantSetting::create([
                        'tenant_id' => $tenant->id,
                        'key'       => $key,
                        'value'     => $integration[$key],
                    ]);
                }
            }
        }

        $request->session()->forget('wizard_data');

        AuditLog::record('tenant.create', [
            'tenant_id' => $tenant->id,
            'target'    => $tenant->slug,
            'payload'   => ['services' => $services],
        ]);

        return redirect("/admin/tenants/{$tenant->id}")->with('flash', [
            'type' => 'success',
            'msg'  => "Tenant '{$tenant->slug}' created.",
        ]);
    }

    public function show(Tenant $tenant)
    {
        // Merge registry + tenant flags for both services and modules.
        $storedModules  = $tenant->modules()->pluck('enabled', 'module_key');
        $storedServices = $tenant->services()->pluck('enabled', 'service_key');

        $services = collect(config('modules.services', []))
            ->sortBy('order')
            ->map(fn ($s) => $s + ['enabled' => (bool) ($storedServices[$s['key']] ?? false)])
            ->values();

        $modules = collect(config('modules.modules', []))
            ->map(fn ($m) => $m + ['enabled' => (bool) ($storedModules[$m['key']] ?? false)]);

        $users = $tenant->users()->orderBy('id')->get();
        $audit = AuditLog::where('tenant_id', $tenant->id)->latest()->limit(20)->get();

        return view('admin.tenants.show', [
            'tenant'   => $tenant,
            'services' => $services,
            'modules'  => $modules,
            'users'    => $users,
            'audit'    => $audit,
            'active'   => 'tenants',
        ]);
    }

    public function toggleService(Request $request, Tenant $tenant, string $serviceKey)
    {
        $service = collect(config('modules.services', []))->firstWhere('key', $serviceKey);
        if (!$service) {
            return back()->with('flash', ['type' => 'error', 'msg' => 'Unknown service.']);
        }

        $row = TenantService::firstOrNew(['tenant_id' => $tenant->id, 'service_key' => $serviceKey]);
        $was = (bool) $row->enabled;
        $row->enabled    = !$was;
        $row->enabled_at = $row->enabled ? now() : null;
        $row->enabled_by = $request->attributes->get('admin_user')?->id;
        $row->save();

        AuditLog::record('service.toggle', [
            'tenant_id' => $tenant->id,
            'target'    => $serviceKey,
            'payload'   => ['from' => $was, 'to' => !$was],
        ]);

        return back()->with('flash', [
            'type' => 'success',
            'msg'  => sprintf('Service "%s" is now %s.', $service['label'], $row->enabled ? 'ENABLED' : 'DISABLED'),
        ]);
    }

    public function toggleModule(Request $request, Tenant $tenant, string $moduleKey)
    {
        $module = collect(config('modules.modules', []))->firstWhere('key', $moduleKey);
        if (!$module) {
            return back()->with('flash', ['type' => 'error', 'msg' => 'Unknown module.']);
        }
        if (!empty($module['core'])) {
            return back()->with('flash', ['type' => 'error', 'msg' => 'Core modules cannot be disabled.']);
        }

        $row = TenantModule::firstOrNew(['tenant_id' => $tenant->id, 'module_key' => $moduleKey]);
        $was = (bool) $row->enabled;
        $row->enabled    = !$was;
        $row->enabled_at = $row->enabled ? now() : null;
        $row->enabled_by = $request->attributes->get('admin_user')?->id;
        $row->save();

        AuditLog::record('module.toggle', [
            'tenant_id' => $tenant->id,
            'target'    => $moduleKey,
            'payload'   => ['from' => $was, 'to' => !$was],
        ]);

        return back()->with('flash', [
            'type' => 'success',
            'msg'  => sprintf('Module "%s" is now %s.', $module['label'], $row->enabled ? 'ENABLED' : 'DISABLED'),
        ]);
    }

    public function showSettings(Tenant $tenant)
    {
        $values = [];
        $rows   = $tenant->settings()->whereIn('key', self::SETTING_FIELDS)->get();
        foreach (self::SETTING_FIELDS as $k) {
            $row = $rows->firstWhere('key', $k);
            $values[$k] = $row ? (TenantSetting::shouldEncrypt($k) ? '' /* never echo back secrets */ : (string) $row->value) : '';
            // Track presence of secrets so the UI can show "(set)".
            $values["__has_{$k}"] = $row !== null && $row->value !== null && $row->value !== '';
        }
        return view('admin.tenants.settings', [
            'tenant' => $tenant,
            'values' => $values,
            'fields' => self::SETTING_FIELDS,
            'active' => 'tenants',
        ]);
    }

    public function saveSettings(Request $request, Tenant $tenant)
    {
        $changed = [];
        foreach (self::SETTING_FIELDS as $k) {
            if (!$request->has($k)) continue;
            $val = trim((string) $request->input($k));

            // Leave secrets untouched when the field was submitted blank.
            if (TenantSetting::shouldEncrypt($k) && $val === '') {
                continue;
            }

            $row = TenantSetting::firstOrNew(['tenant_id' => $tenant->id, 'key' => $k]);
            $row->value = $val;
            $row->save();
            $changed[] = $k;
        }

        AuditLog::record('settings.update', [
            'tenant_id' => $tenant->id,
            'target'    => $tenant->slug,
            'payload'   => ['keys' => $changed],
        ]);

        return back()->with('flash', [
            'type' => 'success',
            'msg'  => 'Settings updated (' . count($changed) . ' fields).',
        ]);
    }

    public function suspend(Tenant $tenant)
    {
        $tenant->update(['status' => Tenant::STATUS_SUSPENDED]);
        AuditLog::record('tenant.suspend', ['tenant_id' => $tenant->id, 'target' => $tenant->slug]);
        return back()->with('flash', ['type' => 'success', 'msg' => "Tenant '{$tenant->slug}' suspended."]);
    }

    public function activate(Tenant $tenant)
    {
        $tenant->update(['status' => Tenant::STATUS_ACTIVE]);
        AuditLog::record('tenant.activate', ['tenant_id' => $tenant->id, 'target' => $tenant->slug]);
        return back()->with('flash', ['type' => 'success', 'msg' => "Tenant '{$tenant->slug}' reactivated."]);
    }
}
