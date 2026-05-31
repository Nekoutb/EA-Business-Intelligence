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
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;

/**
 * Tenant management for super admins: list, create, suspend/activate,
 * toggle modules, override settings, manage users, impersonate.
 */
class TenantController extends Controller
{
    /**
     * Settings keys exposed in the per-tenant ADMIN settings UI.
     *
     * AI provider keys (AI_PROVIDER / AI_MODEL / AI_API_KEY) are intentionally
     * absent: those are platform-global and live in `global_settings`, edited
     * via /admin/settings/ai. Each tenant DOES keep its own Odoo connection.
     *
     * Budget benchmarks (BUDGET_MONTHLY_*) are intentionally absent here:
     * they're operational figures the tenant owner updates against their
     * own Odoo data, so they live exclusively on the tenant Settings page
     * (/t/{slug}/settings), not in the super-admin override view.
     */
    private const SETTING_FIELDS = [
        'ODOO_URL', 'ODOO_DB', 'ODOO_USERNAME', 'ODOO_API_KEY', 'ODOO_PROTOCOL',
        'REVENUE_SOURCE', 'APP_BRAND',
    ];

    public function index(Request $request)
    {
        $q       = trim((string) $request->query('q', ''));
        $status  = (string) $request->query('status', '');
        // Default tab is 'bms' so the operator never sees the demo
        // sandbox tenants mixed in with the real production ones.
        // Visitor demos can balloon to dozens/day during prospecting
        // and would otherwise drown out the real tenants.
        $type    = (string) $request->query('type', 'bms');
        if (!in_array($type, ['bms', 'demo', 'all'], true)) {
            $type = 'bms';
        }

        // Resilience: the BMS/Demo split depends on the is_demo column
        // added by 2026_05_15_000001. If that migration hasn't run on
        // this environment yet, fall back to a single ungated list so
        // the page never 500s. (This is what caused the production 500
        // — the demo migration hadn't run.)
        $hasDemoColumn = \Illuminate\Support\Facades\Schema::hasColumn('tenants', 'is_demo');

        $base = Tenant::query();
        if ($q !== '') {
            $base->where(fn ($w) => $w->where('slug', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%"));
        }
        if ($status !== '') {
            $base->where('status', $status);
        }

        if ($hasDemoColumn) {
            // Independent counts for each tab so the user sees the
            // population sizes at a glance (with the active filter
            // applied — so a search for "acme" updates both counts).
            $bmsCount  = (clone $base)->where(fn ($w) => $w->where('is_demo', false)->orWhereNull('is_demo'))->count();
            $demoCount = (clone $base)->where('is_demo', true)->count();
            $allCount  = $bmsCount + $demoCount;

            // Apply the active tab's filter.
            $query = (clone $base)->withCount(['users', 'modules' => fn ($q) => $q->where('enabled', true)]);
            if ($type === 'bms') {
                $query->where(fn ($w) => $w->where('is_demo', false)->orWhereNull('is_demo'));
            } elseif ($type === 'demo') {
                $query->where('is_demo', true);
            }

            // Demo tenants are ordered by expiry (most recent first)
            // so the live sandboxes float to the top; BMS tenants by
            // creation date desc (most recent customer wins first).
            if ($type === 'demo') {
                $query->orderByRaw('CASE WHEN demo_expires_at IS NULL THEN 1 ELSE 0 END')
                      ->orderByDesc('demo_expires_at');
            } else {
                $query->orderBy('id', 'desc');
            }
        } else {
            // No is_demo column — degrade to a single list, all tenants.
            $type      = 'all';
            $allCount  = (clone $base)->count();
            $bmsCount  = $allCount;
            $demoCount = 0;
            $query     = (clone $base)
                ->withCount(['users', 'modules' => fn ($q) => $q->where('enabled', true)])
                ->orderBy('id', 'desc');
        }

        $tenants = $query->paginate(25)->withQueryString();

        return view('admin.tenants.index', [
            'tenants'   => $tenants,
            'q'         => $q,
            'status'    => $status,
            'type'      => $type,
            'bmsCount'  => $bmsCount,
            'demoCount' => $demoCount,
            'allCount'  => $allCount,
            'active'    => 'tenants',
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

        // Apply industry defaults if industry is specified.
        // Guard against a null industry relation: industry_id can be
        // set to an id that no longer resolves (soft-deleted industry,
        // or the industries table not seeded on this environment). In
        // that case fall back to wizard-provided services rather than
        // calling ->key on null (which was a 500 source).
        $industry = $tenant->industry_id ? $tenant->industry : null;
        if ($industry) {
            $bundle = config("industries.bundles.{$industry->key}", []);
            $bundleModules       = $bundle['modules'] ?? [];
            $bundleServices      = $bundle['services'] ?? [];
            $selectedServiceKeys = collect($bundleServices)->flip();
        } else {
            // Fallback: use wizard-provided services (backward compat
            // + the no-industry / unresolvable-industry case).
            $selectedServiceKeys = collect($services)->flip();
            $bundleModules       = [];
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

        // Save integration settings if provided.
        // NB: previous version had an operator-precedence bug —
        //   `!empty($integration) && !$integration['skip_integration'] ?? false`
        // parses as `(... && !$integration['skip_integration']) ?? false`
        // and accesses a possibly-undefined key. Rewritten explicitly.
        $skipIntegration = (bool) ($integration['skip_integration'] ?? false);
        if (!empty($integration) && !$skipIntegration) {
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

    /**
     * Test the saved Odoo credentials for a specific tenant.
     * Temporarily binds the tenant context so OdooFactory::fromConfig()
     * reads from THAT tenant's settings (not the request session's).
     */
    public function testOdoo(Tenant $tenant)
    {
        /** @var \App\Tenancy\TenantContext $ctx */
        $ctx = app(\App\Tenancy\TenantContext::class);
        $previous = $ctx->has() ? $ctx->tenant() : null;
        $ctx->set($tenant);
        try {
            $payload = \App\Services\OdooConnectionTester::testCurrent();
        } finally {
            // Restore prior context so subsequent admin requests aren't tainted.
            if ($previous) {
                $ctx->set($previous);
            }
        }
        return response()->json($payload);
    }

    /**
     * Toggle a Business Intelligence Dashboard KPI on/off for a tenant.
     * Mirrors toggleModule() — but at finer granularity (one card on the
     * Dashboard page rather than a whole module / page).
     */
    public function toggleDashboardKpi(Request $request, Tenant $tenant, string $kpiKey)
    {
        // Validate the kpi_key against the registry so we don't silently
        // accept random strings.
        $known = collect(config('dashboard_kpis.kpis', []))->pluck('key')->all();
        abort_unless(in_array($kpiKey, $known, true), 404, 'Unknown dashboard KPI');

        $row = \App\Models\TenantDashboardKpi::firstOrNew([
            'tenant_id' => $tenant->id,
            'kpi_key'   => $kpiKey,
        ]);
        $row->enabled    = !$row->exists ? true : !$row->enabled; // first toggle = on
        $row->enabled_at = $row->enabled ? now() : null;
        $row->enabled_by = $request->attributes->get('admin_user')?->id;
        $row->save();

        AuditLog::record('tenant.dashboard_kpi.toggle', [
            'tenant_id' => $tenant->id,
            'target'    => $tenant->slug . ':' . $kpiKey,
            'payload'   => ['enabled' => $row->enabled],
        ]);

        return back()->with('flash', [
            'type' => 'success',
            'msg'  => "KPI '{$kpiKey}' " . ($row->enabled ? 'enabled' : 'disabled') . '.',
        ]);
    }

    /**
     * Rename a tenant — change display name, brand, plan and/or URL slug.
     *
     * Slug used to be intentionally immutable; admins now need to be able
     * to repair an unfortunate slug after the fact. Trade-off:
     *   • Bookmarks for the old `/t/{old-slug}/...` 404 immediately. Tenant
     *     users with an active session lose their tenant-scoped page until
     *     they re-navigate; admin URLs (this page) use the tenant id so
     *     the admin's session is unaffected.
     *   • Audit history is preserved — old slugs remain in past audit log
     *     payloads (target column captures the slug at the time of the
     *     event), and this rename creates a new audit row carrying the
     *     before/after slug so the trail is reconstructible.
     *
     * Slug validation:
     *   • lowercase a-z, 0-9, hyphens; must start with a letter; 3..64 chars
     *   • unique across `tenants.slug` (DB has a unique index)
     *   • a small reserved-words list to prevent accidents (e.g. "admin")
     */
    public function rename(Request $request, Tenant $tenant)
    {
        $data = $request->validate([
            'name'  => ['required', 'string', 'max:191'],
            'brand' => ['nullable', 'string', 'max:191'],
            'plan'  => ['nullable', 'string', 'max:32'],
            'slug'  => [
                'nullable', 'string', 'min:3', 'max:64',
                'regex:/^[a-z][a-z0-9\-]+[a-z0-9]$/',
                Rule::notIn(['admin', 'api', 'login', 'logout', 'register',
                             'settings', 'users', 't', 'tenant', 'tenants',
                             'public', 'storage', 'health']),
                Rule::unique('tenants', 'slug')->ignore($tenant->id),
            ],
        ], [
            'slug.regex' => 'Slug must start with a letter, contain only lowercase letters, digits and hyphens, and end alphanumerically.',
            'slug.not_in' => 'That slug is reserved and cannot be used.',
        ]);

        $before = [
            'name'  => $tenant->name,
            'brand' => $tenant->brand,
            'plan'  => $tenant->plan,
            'slug'  => $tenant->slug,
        ];

        $newSlug = !empty($data['slug']) && $data['slug'] !== $tenant->slug
            ? $data['slug']
            : $tenant->slug;

        $tenant->update([
            'name'  => $data['name'],
            'brand' => $data['brand'] ?? $tenant->brand,
            'plan'  => $data['plan']  ?? $tenant->plan,
            'slug'  => $newSlug,
        ]);

        // Mirror APP_BRAND on tenant_settings so the tenant-facing
        // sidebar / login page shows the new name.
        if (!empty($data['brand'])) {
            TenantSetting::updateOrCreate(
                ['tenant_id' => $tenant->id, 'key' => 'APP_BRAND'],
                ['value' => $data['brand']]
            );
        }

        AuditLog::record('tenant.rename', [
            'tenant_id' => $tenant->id,
            'target'    => $tenant->slug,
            'payload'   => ['before' => $before, 'after' => [
                'name'  => $tenant->name,
                'brand' => $tenant->brand,
                'plan'  => $tenant->plan,
                'slug'  => $tenant->slug,
            ]],
        ]);

        $slugChanged = $before['slug'] !== $tenant->slug;
        $msg = $slugChanged
            ? "Tenant renamed to '{$tenant->name}' and slug changed to '{$tenant->slug}'. Bookmarks for the old slug will 404."
            : "Tenant renamed to '{$tenant->name}'.";

        return back()->with('flash', [
            'type' => 'success',
            'msg'  => $msg,
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

    /**
     * Delete a tenant. Two modes:
     *   - default (soft delete): sets deleted_at, tenant disappears
     *     from queries but everything is preserved. Reversible by
     *     a DB-level UPDATE if needed.
     *   - force=1: hard delete, cascades to users/modules/services/
     *     settings/business_insights/audit_logs. IRREVERSIBLE.
     *
     * Both modes require the operator to TYPE the tenant slug in
     * the confirmation field — prevents accidental clicks. The
     * Suspend button stays the recommended path for "we want to
     * stop them using the BMS but keep the data"; Delete is for
     * "this tenant is gone, purge it".
     */
    public function destroy(Request $request, Tenant $tenant)
    {
        $request->validate([
            // Operator must type the slug character-for-character.
            'confirm_slug' => ['required', 'string', 'in:' . $tenant->slug],
            'force'        => ['nullable', 'boolean'],
        ], [
            'confirm_slug.in' => 'The slug you typed does not match. Type exactly "' . $tenant->slug . '" to confirm.',
        ]);

        $force      = $request->boolean('force');
        $slug       = $tenant->slug;
        $name       = $tenant->name;
        $tenantId   = $tenant->id;
        $adminId    = $request->attributes->get('admin_user')?->id;

        try {
            if ($force) {
                // Hard delete — cascading FK constraints clean up
                // tenant_modules, tenant_services, tenant_settings,
                // tenant_dashboard_kpis, demo_sessions automatically.
                // Users have nullOnDelete (their tenant_id goes null
                // — orphan supers / dual-tenant edge case) so we
                // explicitly delete tenant-scoped users first.
                \DB::transaction(function () use ($tenant) {
                    // Tenant-scoped users first
                    \App\Models\User::where('tenant_id', $tenant->id)->delete();
                    // BusinessInsight (no FK cascade in some envs)
                    \App\Models\BusinessInsight::where('tenant_id', $tenant->id)->delete();
                    // Audit logs scoped to the tenant (keep platform-
                    // wide audit logs untouched).
                    AuditLog::where('tenant_id', $tenant->id)->delete();
                    // The tenant itself, force-delete past soft-delete
                    $tenant->forceDelete();
                });
                $action = 'tenant.force_delete';
                $msg    = "Tenant '{$slug}' permanently deleted. All related data purged.";
            } else {
                $tenant->delete();
                $action = 'tenant.soft_delete';
                $msg    = "Tenant '{$slug}' soft-deleted. Data preserved; reverse via DB if needed.";
            }
        } catch (\Throwable $e) {
            return back()->with('flash', [
                'type' => 'error',
                'msg'  => "Delete failed: " . $e->getMessage(),
            ]);
        }

        // Audit log (platform-wide — tenant_id null since the tenant
        // is gone). Use a direct DB insert because the AuditLog
        // helper may try to relate to the deleted tenant.
        \DB::table('audit_logs')->insert([
            'tenant_id'  => null,
            'user_id'    => $adminId,
            'action'     => $action,
            'target'     => "tenant:{$slug}",
            'payload'    => json_encode([
                'tenant_id' => $tenantId,
                'slug'      => $slug,
                'name'      => $name,
                'force'     => $force,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return redirect('/admin/tenants')->with('flash', [
            'type' => 'success',
            'msg'  => $msg,
        ]);
    }
}
