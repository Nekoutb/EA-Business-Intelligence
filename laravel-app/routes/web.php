<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\RevenueController;
use App\Http\Controllers\ReceivablesController;
use App\Http\Controllers\ProfitLossController;
use App\Http\Controllers\PayablesController;
use App\Http\Controllers\TreasuryController;
use App\Http\Controllers\FixedAssetsController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\GLQualityController;
use App\Http\Controllers\CustomerInsightsController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\Tax\VATComplianceController;
use App\Http\Controllers\Tax\VATInvoiceComplianceController;
use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\TenantController as AdminTenantController;
use App\Models\Tenant;
use Illuminate\Support\Facades\Route;

/* ── Root: send the visitor to the right tenant ───────────────────────── */
Route::get('/', function () {
    // Already logged in? Honour the bound tenant.
    $slug = session('bi_tenant_slug');
    if ($slug) {
        return redirect("/t/{$slug}");
    }
    // Otherwise pick a sensible default (single-tenant deploys land on the only one).
    $only = Tenant::where('status', '!=', Tenant::STATUS_SUSPENDED)->orderBy('id')->value('slug');
    return $only ? redirect("/t/{$only}/login") : abort(404, 'No tenants provisioned.');
});

/* ── Legacy single-tenant login (kept as a redirect for old bookmarks) ── */
Route::get('/login', function () {
    $slug = session('bi_tenant_slug')
        ?: Tenant::where('status', '!=', Tenant::STATUS_SUSPENDED)->orderBy('id')->value('slug');
    return $slug ? redirect("/t/{$slug}/login") : abort(404);
});

/* ── Super Admin backend ─────────────────────────────────────────────── */
Route::prefix('admin')->group(function () {
    Route::get('/login',  [AdminAuthController::class, 'showLogin']);
    Route::post('/login', [AdminAuthController::class, 'login']);
    Route::get('/logout', [AdminAuthController::class, 'logout']);

    Route::middleware('admin.auth')->group(function () {
        Route::get('/', [AdminDashboardController::class, 'index']);

        Route::get('/tenants',                       [AdminTenantController::class, 'index']);
        Route::get('/tenants/create',                [AdminTenantController::class, 'create']);
        Route::post('/tenants/wizard-step',          [AdminTenantController::class, 'wizardStep']);
        Route::post('/tenants',                      [AdminTenantController::class, 'store']);
        Route::get('/tenants/{tenant}',              [AdminTenantController::class, 'show']);
        Route::post('/tenants/{tenant}/services/{serviceKey}/toggle',
                                                     [AdminTenantController::class, 'toggleService']);
        Route::post('/tenants/{tenant}/modules/{moduleKey}/toggle',
                                                     [AdminTenantController::class, 'toggleModule']);
        Route::get('/tenants/{tenant}/settings',     [AdminTenantController::class, 'showSettings']);
        Route::post('/tenants/{tenant}/settings',    [AdminTenantController::class, 'saveSettings']);
        Route::post('/tenants/{tenant}/suspend',     [AdminTenantController::class, 'suspend']);
        Route::post('/tenants/{tenant}/activate',    [AdminTenantController::class, 'activate']);
    });
});

/* ── Tenant scope ─────────────────────────────────────────────────────── */
Route::prefix('t/{tenant}')
    ->whereAlphaNumeric('tenant')
    ->middleware('tenant.resolve')
    ->group(function () {

        // Public (within a tenant): login/logout
        Route::get('/login',  [AuthController::class, 'showLogin'])->name('login');
        Route::post('/login', [AuthController::class, 'login']);
        Route::get('/logout', [AuthController::class, 'logout']);

        // Authenticated tenant area
        Route::middleware('bi.auth')->group(function () {
            // Onboarding wizard (shown if tenant not yet onboarded)
            Route::get('/onboarding',                [OnboardingController::class, 'show']);
            Route::post('/onboarding/step',          [OnboardingController::class, 'step']);
            Route::post('/onboarding/complete',      [OnboardingController::class, 'complete']);

            // Apply onboarding redirect middleware to all other routes
            Route::middleware('redirect.if.not.onboarded')->group(function () {
                Route::get('/',                  [DashboardController::class, 'index'])->middleware('module:dashboard');
                Route::post('/sync',             [DashboardController::class, 'sync']);

                Route::get('/revenue',           [RevenueController::class, 'index'])->middleware('module:revenue');
                Route::get('/customer-insights', [CustomerInsightsController::class, 'index'])->middleware('module:customer_insights');
                Route::get('/receivables',       [ReceivablesController::class, 'index'])->middleware('module:receivables');
                Route::get('/treasury',          [TreasuryController::class, 'index'])->middleware('module:treasury');
                Route::get('/profit-loss',       [ProfitLossController::class, 'index'])->middleware('module:profit_loss');
                Route::get('/payables',          [PayablesController::class, 'index'])->middleware('module:payables');
                Route::get('/fixed-assets',      [FixedAssetsController::class, 'index'])->middleware('module:fixed_assets');
                Route::get('/inventory',         [InventoryController::class, 'index'])->middleware('module:inventory');

                // ── Financial Reporting service ───────────────────────────────
                Route::get('/general-ledger-quality', [GLQualityController::class, 'index'])->middleware('module:gl_quality');
                // Avoid a dotted extension in the URL — PHP's built-in dev server
                // treats anything with a "." as a static file and serves a 404
                // before Laravel's router sees the request.
                Route::get('/general-ledger-quality/description-report',
                    [GLQualityController::class, 'descriptionReport'])->middleware('module:gl_quality');
                Route::get('/general-ledger-quality/manual-entry-report',
                    [GLQualityController::class, 'manualEntryReport'])->middleware('module:gl_quality');

                // ── Tax Compliance service ────────────────────────────────────
                Route::get('/vat-compliance',         [VATComplianceController::class, 'index'])->middleware('module:vat_compliance');
                Route::get('/vat-invoice-compliance', [VATInvoiceComplianceController::class, 'index'])->middleware('module:vat_invoice_compliance');
                Route::get('/tax-calendar',           [VATComplianceController::class, 'calendar'])->middleware('module:tax_calendar');

                Route::get('/settings',          [SettingsController::class, 'show']);
                Route::post('/settings',         [SettingsController::class, 'save']);
            });
        });
    });
