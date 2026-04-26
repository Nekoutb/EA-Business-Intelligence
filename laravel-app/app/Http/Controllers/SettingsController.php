<?php

namespace App\Http\Controllers;

use App\Models\TenantSetting;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

/**
 * Tenant-scoped Settings page.
 *
 * Persists changes into the active tenant's `tenant_settings` row so values
 * survive restarts AND stay isolated between workspaces. Secrets are
 * encrypted at rest by the TenantSetting model.
 *
 * AI provider configuration is intentionally absent here — it lives in
 * `global_settings` and is managed by super admins only via /admin/settings/ai.
 */
class SettingsController extends Controller
{
    /**
     * Keys exposed in the tenant Settings UI.
     *
     * Notably excludes AI_PROVIDER / AI_MODEL / AI_API_KEY: those are
     * platform-global and managed in the super-admin backend.
     */
    private const FIELDS = [
        'ODOO_URL', 'ODOO_DB', 'ODOO_USERNAME', 'ODOO_API_KEY', 'ODOO_PROTOCOL',
        'REVENUE_SOURCE', 'APP_BRAND',
        'BUDGET_MONTHLY_REVENUE', 'BUDGET_MONTHLY_OUTFITS', 'BUDGET_MONTHLY_COGS',
        'BUDGET_MONTHLY_DISCOUNTS', 'BUDGET_MONTHLY_CUSTOMERS',
    ];

    public function show()
    {
        $tenant = $this->requireTenant();

        $values = [];
        $rows   = $tenant->settings()->whereIn('key', self::FIELDS)->get();
        foreach (self::FIELDS as $k) {
            $row = $rows->firstWhere('key', $k);
            // Never echo secrets back into the form.
            $values[$k] = $row && !TenantSetting::shouldEncrypt($k) ? (string) $row->value : '';
            $values["__has_{$k}"] = $row !== null && $row->value !== null && $row->value !== '';
        }

        return view('settings', ['values' => $values, 'active' => null]);
    }

    public function save(Request $request)
    {
        $tenant = $this->requireTenant();

        foreach (self::FIELDS as $k) {
            if (!$request->has($k)) continue;
            $val = trim((string) $request->input($k));

            // Leave secrets untouched when the field was submitted blank.
            if (TenantSetting::shouldEncrypt($k) && $val === '') {
                continue;
            }

            $row = TenantSetting::firstOrNew(['tenant_id' => $tenant->id, 'key' => $k]);
            $row->value = $val;
            $row->save();
        }

        // Drop any cached service responses that may have been computed under
        // the previous (stale) credentials so the next page load refetches.
        ea_cache_clear();

        return redirect(tenant_url('/settings'))->with('flash', [
            'type' => 'success',
            'msg'  => 'Settings saved.',
        ]);
    }

    /**
     * The Settings page only makes sense inside a tenant scope; if the
     * resolver didn't bind one, fail loudly rather than silently writing
     * the changes nowhere.
     */
    private function requireTenant(): \App\Models\Tenant
    {
        $ctx = app(TenantContext::class);
        if (!$ctx->has()) {
            abort(404, 'No tenant in scope for settings.');
        }
        return $ctx->tenant();
    }
}
