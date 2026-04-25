<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\TenantModule;
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
    /** Settings keys exposed in the per-tenant settings UI. */
    private const SETTING_FIELDS = [
        'ODOO_URL', 'ODOO_DB', 'ODOO_USERNAME', 'ODOO_API_KEY', 'ODOO_PROTOCOL',
        'REVENUE_SOURCE', 'APP_BRAND',
        'AI_PROVIDER', 'AI_MODEL', 'AI_API_KEY',
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
        return view('admin.tenants.create', [
            'modules' => config('modules.modules', []),
            'active'  => 'tenants',
        ]);
    }

    public function store(Request $request)
    {
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

        $tenant = Tenant::create([
            'slug'   => Str::lower($data['slug']),
            'name'   => $data['name'],
            'brand'  => $data['brand'] ?? $data['name'],
            'plan'   => $data['plan'] ?? 'standard',
            'status' => Tenant::STATUS_ACTIVE,
        ]);

        $selected = collect($data['modules'] ?? [])->flip();
        foreach (config('modules.modules', []) as $m) {
            $enabled = $selected->has($m['key']) || !empty($m['core']);
            TenantModule::create([
                'tenant_id'  => $tenant->id,
                'module_key' => $m['key'],
                'enabled'    => $enabled,
                'enabled_at' => $enabled ? now() : null,
                'enabled_by' => $request->attributes->get('admin_user')?->id,
            ]);
        }

        // Bootstrap an owner user.
        User::create([
            'tenant_id' => $tenant->id,
            'name'      => $data['owner_name'],
            'email'     => $data['owner_email'],
            'password'  => Hash::make($data['owner_password']),
            'role'      => User::ROLE_OWNER,
            'status'    => User::STATUS_ACTIVE,
        ]);

        AuditLog::record('tenant.create', [
            'tenant_id' => $tenant->id,
            'target'    => $tenant->slug,
            'payload'   => ['modules' => array_values((array) ($data['modules'] ?? []))],
        ]);

        return redirect("/admin/tenants/{$tenant->id}")->with('flash', [
            'type' => 'success',
            'msg'  => "Tenant '{$tenant->slug}' created.",
        ]);
    }

    public function show(Tenant $tenant)
    {
        // Build a map of (module_key => enabled bool) merged with the registry.
        $stored = $tenant->modules()->pluck('enabled', 'module_key');
        $modules = collect(config('modules.modules', []))->map(function ($m) use ($stored) {
            return $m + ['enabled' => (bool) ($stored[$m['key']] ?? false)];
        });

        $users = $tenant->users()->orderBy('id')->get();
        $audit = AuditLog::where('tenant_id', $tenant->id)->latest()->limit(20)->get();

        return view('admin.tenants.show', [
            'tenant'  => $tenant,
            'modules' => $modules,
            'users'   => $users,
            'audit'   => $audit,
            'active'  => 'tenants',
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
