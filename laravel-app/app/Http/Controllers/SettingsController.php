<?php

namespace App\Http\Controllers;

use App\Models\TenantSetting;
use App\Services\OdooConnectionTester;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
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
        // ERP selection (Odoo uses the web API; anything else connects
        // to a database the ERP/BI provider exposes)
        'ERP_TYPE', 'ERP_NAME',
        // Odoo connection
        'ODOO_URL', 'ODOO_DB', 'ODOO_USERNAME', 'ODOO_API_KEY', 'ODOO_PROTOCOL',
        // Direct ERP database connection (non-Odoo ERPs)
        'ERP_DB_DRIVER', 'ERP_DB_HOST', 'ERP_DB_PORT', 'ERP_DB_DATABASE',
        'ERP_DB_USERNAME', 'ERP_DB_PASSWORD', 'ERP_DB_SSLMODE', 'ERP_DB_SCHEMA',
        'REVENUE_SOURCE', 'APP_BRAND',
        // Existing budget benchmarks (kept)
        'BUDGET_MONTHLY_REVENUE', 'BUDGET_MONTHLY_OUTFITS', 'BUDGET_MONTHLY_COGS',
        'BUDGET_MONTHLY_DISCOUNTS', 'BUDGET_MONTHLY_CUSTOMERS',
        // New P&L-line-item budget benchmarks (added 2026-04-28)
        'BUDGET_MONTHLY_DIRECT_COSTS', 'BUDGET_MONTHLY_INDIRECT_COSTS',
        'BUDGET_MONTHLY_DA', 'BUDGET_MONTHLY_INCOME_TAX',
        'BUDGET_MONTHLY_COST_OF_SALES',
        // Scanning operations budget — feeds dashboard F1 (Scanned Revenue vs Budget)
        'BUDGET_MONTHLY_SCANNED_REVENUE',
        // Tax declarant info (DGI I/TVA-IR header) — added 2026-05-02
        'TAX_DGE_CRI', 'TAX_CDI_CSI_CIME',
        'TAX_REGIME', 'TAX_NIU',
        'TAX_RAISON_SOCIALE', 'TAX_SIGLE',
        'TAX_VILLE', 'TAX_COMMUNE', 'TAX_QUARTIER', 'TAX_LIEU_DIT',
        'TAX_BP', 'TAX_TEL_FIXE', 'TAX_TEL_MOBILE', 'TAX_EMAIL', 'TAX_FAX',
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

        // ── Top-level tenant fields (brand name, ETP) live on the
        // tenants row itself, not in tenant_settings. They drive the
        // company badge in the topbar so they need to be queryable
        // without a settings join.
        if ($request->filled('TENANT_BRAND')) {
            $tenant->brand = trim((string) $request->input('TENANT_BRAND'));
        }
        if ($request->filled('TENANT_NAME')) {
            $tenant->name = trim((string) $request->input('TENANT_NAME'));
        }

        // ── Logo upload — stored under public/uploads/tenant-logos/
        // per the migration's `// public/uploads/<file>` convention.
        // Validation: PNG/JPG/SVG/WebP, ≤ 2 MB. SVG accepted because
        // it scales cleanly into the topbar at any DPR.
        if ($request->hasFile('logo')) {
            $request->validate([
                'logo' => 'file|mimetypes:image/png,image/jpeg,image/svg+xml,image/webp|max:2048',
            ]);

            $file    = $request->file('logo');
            $ext     = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'png');
            $dir     = public_path('uploads/tenant-logos');
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $name    = $tenant->id . '-' . substr(md5(microtime(true)), 0, 8) . '.' . $ext;
            $file->move($dir, $name);

            // Best-effort delete of the previous file so we don't
            // accumulate orphans; ignore failures (path may have
            // moved between deploys).
            if ($tenant->logo_path) {
                $old = public_path(ltrim((string) $tenant->logo_path, '/'));
                if (is_file($old)) @unlink($old);
            }

            $tenant->logo_path = 'uploads/tenant-logos/' . $name;
        }

        // Allow explicit logo removal via a hidden flag.
        if ($request->boolean('remove_logo') && $tenant->logo_path) {
            $old = public_path(ltrim((string) $tenant->logo_path, '/'));
            if (is_file($old)) @unlink($old);
            $tenant->logo_path = null;
        }

        $tenant->save();

        // Drop any cached service responses that may have been computed under
        // the previous (stale) credentials so the next page load refetches.
        ea_cache_clear();

        return redirect(tenant_url('/settings'))->with('flash', [
            'type' => 'success',
            'msg'  => 'Settings saved.',
        ]);
    }

    /**
     * Test the saved Odoo credentials — round-trip authentication.
     * Returns JSON for the AJAX caller in the Settings page.
     */
    public function testOdoo(): JsonResponse
    {
        $this->requireTenant();
        return response()->json(OdooConnectionTester::testCurrent());
    }

    /**
     * Test the saved direct-database ERP credentials (non-Odoo ERPs).
     */
    public function testErpDb(): JsonResponse
    {
        $this->requireTenant();
        return response()->json(\App\Services\ErpDbConnectionTester::testCurrent());
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
