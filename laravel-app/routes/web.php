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
use App\Http\Controllers\OperationsController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\ProcurementController;
use App\Http\Controllers\GLQualityController;
use App\Http\Controllers\CustomerInsightsController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\Tax\IncomeTaxComplianceController;
use App\Http\Controllers\Tax\MonthlyVatReturnController;
use App\Http\Controllers\Tax\TaxDashboardController;
use App\Http\Controllers\Tax\VATComplianceController;
use App\Http\Controllers\Tax\VATInvoiceComplianceController;
use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AiSettingsController as AdminAiSettingsController;
use App\Http\Controllers\Admin\IndustryController as AdminIndustryController;
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

        // Industries (configuration)
        Route::get('/industries',                          [AdminIndustryController::class, 'index']);
        Route::get('/industries/create',                   [AdminIndustryController::class, 'create']);
        Route::post('/industries',                         [AdminIndustryController::class, 'store']);
        Route::get('/industries/{industry}/edit',          [AdminIndustryController::class, 'edit']);
        Route::patch('/industries/{industry}',             [AdminIndustryController::class, 'update']);
        Route::get('/industries/{industry}/edit-modules',  [AdminIndustryController::class, 'editModules']);
        Route::patch('/industries/{industry}/edit-modules',[AdminIndustryController::class, 'updateModules']);
        Route::delete('/industries/{industry}',            [AdminIndustryController::class, 'destroy']);

        Route::get('/tenants',                       [AdminTenantController::class, 'index']);
        Route::get('/tenants/create',                [AdminTenantController::class, 'create']);
        Route::post('/tenants/wizard-step',          [AdminTenantController::class, 'wizardStep']);
        Route::post('/tenants',                      [AdminTenantController::class, 'store']);
        Route::get('/tenants/{tenant}',              [AdminTenantController::class, 'show']);
        Route::post('/tenants/{tenant}/services/{serviceKey}/toggle',
                                                     [AdminTenantController::class, 'toggleService']);
        Route::post('/tenants/{tenant}/modules/{moduleKey}/toggle',
                                                     [AdminTenantController::class, 'toggleModule']);
        Route::post('/tenants/{tenant}/dashboard-kpis/{kpiKey}/toggle',
                                                     [AdminTenantController::class, 'toggleDashboardKpi']);
        Route::get ('/tenants/{tenant}/settings',           [AdminTenantController::class, 'showSettings']);
        Route::post('/tenants/{tenant}/settings',           [AdminTenantController::class, 'saveSettings']);
        Route::post('/tenants/{tenant}/settings/test-odoo', [AdminTenantController::class, 'testOdoo']);
        Route::post('/tenants/{tenant}/rename',      [AdminTenantController::class, 'rename']);
        Route::post('/tenants/{tenant}/suspend',     [AdminTenantController::class, 'suspend']);
        Route::post('/tenants/{tenant}/activate',    [AdminTenantController::class, 'activate']);

        /*
         * ── DIAGNOSTIC: Odoo Logistics schema probe ───────────────────────
         *
         * One-off introspection helper used to discover what custom models
         * back the customer's "Logistique" Odoo menu (containers, scans,
         * shipments, EDI documents, …). Output drives the design of the
         * container-related KPIs we want to add to the dashboard.
         *
         * What it does (in order):
         *   1. Resolves the Logistique top-level menu in `ir.ui.menu` and
         *      walks its children/grandchildren so we know exactly which
         *      models the user sees clicked from that menu.
         *   2. Dereferences each menu's action (`ir.actions.act_window`)
         *      to recover the underlying `res_model`.
         *   3. Cross-checks with a keyword scan of `ir.model` (container,
         *      conteneur, scan, shipment, expédition, edi, manifest, …)
         *      to catch anything not exposed via menus.
         *   4. For the union of resolved models, dumps `fields_get` and a
         *      tiny sample (limit 2) so we can see what the rows look like.
         *
         * Output is JSON — the admin pastes it back into the BI thread and
         * we use it to build container KPIs. REMOVE after schema discovery
         * is complete (no permanent value).
         */
        Route::get('/tenants/{tenant}/odoo-probe', function (\App\Models\Tenant $tenant) {
            @set_time_limit(120);

            $ctx = app(\App\Tenancy\TenantContext::class);
            $previous = $ctx->has() ? $ctx->tenant() : null;
            $ctx->set($tenant);

            $report = [
                'tenant'    => $tenant->slug,
                'probed_at' => now()->toIso8601String(),
            ];

            try {
                $client = \App\Odoo\OdooFactory::fromConfig();
                $client->authenticate();

                /* 1. Find the Logistique top-level menu and walk descendants. */
                $rootCandidates = $client->searchRead('ir.ui.menu',
                    [['name', 'in', ['Logistique', 'Logistics', 'Logistica']]],
                    ['id', 'name', 'parent_id', 'sequence', 'action', 'web_icon'],
                    ['limit' => 10, 'order' => 'sequence']
                );
                // Walk children: for each candidate, fetch direct children and (one level deeper) grandchildren.
                foreach ($rootCandidates as &$root) {
                    $children = $client->searchRead('ir.ui.menu',
                        [['parent_id', '=', $root['id']]],
                        ['id', 'name', 'sequence', 'action'],
                        ['limit' => 50, 'order' => 'sequence']
                    );
                    foreach ($children as &$ch) {
                        $grand = $client->searchRead('ir.ui.menu',
                            [['parent_id', '=', $ch['id']]],
                            ['id', 'name', 'sequence', 'action'],
                            ['limit' => 50, 'order' => 'sequence']
                        );
                        $ch['children'] = $grand;
                    }
                    unset($ch);
                    $root['children'] = $children;
                }
                unset($root);
                $report['logistique_menu_tree'] = $rootCandidates;

                /* 2. Resolve menu actions → res_model.
                 * Menu `action` looks like "ir.actions.act_window,123" or false.
                 */
                $actionIds = [];
                $walk = function (array $items) use (&$walk, &$actionIds) {
                    foreach ($items as $it) {
                        if (!empty($it['action']) && is_string($it['action']) && str_starts_with($it['action'], 'ir.actions.act_window,')) {
                            $actionIds[] = (int) substr($it['action'], strlen('ir.actions.act_window,'));
                        }
                        if (!empty($it['children'])) $walk($it['children']);
                    }
                };
                $walk($rootCandidates);
                $actionIds = array_values(array_unique(array_filter($actionIds)));

                $resolvedModels = [];
                if ($actionIds) {
                    $actions = $client->read('ir.actions.act_window', $actionIds, ['id', 'name', 'res_model', 'view_mode', 'domain']);
                    foreach ($actions as $a) {
                        if (!empty($a['res_model'])) $resolvedModels[$a['res_model']] = $a['name'] ?? $a['res_model'];
                    }
                }
                $report['models_from_menu_actions'] = $resolvedModels;

                /* 3. Keyword scan of ir.model (catches anything not menu-exposed).
                 * Extended on 2026-04-28 to also catch vehicle/wood/cargo
                 * models (cars, trucks, bois) since those have separate
                 * scanning tariffs in the customer's Grille Tarifaire. */
                $keywordHits = $client->searchRead('ir.model',
                    [
                        '|', '|', '|', '|', '|', '|', '|', '|', '|', '|', '|',
                        ['model', 'ilike', 'container'],
                        ['model', 'ilike', 'conteneur'],
                        ['model', 'ilike', 'scan'],
                        ['model', 'ilike', 'shipment'],
                        ['model', 'ilike', 'expedition'],
                        ['model', 'ilike', 'expédition'],
                        ['model', 'ilike', 'manifest'],
                        ['model', 'ilike', 'edi'],
                        ['model', 'ilike', 'vehicule'],
                        ['model', 'ilike', 'vehicle'],
                        ['model', 'ilike', 'bois'],
                        ['model', 'ilike', 'aw_'],
                    ],
                    ['id', 'model', 'name', 'transient'],
                    ['limit' => 60, 'order' => 'model']
                );
                // Drop transient/wizard models — they're not real data.
                $keywordHits = array_values(array_filter(
                    $keywordHits,
                    fn ($m) => empty($m['transient'])
                        && !str_starts_with((string) $m['model'], 'ir.')
                        && !str_starts_with((string) $m['model'], 'mail.')
                ));
                $report['models_from_keyword_scan'] = $keywordHits;

                /* 4. Union the candidates and probe each. We force the
                 * known logistics models to the front of the list so they
                 * always get schema-dumped even if the keyword scan
                 * returns more candidates than the cap. */
                $forced = ['aw_cargo.equipment', 'aw_cargo.cargo', 'aw_cargo.stopover',
                           'aw_vehicule.vehicule', 'aw_bois.bois', 'aw_cargo.equipment.type',
                           'aw_cargo_invoice.product'];
                $candidateModels = array_values(array_unique(array_merge(
                    $forced,
                    array_keys($resolvedModels),
                    array_map(fn ($m) => $m['model'], $keywordHits),
                )));

                $details = [];
                foreach (array_slice($candidateModels, 0, 16) as $modelName) {
                    try {
                        $fields = $client->callModel($modelName, 'fields_get', [
                            'attributes' => ['string', 'type', 'required', 'relation', 'help'],
                        ]);
                        // Drop noisy mixin fields so the JSON stays readable.
                        $noise = [
                            'create_uid', 'create_date', 'write_uid', 'write_date',
                            'message_ids', 'message_follower_ids', 'message_main_attachment_id',
                            'message_attachment_count', 'message_has_error', 'message_has_error_counter',
                            'message_needaction', 'message_needaction_counter', 'message_unread',
                            'message_unread_counter', 'website_message_ids', 'message_partner_ids',
                            'activity_ids', 'activity_state', 'activity_user_id', 'activity_type_id',
                            'activity_type_icon', 'activity_date_deadline', 'activity_summary',
                            'activity_exception_decoration', 'activity_exception_icon',
                            'has_message', '__last_update', 'access_url', 'access_token',
                            'rating_ids', 'access_warning',
                        ];
                        foreach ($noise as $n) unset($fields[$n]);

                        $count = $client->searchCount($modelName, []);
                        $entry = [
                            'name'   => $resolvedModels[$modelName] ?? null,
                            'count'  => $count,
                            'fields' => $fields,
                        ];
                        if ($count > 0 && $count < 200000) {
                            $sampleFieldNames = array_keys($fields);
                            // Cap sample-fields list — some custom models have 200+ fields.
                            $sampleFieldNames = array_slice($sampleFieldNames, 0, 60);
                            $entry['samples'] = $client->searchRead(
                                $modelName, [], $sampleFieldNames, ['limit' => 2, 'order' => 'id desc']
                            );
                        }
                        $details[$modelName] = $entry;
                    } catch (\Throwable $e) {
                        $details[$modelName] = ['error' => $e->getMessage()];
                    }
                }
                $report['model_details'] = $details;

                /* 5. DEEP DIVE: vehicle classification.
                 *
                 * The user wants to know whether ANY data source — outside
                 * the billing path — can tell us if a scanned record is a
                 * voiture / camion / bulldozer / OGG. We answer that by:
                 *
                 *   a. Dumping EVERY field on aw_vehicule.vehicule (no
                 *      noise filter) so nothing hides.
                 *   b. Reading 8 sample records (more than the regular 2)
                 *      so the user can see the spread of values.
                 *   c. Enumerating selection-field choices, since those
                 *      are where Odoo typically encodes categorical type.
                 *   d. Cross-model field search: any field anywhere in
                 *      Odoo whose name OR human label hints at vehicle
                 *      type / category / classification / dimensions.
                 *   e. Full dump of aw_cargo_invoice.product (the
                 *      container-billing config table) — useful to see
                 *      whether a parallel vehicle-billing table exists.
                 *   f. Inventory of every aw_* model in the install with
                 *      record counts so nothing is missed.
                 */
                $deep = [];

                try {
                    $allVehFields = $client->callModel('aw_vehicule.vehicule', 'fields_get', [
                        'attributes' => ['string', 'type', 'required', 'relation', 'help', 'selection'],
                    ]);
                    $deep['aw_vehicule.vehicule_full_fields'] = $allVehFields;

                    // Pull 8 spread-out samples so we see variety in the
                    // actually-populated columns (manufacturer, model_code,
                    // goods_group, name, etc.).
                    $deep['aw_vehicule.vehicule_samples'] = $client->searchRead(
                        'aw_vehicule.vehicule',
                        [],
                        array_slice(array_keys($allVehFields), 0, 80),
                        ['limit' => 8, 'order' => 'id desc']
                    );

                    // Selection-field choices: in modern Odoo each
                    // selection field's options live in the field's
                    // `selection` attribute (which fields_get returns).
                    // Pull them out separately for readability.
                    $vehSelections = [];
                    foreach ($allVehFields as $name => $meta) {
                        if (($meta['type'] ?? null) === 'selection') {
                            $vehSelections[$name] = [
                                'label'   => $meta['string']    ?? $name,
                                'choices' => $meta['selection'] ?? null,
                            ];
                        }
                    }
                    $deep['aw_vehicule.vehicule_selection_fields'] = $vehSelections;

                    // Distribution of populated values for the most
                    // promising classifier candidates. read_group gives us
                    // value -> count for free.
                    $candidates = ['goods_group', 'manufacturer', 'model_code',
                                   'manufacturer_code', 'cylinders', 'position',
                                   'state', 'pdf_img'];
                    $vehDistributions = [];
                    foreach ($candidates as $f) {
                        if (!isset($allVehFields[$f])) continue;
                        try {
                            $rows = $client->callModel('aw_vehicule.vehicule', 'read_group', [
                                'domain'  => [],
                                'fields'  => [],
                                'groupby' => [$f],
                                'lazy'    => false,
                                'limit'   => 25,
                            ]);
                            $vehDistributions[$f] = array_map(fn ($r) => [
                                'value' => $r[$f] ?? null,
                                'count' => $r['__count'] ?? null,
                            ], $rows);
                        } catch (\Throwable $e) {
                            $vehDistributions[$f] = ['error' => $e->getMessage()];
                        }
                    }
                    $deep['aw_vehicule.vehicule_value_distributions'] = $vehDistributions;
                } catch (\Throwable $e) {
                    $deep['aw_vehicule.vehicule_error'] = $e->getMessage();
                }

                // Cross-model field name search.
                try {
                    $fieldHits = $client->searchRead('ir.model.fields',
                        [
                            '|','|','|','|','|','|','|','|','|','|','|','|','|','|',
                            ['name',              'ilike', 'goods_group'],
                            ['name',              'ilike', 'classif'],
                            ['name',              'ilike', 'category'],
                            ['name',              'ilike', 'gabarit'],
                            ['name',              'ilike', 'vehicle_type'],
                            ['name',              'ilike', 'tonnage'],
                            ['field_description', 'ilike', 'voiture'],
                            ['field_description', 'ilike', 'camion'],
                            ['field_description', 'ilike', 'bulldozer'],
                            ['field_description', 'ilike', 'engin'],
                            ['field_description', 'ilike', 'gabarit'],
                            ['field_description', 'ilike', 'classif'],
                            ['field_description', 'ilike', 'marchandise'],
                            ['field_description', 'ilike', 'tonnage'],
                            ['field_description', 'ilike', 'véhicule'],
                        ],
                        ['model', 'name', 'field_description', 'ttype', 'help', 'relation'],
                        ['limit' => 200, 'order' => 'model, name']
                    );
                    // Drop hits that come from std Odoo / sale / stock /
                    // mail / utm / etc. — keep only AW + custom + fields
                    // on models we already touched.
                    $fieldHits = array_values(array_filter($fieldHits, function ($h) {
                        $m = (string) ($h['model'] ?? '');
                        if ($m === '') return false;
                        if (str_starts_with($m, 'mail.')) return false;
                        if (str_starts_with($m, 'ir.')) return false;
                        if (str_starts_with($m, 'res.users')) return false;
                        if (str_starts_with($m, 'utm.')) return false;
                        if (str_starts_with($m, 'web_')) return false;
                        return true;
                    }));
                    $deep['classification_field_hits'] = $fieldHits;
                } catch (\Throwable $e) {
                    $deep['classification_field_hits_error'] = $e->getMessage();
                }

                // aw_cargo_invoice.product full dump (~31 rows; small).
                try {
                    $deep['aw_cargo_invoice.product_full'] = $client->searchRead(
                        'aw_cargo_invoice.product',
                        [],
                        ['size', 'journey_type', 'direction', 'product_id'],
                        ['limit' => 100, 'order' => 'id']
                    );
                } catch (\Throwable $e) {
                    $deep['aw_cargo_invoice.product_full_error'] = $e->getMessage();
                }

                // Full inventory of aw_* models with row counts.
                try {
                    $awModels = $client->searchRead('ir.model',
                        [
                            ['model', 'ilike', 'aw_'],
                            ['transient', '=', false],
                        ],
                        ['model', 'name'],
                        ['limit' => 200, 'order' => 'model']
                    );
                    foreach ($awModels as &$m) {
                        try {
                            $m['row_count'] = $client->searchCount($m['model'], []);
                        } catch (\Throwable $e) {
                            $m['row_count_error'] = $e->getMessage();
                        }
                    }
                    unset($m);
                    $deep['aw_models_inventory'] = $awModels;
                } catch (\Throwable $e) {
                    $deep['aw_models_inventory_error'] = $e->getMessage();
                }

                $report['deep_vehicle_probe'] = $deep;
            } catch (\Throwable $e) {
                $report['error'] = $e->getMessage();
            } finally {
                if ($previous) {
                    $ctx->set($previous);
                } else {
                    $ctx->clear();
                }
            }

            return response()->json(
                $report, 200, [],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        });

        // Platform-wide AI provider configuration (super-admin only).
        Route::get('/settings/ai',  [AdminAiSettingsController::class, 'show']);
        Route::post('/settings/ai', [AdminAiSettingsController::class, 'save']);
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
                Route::get('/operations',        [OperationsController::class, 'index'])->middleware('module:operations');
                Route::get('/billing',           [BillingController::class, 'index'])->middleware('module:billing');
                Route::get('/procurement',       [ProcurementController::class, 'index'])->middleware('module:procurement');

                // ── Financial Reporting service ───────────────────────────────
                Route::get('/general-ledger-quality', [GLQualityController::class, 'index'])->middleware('module:gl_quality');
                // Avoid a dotted extension in the URL — PHP's built-in dev server
                // treats anything with a "." as a static file and serves a 404
                // before Laravel's router sees the request.
                Route::get('/general-ledger-quality/description-report',
                    [GLQualityController::class, 'descriptionReport'])->middleware('module:gl_quality');
                Route::get('/general-ledger-quality/manual-entry-report',
                    [GLQualityController::class, 'manualEntryReport'])->middleware('module:gl_quality');
                Route::get('/general-ledger-quality/timing-report',
                    [GLQualityController::class, 'timingReport'])->middleware('module:gl_quality');

                // ── Tax Compliance service ────────────────────────────────────
                Route::get('/tax-dashboard',          [TaxDashboardController::class, 'index'])->middleware('module:tax_dashboard');
                Route::get ('/income-tax-compliance',                       [IncomeTaxComplianceController::class, 'index'])           ->middleware('module:income_tax_compliance');
                Route::get ('/income-tax-compliance/export',                [IncomeTaxComplianceController::class, 'export'])          ->middleware('module:income_tax_compliance');
                Route::post('/income-tax-compliance/donations/category',    [IncomeTaxComplianceController::class, 'setCategory'])     ->middleware('module:income_tax_compliance');
                Route::post('/income-tax-compliance/donations/upload',      [IncomeTaxComplianceController::class, 'uploadDoc'])       ->middleware('module:income_tax_compliance');
                Route::get ('/income-tax-compliance/donations/export',      [IncomeTaxComplianceController::class, 'exportDonations']) ->middleware('module:income_tax_compliance');
                Route::get('/vat-compliance',         [VATComplianceController::class, 'index'])->middleware('module:vat_compliance');
                Route::get('/vat-invoice-compliance', [VATInvoiceComplianceController::class, 'index'])->middleware('module:vat_invoice_compliance');
                Route::get('/monthly-vat-return',     [MonthlyVatReturnController::class, 'index'])->middleware('module:monthly_vat_return');
                // Backward-compat redirects (old URLs from prior to 2026-04-27 reshuffle)
                Route::get('/tax-calendar', fn () => redirect()->to(tenant_url('/tax-dashboard')));

                Route::get ('/settings',                    [SettingsController::class, 'show']);
                Route::post('/settings',                    [SettingsController::class, 'save']);
                Route::post('/settings/test-odoo',          [SettingsController::class, 'testOdoo']);
            });
        });
    });
