<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\RevenueController;
use App\Http\Controllers\ReceivablesController;
use App\Http\Controllers\ProfitLossController;
use App\Http\Controllers\PayablesController;
use App\Http\Controllers\TreasuryController;
use App\Http\Controllers\BalanceSheetReviewController;
use App\Http\Controllers\BudgetUploadController;
use App\Http\Controllers\CashflowForecastController;
use App\Http\Controllers\InvestmentAnalysisController;
use App\Http\Controllers\MacroEconomicsController;
use App\Http\Controllers\OhadaFinancialStatementsController;
use App\Http\Controllers\PLForecastController;
use App\Http\Controllers\TrialBalanceController;
use App\Http\Controllers\WorkingCapitalController;
use App\Http\Controllers\FixedAssetsController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\OperationsController;
use App\Http\Controllers\ProductRevenueTsdController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\ProcurementController;
use App\Http\Controllers\GLQualityController;
use App\Http\Controllers\CustomerInsightsController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TenantUsersController;
use App\Http\Controllers\Tax\IncomeTaxComplianceController;
use App\Http\Controllers\Tax\MonthlyTaxReturnController;
use App\Http\Controllers\Tax\ReturnReconciliationController;
use App\Http\Controllers\Tax\TaxDashboardController;
use App\Http\Controllers\Tax\VATComplianceController;
use App\Http\Controllers\Tax\VATInvoiceComplianceController;
use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\BusinessInsightsController as AdminBusinessInsightsController;
use App\Http\Controllers\Admin\UsersController as AdminUsersController;
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

/* ── Public Demo page ──────────────────────────────────────────────────
   The marketing demo lives on the hosted site as a proper page, not as
   a section embedded in the login or a standalone HTML file. Public —
   no auth, no tenant scope. Linked from the sign-in page's top nav and
   footer ("Demo"). All figures are fictional and illustrative; the
   same six-capability set we surface from the marketing CTAs.
   ────────────────────────────────────────────────────────────────────── */
Route::view('/demo', 'demo')->name('demo');

/* ── Super Admin backend ─────────────────────────────────────────────── */
Route::prefix('admin')->group(function () {
    Route::get('/login',  [AdminAuthController::class, 'showLogin']);
    Route::post('/login', [AdminAuthController::class, 'login']);
    Route::get('/logout', [AdminAuthController::class, 'logout']);

    Route::middleware('admin.auth')->group(function () {
        Route::get('/', [AdminDashboardController::class, 'index']);

        // Methodology — internal reference page documenting per-module
        // data sources, formulas and design rationale. Used to live at
        // the bottom of each client-facing module page; moved here so
        // it isn't exposed to clients.
        // Cross-tenant user management (super admin only — already
        // gated by admin.auth above).
        Route::get   ('/users',                [AdminUsersController::class, 'index']);
        Route::get   ('/users/create',         [AdminUsersController::class, 'create']);
        Route::post  ('/users',                [AdminUsersController::class, 'store']);
        Route::get   ('/users/{id}/edit',      [AdminUsersController::class, 'edit'])->whereNumber('id');
        Route::post  ('/users/{id}',           [AdminUsersController::class, 'update'])->whereNumber('id');
        Route::post  ('/users/{id}/delete',    [AdminUsersController::class, 'destroy'])->whereNumber('id');

        // Daily Claude-generated business insights (super-admin only —
        // already gated by admin.auth above).
        Route::get ('/business-insights',                  [AdminBusinessInsightsController::class, 'index']);
        Route::post('/business-insights/run-all',          [AdminBusinessInsightsController::class, 'runAll']);
        Route::get ('/business-insights/{tenantId}',       [AdminBusinessInsightsController::class, 'show'])->whereNumber('tenantId');
        Route::post('/business-insights/{tenantId}/refresh',[AdminBusinessInsightsController::class, 'refresh'])->whereNumber('tenantId');

        Route::get('/methodology', fn () => view('admin.methodology', [
            'active' => 'methodology',
        ]));

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

                /* ── Manual EDI probe (v4) ─────────────────────────────────
                 * Vehicle scan counts on /operations are still 0 because the
                 * `scanned` flag on aw_vehicule.vehicule is never set on
                 * this customer's data. The webmaster says the scan info
                 * lives under the "Manual EDI" menu. This section dumps:
                 *
                 *   1. Manual EDI menu tree + resolved models
                 *   2. Full field schema for aw_edi.* / aw.edi.* models
                 *   3. Sample records (with text bodies truncated)
                 *   4. Datetime-field census on aw_vehicule.vehicule —
                 *      for each datetime field, what % of the live
                 *      population has a non-null value (so we can spot
                 *      the "real" scan timestamp if there is one)
                 *   5. ir.model.fields search for anything linking the EDI
                 *      models back to aw_vehicule.vehicule
                 * ─────────────────────────────────────────────────────── */
                $edi = [];

                // 1. Manual EDI menu walk
                try {
                    $ediRoots = $client->searchRead('ir.ui.menu',
                        [
                            '|','|',
                            ['name', 'ilike', 'Manual EDI'],
                            ['name', 'ilike', 'EDI Manuel'],
                            ['name', 'ilike', 'Manual'],
                        ],
                        ['id', 'name', 'parent_id', 'sequence', 'action'],
                        ['limit' => 30, 'order' => 'sequence']
                    );
                    foreach ($ediRoots as &$root) {
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
                    $edi['manual_edi_menu_candidates'] = $ediRoots;

                    // Resolve the menu actions to their res_model
                    $ediActionIds = [];
                    $walk2 = function (array $items) use (&$walk2, &$ediActionIds) {
                        foreach ($items as $it) {
                            if (!empty($it['action']) && is_string($it['action']) && str_starts_with($it['action'], 'ir.actions.act_window,')) {
                                $ediActionIds[] = (int) substr($it['action'], strlen('ir.actions.act_window,'));
                            }
                            if (!empty($it['children'])) $walk2($it['children']);
                        }
                    };
                    $walk2($ediRoots);
                    $ediActionIds = array_values(array_unique(array_filter($ediActionIds)));
                    if ($ediActionIds) {
                        $actions = $client->searchRead('ir.actions.act_window',
                            [['id', 'in', $ediActionIds]],
                            ['id', 'name', 'res_model'],
                            ['limit' => 200]
                        );
                        $edi['manual_edi_resolved_models'] = collect($actions)
                            ->mapWithKeys(fn ($a) => [(string) ($a['res_model'] ?? '?') => (string) ($a['name'] ?? '?')])
                            ->all();
                    }
                } catch (\Throwable $e) {
                    $edi['menu_walk_error'] = $e->getMessage();
                }

                // 2. Full field schema + samples for the candidate EDI models
                $ediModels = [
                    'aw_edi.message',
                    'aw_edi.message.history',
                    'aw_edi.message.type',
                    'aw.edi.history',
                    'aw.edi.processed.file',
                ];
                foreach ($ediModels as $model) {
                    $bucket = [];
                    try {
                        $bucket['count'] = $client->searchCount($model, []);
                    } catch (\Throwable $e) {
                        $bucket['count_error'] = $e->getMessage();
                    }
                    try {
                        $bucket['fields'] = $client->callModel($model, 'fields_get', [
                            'attributes' => ['type', 'string', 'help', 'relation', 'required'],
                        ]);
                    } catch (\Throwable $e) {
                        $bucket['fields_error'] = $e->getMessage();
                    }
                    try {
                        // Avoid pulling huge binary/text bodies — pick a
                        // small set of "metadata-like" field names if
                        // available, else fall back to default columns.
                        $candidateCols = ['id', 'name', 'create_date', 'write_date', 'state', 'message_type', 'partner_id', 'cargo_id', 'vehicule_id', 'vehicule_ids', 'reference', 'filename', 'direction', 'received_at', 'processed_at', 'count'];
                        $available = array_keys($bucket['fields'] ?? []);
                        $cols = array_values(array_intersect($candidateCols, $available));
                        if (empty($cols)) $cols = ['id'];
                        $rows = $client->searchRead($model, [], $cols, ['limit' => 8, 'order' => 'id desc']);
                        $bucket['samples'] = $rows;
                    } catch (\Throwable $e) {
                        $bucket['samples_error'] = $e->getMessage();
                    }
                    $edi[$model] = $bucket;
                }

                // 3. ir.model.fields hunt — find anything that LINKS an EDI
                //    model TO aw_vehicule.vehicule, OR sits ON
                //    aw_vehicule.vehicule and references an EDI table.
                try {
                    $linkHits = $client->searchRead('ir.model.fields',
                        [
                            '|','|','|','|','|',
                            ['model', 'ilike', 'aw_edi'],
                            ['relation', '=', 'aw_vehicule.vehicule'],
                            ['model', '=', 'aw_vehicule.vehicule'],
                            ['name', 'ilike', 'edi_'],
                            ['name', 'ilike', 'scan_'],
                            ['field_description', 'ilike', 'scan'],
                        ],
                        ['model', 'name', 'field_description', 'ttype', 'relation', 'help'],
                        ['limit' => 300, 'order' => 'model, name']
                    );
                    // Trim noise: keep only AW + relevant standard models.
                    $linkHits = array_values(array_filter($linkHits, function ($h) {
                        $m = (string) ($h['model'] ?? '');
                        if ($m === '') return false;
                        if (str_starts_with($m, 'mail.'))  return false;
                        if (str_starts_with($m, 'ir.'))    return false;
                        if (str_starts_with($m, 'res.'))   return false;
                        if (str_starts_with($m, 'utm.'))   return false;
                        if (str_starts_with($m, 'web_'))   return false;
                        if (str_starts_with($m, 'mrp.'))   return false;
                        if (str_starts_with($m, 'bus.'))   return false;
                        return true;
                    }));
                    $edi['edi_vehicle_link_field_hits'] = $linkHits;
                } catch (\Throwable $e) {
                    $edi['edi_vehicle_link_field_hits_error'] = $e->getMessage();
                }

                // 4. Datetime-field census on aw_vehicule.vehicule.
                //    For a sample of 1000 most-recent vehicles, count which
                //    datetime fields are populated. This shows whether
                //    there's a "real" scan timestamp we missed
                //    (e.g. gate_check_date, inspection_date, qr_at, …).
                try {
                    $vehFields = $deep['aw_vehicule.vehicule_full_fields'] ?? null;
                    if (!is_array($vehFields)) {
                        $vehFields = $client->callModel('aw_vehicule.vehicule', 'fields_get', [
                            'attributes' => ['type'],
                        ]);
                    }
                    $datetimeFields = [];
                    foreach ($vehFields as $fname => $fmeta) {
                        if (($fmeta['type'] ?? null) === 'datetime') $datetimeFields[] = $fname;
                    }
                    $boolFields = [];
                    foreach ($vehFields as $fname => $fmeta) {
                        if (($fmeta['type'] ?? null) === 'boolean') $boolFields[] = $fname;
                    }

                    $sampleSize = 1000;
                    $sample = $client->searchRead('aw_vehicule.vehicule',
                        [],
                        array_values(array_unique(array_merge($datetimeFields, $boolFields, ['id', 'state']))),
                        ['limit' => $sampleSize, 'order' => 'id desc']
                    );
                    $populated = [];
                    foreach ($datetimeFields as $f) {
                        $cnt = 0;
                        foreach ($sample as $r) {
                            $v = $r[$f] ?? null;
                            if ($v !== null && $v !== false && $v !== '') $cnt++;
                        }
                        $populated[$f] = ['type' => 'datetime', 'non_null' => $cnt, 'sample_size' => count($sample)];
                    }
                    foreach ($boolFields as $f) {
                        $cnt = 0;
                        foreach ($sample as $r) {
                            if (!empty($r[$f])) $cnt++;
                        }
                        $populated[$f] = ['type' => 'boolean', 'non_null' => $cnt, 'sample_size' => count($sample)];
                    }
                    $edi['aw_vehicule_datetime_and_bool_census'] = [
                        'sample_size' => count($sample),
                        'fields'      => $populated,
                        'note'        => 'Within the latest '.count($sample).' vehicles, this is how many records have a populated value per field. The "scan" column the customer sees in the UI is whichever bool/datetime is non-zero on most rows. Use this to spot it.',
                    ];

                    // Bonus: state distribution on the SAME sample, so we
                    // can correlate "vehicles with state=paid/invoiced"
                    // with "vehicles with this datetime non-null".
                    $byState = [];
                    foreach ($sample as $r) {
                        $s = (string) ($r['state'] ?? '?');
                        $byState[$s] = ($byState[$s] ?? 0) + 1;
                    }
                    $edi['aw_vehicule_sample_state_distribution'] = $byState;
                } catch (\Throwable $e) {
                    $edi['aw_vehicule_datetime_census_error'] = $e->getMessage();
                }

                // 5. Whatever the customer DOES see as "scanned" in the
                //    Vehicles list view — let's grab the act_window's
                //    domain filter and the tree-view definition. That'll
                //    tell us which fields drive the visible columns.
                try {
                    $vehViews = $client->searchRead('ir.actions.act_window',
                        [['res_model', '=', 'aw_vehicule.vehicule']],
                        ['id', 'name', 'domain', 'context', 'view_mode'],
                        ['limit' => 20]
                    );
                    $edi['aw_vehicule_act_windows'] = $vehViews;

                    $treeViews = $client->searchRead('ir.ui.view',
                        [['model', '=', 'aw_vehicule.vehicule'], ['type', '=', 'tree']],
                        ['id', 'name', 'arch_db'],
                        ['limit' => 5]
                    );
                    // Trim arch_db to first 4000 chars (it's XML)
                    foreach ($treeViews as &$v) {
                        if (isset($v['arch_db']) && is_string($v['arch_db']) && strlen($v['arch_db']) > 4000) {
                            $v['arch_db'] = substr($v['arch_db'], 0, 4000) . "\n... (truncated)";
                        }
                    }
                    unset($v);
                    $edi['aw_vehicule_tree_views'] = $treeViews;
                } catch (\Throwable $e) {
                    $edi['aw_vehicule_views_error'] = $e->getMessage();
                }

                $report['manual_edi_probe'] = $edi;
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

        /*
         * Vehicle Scan Analyzer
         * ─────────────────────
         * Lightweight diagnostic that runs FOUR candidate filter strategies
         * against `aw_vehicule.vehicule` for a chosen period and returns
         * per-category counts + sample rows side-by-side. Lets the operator
         * (and the BI team) see at a glance which definition of "scanned
         * vehicle" matches the number they expect from the Odoo UI.
         *
         * Query params:
         *   period = current_month (default) | last_month | current_week |
         *            last_week | ytd | custom
         *   from   = YYYY-MM-DD  (used with period=custom)
         *   to     = YYYY-MM-DD  (used with period=custom)
         *
         * Categories follow the billing legend:
         *   cylinders ∈ {4, 6}  → Voiture (50,000 XAF HT)
         *   cylinders == 8       → Camion (95,000 XAF HT)
         *   cylinders == 12      → Bulldoze (105,000 XAF HT)
         *   anything else        → Other / unclassified (not billed)
         */
        Route::get('/tenants/{tenant}/vehicle-scan-analyzer', function (\App\Models\Tenant $tenant, \Illuminate\Http\Request $request) {
            @set_time_limit(60);

            $ctx = app(\App\Tenancy\TenantContext::class);
            $previous = $ctx->has() ? $ctx->tenant() : null;
            $ctx->set($tenant);

            // ── Resolve the period ─────────────────────────────────────
            $period = (string) $request->query('period', 'current_month');
            $today  = new \DateTimeImmutable('today');
            switch ($period) {
                case 'current_week':
                    // ISO week — Monday to Sunday containing today
                    $monday = $today->modify('monday this week');
                    $from = $monday;
                    $to   = $monday->modify('+6 days');
                    $label = 'Week of ' . $monday->format('M j, Y');
                    break;
                case 'last_week':
                    $monday = $today->modify('monday last week');
                    $from = $monday;
                    $to   = $monday->modify('+6 days');
                    $label = 'Week of ' . $monday->format('M j, Y');
                    break;
                case 'last_month':
                    $first = $today->modify('first day of last month');
                    $from = $first;
                    $to   = $first->modify('last day of this month');
                    $label = $first->format('F Y');
                    break;
                case 'ytd':
                    $from  = new \DateTimeImmutable($today->format('Y-01-01'));
                    $to    = $today;
                    $label = 'YTD ' . $today->format('Y');
                    break;
                case 'custom':
                    $from = new \DateTimeImmutable((string) $request->query('from', $today->format('Y-m-01')));
                    $to   = new \DateTimeImmutable((string) $request->query('to',   $today->format('Y-m-d')));
                    $label = $from->format('Y-m-d') . ' to ' . $to->format('Y-m-d');
                    break;
                case 'current_month':
                default:
                    $first = $today->modify('first day of this month');
                    $from = $first;
                    $to   = $first->modify('last day of this month');
                    $label = $first->format('F Y');
                    break;
            }

            $fromS = $from->format('Y-m-d 00:00:00');
            $toS   = $to->format('Y-m-d 23:59:59');

            // ── Categorisation helper ──────────────────────────────────
            $categorise = function ($cyl): string {
                if ($cyl === 4 || $cyl === 6 || $cyl === '4' || $cyl === '6') return 'voiture';
                if ($cyl === 8 || $cyl === '8') return 'camion';
                if ($cyl === 12 || $cyl === '12') return 'bulldoze';
                return 'other';
            };

            $report = [
                'tenant'    => $tenant->slug,
                'probed_at' => now()->toIso8601String(),
                'period'    => [
                    'mode'  => $period,
                    'from'  => $from->format('Y-m-d'),
                    'to'    => $to->format('Y-m-d'),
                    'label' => $label,
                ],
                'billing_legend' => [
                    'voiture'  => ['cylinders' => [4, 6], 'price_xaf_ht' => 50_000,  'label' => 'Voiture (4 / 6 cyl)'],
                    'camion'   => ['cylinders' => [8],    'price_xaf_ht' => 95_000,  'label' => 'Camion / Truck (8 cyl)'],
                    'bulldoze' => ['cylinders' => [12],   'price_xaf_ht' => 105_000, 'label' => 'Bulldoze / heavy (12 cyl)'],
                    'other'    => ['cylinders' => 'anything else', 'price_xaf_ht' => null, 'label' => 'Non-vehicle / unclassified'],
                ],
            ];

            try {
                $client = \App\Odoo\OdooFactory::fromConfig();
                $client->authenticate();

                /*
                 * Strategies to compare. Each defines:
                 *   - domain: the Odoo search domain (period filter applied)
                 *   - description: plain-English meaning
                 */
                // NOTE — `aw_vehicule.vehicule` does NOT have an `active`
                // field. Including ['active','=',true] in the domain
                // causes Odoo to throw "Invalid field" and the strategy
                // returns 0 with an error blob. (Confirmed via PR #37
                // first run: B/C/D all errored out for this exact reason.)
                // None of the domains below filter on `active`.
                $strategies = [
                    'A_literal_scanned' => [
                        'description' => 'Literal scan: scanned=true AND scanned_date in period. Matches the customer\'s "scanned" checkbox column directly. CONFIRMED to be the correct definition — April 2026 returned 3,167 records with proper cylinders breakdown.',
                        'domain'      => [
                            ['scanned', '=', true],
                            ['scanned_date', '>=', $fromS],
                            ['scanned_date', '<=', $toS],
                        ],
                        'date_field'  => 'scanned_date',
                    ],
                    'B_state_writedate' => [
                        'description' => 'Billing-state proxy anchored on write_date: state in [invoiced, paid, notify, close] AND write_date in period. Excludes scanned-but-still-draft records (the majority on this workflow), so will under-count vs strategy A.',
                        'domain'      => [
                            ['state', 'in', ['invoiced', 'paid', 'notify', 'close']],
                            ['write_date', '>=', $fromS],
                            ['write_date', '<=', $toS],
                        ],
                        'date_field'  => 'write_date',
                    ],
                    'C_state_createdate' => [
                        'description' => 'Billing-state proxy anchored on create_date (the PR #33 production logic that broke /operations vehicle rows): state in [invoiced, paid, notify, close] AND create_date in period. Most restrictive — vehicle must be both imported AND past draft within the same window.',
                        'domain'      => [
                            ['state', 'in', ['invoiced', 'paid', 'notify', 'close']],
                            ['create_date', '>=', $fromS],
                            ['create_date', '<=', $toS],
                        ],
                        'date_field'  => 'create_date',
                    ],
                    'D_any_writedate' => [
                        'description' => 'Broadest: any vehicle record touched in the period (write_date in range, no state filter). Useful as an upper bound — includes draft-only records that have never been scanned.',
                        'domain'      => [
                            ['write_date', '>=', $fromS],
                            ['write_date', '<=', $toS],
                        ],
                        'date_field'  => 'write_date',
                    ],
                ];

                $results = [];

                foreach ($strategies as $key => $cfg) {
                    $bucket = [
                        'description' => $cfg['description'],
                        'domain'      => $cfg['domain'],
                        'by_category' => [
                            'voiture' => 0, 'camion' => 0, 'bulldoze' => 0, 'other' => 0, 'total' => 0,
                        ],
                        'cylinders_distribution' => [],
                        'samples'      => [],
                        'error'        => null,
                    ];

                    try {
                        // 1) Counts by cylinders via read_group.
                        $rows = $client->callModel('aw_vehicule.vehicule', 'read_group', [
                            'domain'  => $cfg['domain'],
                            'fields'  => [],
                            'groupby' => ['cylinders'],
                            'lazy'    => false,
                            'limit'   => 100,
                        ]);
                        foreach ($rows as $r) {
                            $cyl = $r['cylinders'] ?? null;
                            // Odoo returns false for null int fields; preserve that.
                            $cylDisplay = ($cyl === false || $cyl === null) ? 'null' : (string) $cyl;
                            $count = (int) ($r['__count'] ?? $r['cylinders_count'] ?? 0);
                            $bucket['cylinders_distribution'][$cylDisplay] = $count;
                            $cat = $categorise($cyl);
                            $bucket['by_category'][$cat] += $count;
                            $bucket['by_category']['total'] += $count;
                        }

                        // 2) Sample rows for eyeballing — top 8 by id desc.
                        $bucket['samples'] = $client->searchRead(
                            'aw_vehicule.vehicule',
                            $cfg['domain'],
                            ['id', 'name', 'cylinders', 'state', 'scanned', 'scanned_date',
                             'create_date', 'write_date', 'cargo_id', 'manufacturer', 'model_code'],
                            ['limit' => 8, 'order' => 'id desc']
                        );
                    } catch (\Throwable $e) {
                        $bucket['error'] = $e->getMessage();
                    }

                    // Add billing-revenue projection from counts × price grid.
                    $bucket['projected_revenue_xaf_ht'] = (
                        $bucket['by_category']['voiture']  *  50_000
                      + $bucket['by_category']['camion']   *  95_000
                      + $bucket['by_category']['bulldoze'] * 105_000
                    );

                    $results[$key] = $bucket;
                }

                $report['strategies'] = $results;

                // Headline comparison table — easier to scan than the full strategy blob.
                $report['headline_comparison'] = [
                    'header' => ['strategy', 'voiture', 'camion', 'bulldoze', 'other', 'total', 'rev_xaf_ht'],
                    'rows'   => array_map(
                        fn ($k) => [
                            'strategy'    => $k,
                            'voiture'     => $results[$k]['by_category']['voiture'],
                            'camion'      => $results[$k]['by_category']['camion'],
                            'bulldoze'    => $results[$k]['by_category']['bulldoze'],
                            'other'       => $results[$k]['by_category']['other'],
                            'total'       => $results[$k]['by_category']['total'],
                            'rev_xaf_ht'  => $results[$k]['projected_revenue_xaf_ht'],
                        ],
                        array_keys($results)
                    ),
                ];

                /*
                 * Cylinders / tariff cross-reference.
                 *
                 * The `cylinders` field on aw_vehicule.vehicule holds the
                 * values used to bucket records into Voiture / Camion /
                 * Bulldoze for billing. The tariff structure itself —
                 * which says "this cylinder code maps to this XAF price"
                 * — lives in aw_cargo_invoice.product (the customer's
                 * Grille Tarifaire). This section dumps both so the
                 * mapping is auditable in one place:
                 *
                 *   1. Cylinders distribution across vehicles SCANNED in
                 *      the chosen period.
                 *   2. aw_cargo_invoice.product full schema + every record
                 *      so the customer can confirm exactly which code →
                 *      label → price triplet defines each tariff class.
                 */
                $cyl = [
                    'note' => 'Cylinders → tariff mapping. Section 1 is what we see on scanned vehicles in the period; section 2 is the customer\'s billing legend (Grille Tarifaire) from aw_cargo_invoice.product.',
                ];
                try {
                    $rows = $client->callModel('aw_vehicule.vehicule', 'read_group', [
                        'domain'  => [
                            ['scanned', '=', true],
                            ['scanned_date', '>=', $fromS],
                            ['scanned_date', '<=', $toS],
                        ],
                        'fields'  => [],
                        'groupby' => ['cylinders'],
                        'lazy'    => false,
                        'limit'   => 50,
                    ]);
                    $cyl['scanned_cylinders_distribution_in_period'] = array_map(fn ($r) => [
                        'cylinders' => $r['cylinders'] ?? null,
                        'count'     => (int) ($r['__count'] ?? 0),
                    ], $rows);
                } catch (\Throwable $e) {
                    $cyl['scanned_cylinders_error'] = $e->getMessage();
                }

                try {
                    $tariffFields = $client->callModel('aw_cargo_invoice.product', 'fields_get', [
                        'attributes' => ['string', 'type', 'help', 'selection'],
                    ]);
                    $cyl['tariff_table_field_schema'] = $tariffFields;

                    $cyl['tariff_table_records'] = $client->searchRead(
                        'aw_cargo_invoice.product',
                        [],
                        array_slice(array_keys($tariffFields), 0, 60),
                        ['limit' => 100, 'order' => 'id asc']
                    );
                } catch (\Throwable $e) {
                    $cyl['tariff_table_error'] = $e->getMessage();
                }

                $report['cylinders_tariff'] = $cyl;

                /*
                 * Verified-cylinder samples — one bucket per correctly
                 * captured value (4, 6, 8, 12). For each, pull 5 recent
                 * scanned records in the chosen period and dump every
                 * scalar field on the record (excluding binary blobs and
                 * one-to-many relations to keep the payload reasonable).
                 *
                 * Purpose: let the operator see records exactly as they
                 * appear in Odoo for vehicles where the cylinders field
                 * IS populated correctly. Useful for:
                 *   - Confirming the categorisation is right (cyl=4 →
                 *     Voiture really is a passenger car, etc.)
                 *   - Comparing to the cylinders=0 records to identify
                 *     what input step is missing on the unclassified ones
                 */
                $verified = [
                    'note' => 'Sample scanned records grouped by correctly-captured cylinders value (4, 6, 8, 12). All scalar fields included; binary fields and o2m relations skipped.',
                ];
                try {
                    // Build the field list ONCE — every scalar/relation
                    // type except binary (pdf_img etc.) and one2many.
                    $fieldSchema = $client->callModel('aw_vehicule.vehicule', 'fields_get', [
                        'attributes' => ['type'],
                    ]);
                    $skipTypes = ['binary', 'one2many', 'many2many'];
                    $readableFields = [];
                    foreach ($fieldSchema as $fname => $fmeta) {
                        if (in_array($fmeta['type'] ?? '', $skipTypes, true)) continue;
                        $readableFields[] = $fname;
                    }
                    $verified['readable_field_count'] = count($readableFields);

                    foreach ([4, 6, 8, 12] as $cylValue) {
                        $bucketKey = "cyl_{$cylValue}";
                        try {
                            $samples = $client->searchRead(
                                'aw_vehicule.vehicule',
                                [
                                    ['scanned', '=', true],
                                    ['scanned_date', '>=', $fromS],
                                    ['scanned_date', '<=', $toS],
                                    ['cylinders', '=', $cylValue],
                                ],
                                $readableFields,
                                ['limit' => 5, 'order' => 'scanned_date desc']
                            );
                            // Strip any false / null fields from each
                            // record so the JSON shows only what's
                            // actually populated (cleaner reading).
                            foreach ($samples as &$s) {
                                $s = array_filter(
                                    $s,
                                    fn ($v) => $v !== false && $v !== null && $v !== ''
                                );
                            }
                            unset($s);

                            $category = match ($cylValue) {
                                4, 6 => 'Voiture',
                                8    => 'Camion',
                                12   => 'Bulldoze',
                                default => 'Other',
                            };
                            $tariff = match ($cylValue) {
                                4, 6 => 50_000,
                                8    => 95_000,
                                12   => 105_000,
                                default => 0,
                            };

                            $verified[$bucketKey] = [
                                'category'         => $category,
                                'tariff_xaf_ht'    => $tariff,
                                'sample_count'     => count($samples),
                                'samples'          => $samples,
                            ];
                        } catch (\Throwable $e) {
                            $verified[$bucketKey] = ['error' => $e->getMessage()];
                        }
                    }
                } catch (\Throwable $e) {
                    $verified['error'] = $e->getMessage();
                }

                $report['verified_cylinder_samples'] = $verified;
            } catch (\Throwable $e) {
                $report['error'] = $e->getMessage();
            } finally {
                if ($previous) $ctx->set($previous);
            }

            return response()->json(
                $report, 200, [],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        });

        // Platform-wide AI provider configuration (super-admin only).
        Route::get('/settings/ai',  [AdminAiSettingsController::class, 'show']);
        Route::post('/settings/ai', [AdminAiSettingsController::class, 'save']);

        /*
         * ── DIAGNOSTIC: Treasury disbursements probe ──────────────────────
         *
         * Real-time JSON dump of every step the disbursements engine takes
         * for a given tenant. Built 2026-05-07 to find why the
         * "Next 30-day disbursements" sandbox kept reading XAF 0 across
         * every category despite the tenant having posted cash payments
         * throughout the year. The engine writes diagnostic logs but
         * tailing the prod log file requires SSH; this endpoint exposes
         * the same state as JSON in a single browser hit, no SSH needed.
         *
         * URL:
         *   /admin/tenants/{tenant}/treasury-probe
         *
         * Output (JSON):
         *   {
         *     window: {from, to, months_basis},
         *     journals: { domain_used, count, list:[{id,code,name,type}, ...] },
         *     prefix_accounts: { '16':[...], '42':[...], ... },
         *     read_group_rows: [...],
         *     sums_by_prefix: { '16':X, '42':Y, ... },
         *     ap_due_30d: float,
         *     errors: [...]
         *   }
         *
         * Read-only — performs no mutations. Remove after the
         * disbursements engine is verified correct on this tenant.
         */
        /*
         * ── DIAGNOSTIC: Tail of laravel.log ─────────────────────────────
         *
         * Quick way to read the production log without SSH. Returns
         * the last N lines (default 200) of storage/logs/laravel.log
         * as plain-text. Super-admin only. Used to read the
         * `treasury.index: disbursements payload` log line written by
         * TreasuryController on every render — confirms whether the
         * engine is computing zeros (engine bug) or non-zeros that
         * the view drops (rendering bug).
         *
         * URL:
         *   /admin/log-tail            (last 200 lines)
         *   /admin/log-tail?lines=500  (last 500 lines)
         *   /admin/log-tail?grep=treasury  (only lines containing 'treasury')
         */
        Route::get('/log-tail', function (\Illuminate\Http\Request $request) {
            $path  = storage_path('logs/laravel.log');
            $lines = max(10, min(2000, (int) $request->query('lines', 200)));
            $grep  = (string) $request->query('grep', '');

            if (!file_exists($path)) {
                return response("Log file not found at: {$path}", 404, ['Content-Type' => 'text/plain']);
            }

            // Tail using shell — efficient even on large logs. Falls back
            // to PHP-level read if `tail` is unavailable for some reason.
            $output = '';
            if (function_exists('shell_exec') && shell_exec('which tail')) {
                $cmd = 'tail -n ' . escapeshellarg((string) $lines) . ' ' . escapeshellarg($path);
                if ($grep !== '') {
                    $cmd .= ' | grep -i ' . escapeshellarg($grep);
                }
                $output = (string) shell_exec($cmd . ' 2>&1');
            } else {
                $allLines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                $tail = array_slice($allLines, -$lines);
                if ($grep !== '') {
                    $tail = array_filter($tail, fn ($l) => stripos($l, $grep) !== false);
                }
                $output = implode("\n", $tail);
            }

            return response($output ?: '(no matching lines)', 200, ['Content-Type' => 'text/plain; charset=utf-8']);
        });

        Route::get('/tenants/{tenant:slug}/treasury-probe', function (\App\Models\Tenant $tenant) {
            @set_time_limit(60);

            $ctx = app(\App\Tenancy\TenantContext::class);
            $previous = $ctx->has() ? $ctx->tenant() : null;
            $ctx->set($tenant);

            $report = [
                'tenant'    => $tenant->slug,
                'probed_at' => now()->toIso8601String(),
                'errors'    => [],
            ];

            try {
                $client = \App\Odoo\OdooFactory::fromConfig();
                $client->authenticate();

                // Replicate the controller's window logic
                $today    = new \DateTimeImmutable('today');
                $year     = (int) $today->format('Y');
                $monthNow = (int) $today->format('n');
                $jan1     = new \DateTimeImmutable("{$year}-01-01");
                if ($monthNow >= 2) {
                    $ytdEnd      = (new \DateTimeImmutable("{$year}-{$monthNow}-01"))->modify('-1 day');
                    $monthsBasis = $monthNow - 1;
                } else {
                    $ytdEnd      = $today;
                    $monthsBasis = 1;
                }
                $report['window'] = [
                    'today'        => $today->format('Y-m-d'),
                    'from'         => $jan1->format('Y-m-d'),
                    'to'           => $ytdEnd->format('Y-m-d'),
                    'months_basis' => $monthsBasis,
                ];

                // ── 1. Identify cash journals (same domain as service) ──
                $journalDomain = [
                    '|', '|',
                    ['type', 'in',     ['cash', 'bank']],
                    ['code', '=like',  'CSH%'],
                    ['code', '=like',  'BNK%'],
                ];
                $report['journals']['domain_used'] = 'type IN (cash,bank) OR code LIKE CSH% OR code LIKE BNK%';
                $journals = $client->searchRead('account.journal', $journalDomain,
                    ['id', 'code', 'name', 'type'], []);
                $report['journals']['count'] = count($journals);
                $report['journals']['list']  = $journals;
                $journalIds = array_map(fn ($j) => (int) $j['id'], $journals);

                // ── 1b. Compare with the broader pull (no filter) so we
                // can see every journal Odoo has, useful if the type+code
                // filter returns zero. ────────────────────────────────────
                $allJournals = $client->searchRead('account.journal', [],
                    ['id', 'code', 'name', 'type'], ['order' => 'code asc']);
                $report['journals']['_all_journals_for_reference'] = $allJournals;

                // ── 2. Resolve the disbursement-prefix accounts ──────────
                $prefixes = ['16', '42', '43', '44', '401'];
                $domain   = [];
                for ($i = 0; $i < count($prefixes) - 1; $i++) $domain[] = '|';
                foreach ($prefixes as $p) $domain[] = ['code', '=like', $p . '%'];
                $accounts = $client->searchRead('account.account', $domain,
                    ['id', 'code', 'name'], ['order' => 'code asc']);
                $report['prefix_accounts']['count'] = count($accounts);
                $report['prefix_accounts']['list']  = $accounts;

                // Reverse map: account_id → which prefix matches (longest wins)
                $sortedPrefixes = $prefixes;
                usort($sortedPrefixes, fn ($a, $b) => strlen($b) - strlen($a));
                $prefixById = [];
                foreach ($accounts as $a) {
                    $code = (string) ($a['code'] ?? '');
                    foreach ($sortedPrefixes as $p) {
                        if (str_starts_with($code, $p)) {
                            $prefixById[(int) $a['id']] = $p;
                            break;
                        }
                    }
                }
                $report['prefix_accounts']['mapping_count'] = count($prefixById);

                // ── 3. read_group debits per account ─────────────────────
                if (count($journalIds) === 0) {
                    $report['errors'][] = 'No journals matched the cash-journal filter — sums will be zero.';
                    $report['sums_by_prefix'] = array_fill_keys($prefixes, 0.0);
                } elseif (count($prefixById) === 0) {
                    $report['errors'][] = 'No accounts matched the disbursement prefixes — sums will be zero.';
                    $report['sums_by_prefix'] = array_fill_keys($prefixes, 0.0);
                } else {
                    $mlDomain = [
                        ['journal_id',   'in', $journalIds],
                        ['account_id',   'in', array_keys($prefixById)],
                        ['parent_state', '=',  'posted'],
                        ['debit',        '>',  0],
                        ['date',         '>=', $jan1->format('Y-m-d')],
                        ['date',         '<=', $ytdEnd->format('Y-m-d')],
                    ];
                    $report['read_group']['domain'] = $mlDomain;

                    $rows = $client->callModel('account.move.line', 'read_group', [
                        'domain'  => $mlDomain,
                        'fields'  => ['debit:sum'],
                        'groupby' => ['account_id'],
                        'lazy'    => false,
                    ]);
                    $report['read_group']['row_count'] = count((array) $rows);
                    $report['read_group']['rows']      = $rows;

                    $sums = array_fill_keys($prefixes, 0.0);
                    foreach ((array) $rows as $r) {
                        $aid = is_array($r['account_id'] ?? null) ? (int) $r['account_id'][0] : null;
                        if ($aid === null || !isset($prefixById[$aid])) continue;
                        $sums[$prefixById[$aid]] += (float) ($r['debit'] ?? 0);
                    }
                    $report['sums_by_prefix'] = $sums;
                    $report['monthly_run_rate'] = array_map(
                        fn ($v) => $monthsBasis > 0 ? round($v / $monthsBasis, 2) : 0.0,
                        $sums
                    );
                }

                // ── 4. Sample raw move lines on 401, no filter besides
                // the date window — confirms there ARE 401 debits in scope
                // even if the journal filter mismatched. ──────────────────
                $sampleAcc = collect($accounts)->first(fn ($a) => str_starts_with((string)($a['code'] ?? ''), '401'));
                if ($sampleAcc) {
                    $sample = $client->searchRead('account.move.line', [
                        ['account_id',   '=',  (int) $sampleAcc['id']],
                        ['debit',        '>',  0],
                        ['parent_state', '=',  'posted'],
                        ['date',         '>=', $jan1->format('Y-m-d')],
                        ['date',         '<=', $ytdEnd->format('Y-m-d')],
                    ], ['id', 'date', 'debit', 'name', 'journal_id', 'move_id'],
                       ['limit' => 10, 'order' => 'date desc']);
                    $report['sample_401_debits_any_journal'] = [
                        'account' => $sampleAcc,
                        'count'   => count($sample),
                        'sample'  => $sample,
                    ];
                }

                // ── 5. AP invoices due in next 30d ────────────────────────
                $apDueWindow = $today->modify('+30 days');
                try {
                    $apInvoices = $client->searchRead('account.move', [
                        ['move_type', '=', 'in_invoice'],
                        ['state', '=', 'posted'],
                        ['payment_state', 'in', ['not_paid', 'partial', 'in_payment']],
                        ['invoice_date_due', '>=', $today->format('Y-m-d')],
                        ['invoice_date_due', '<=', $apDueWindow->format('Y-m-d')],
                    ], ['id', 'name', 'invoice_date_due', 'amount_residual'], ['limit' => 50]);
                    $apDue = 0.0;
                    foreach ($apInvoices as $inv) $apDue += (float) ($inv['amount_residual'] ?? 0);
                    $report['ap_due_30d'] = [
                        'window'  => ['from' => $today->format('Y-m-d'), 'to' => $apDueWindow->format('Y-m-d')],
                        'count'   => count($apInvoices),
                        'total'   => $apDue,
                        'sample'  => array_slice($apInvoices, 0, 5),
                    ];
                } catch (\Throwable $e) {
                    $report['errors'][] = 'AP-due query failed: ' . $e->getMessage();
                }

            } catch (\Throwable $e) {
                $report['errors'][] = 'Top-level exception: ' . $e->getMessage();
                $report['_exception_class'] = get_class($e);
                $report['_exception_trace'] = explode("\n", $e->getTraceAsString());
            } finally {
                $previous ? $ctx->set($previous) : $ctx->clear();
            }

            return response()->json($report, 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        });
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

            // Apply onboarding redirect middleware to all other routes.
            // 'remember.period' rides along here so the master sidebar
            // filter persists across every tenant page in the group.
            Route::middleware(['redirect.if.not.onboarded', 'remember.period'])->group(function () {
                Route::get('/',                  [DashboardController::class, 'index'])->middleware('module:dashboard');
                Route::post('/sync',             [DashboardController::class, 'sync']);

                Route::get('/revenue',           [RevenueController::class, 'index'])->middleware('module:revenue');
                // Product Revenue TSD — per-product trajectory + budget editor.
                // Page is read-only; the POST endpoint accepts the inline
                // budget editor submit (one form for all 12 products).
                Route::get ('/product-revenue-tsd',         [ProductRevenueTsdController::class, 'index'])     ->middleware('module:product_revenue_tsd');
                Route::post('/product-revenue-tsd/budget',  [ProductRevenueTsdController::class, 'saveBudget'])->middleware('module:product_revenue_tsd');
                Route::get('/customer-insights', [CustomerInsightsController::class, 'index'])->middleware('module:customer_insights');
                Route::get('/receivables',       [ReceivablesController::class, 'index'])->middleware('module:receivables');
                Route::get('/treasury',          [TreasuryController::class, 'index'])->middleware('module:treasury');
                Route::get('/profit-loss',       [ProfitLossController::class, 'index'])->middleware('module:profit_loss');
                Route::get('/payables',          [PayablesController::class, 'index'])->middleware('module:payables');
                Route::get('/fixed-assets',      [FixedAssetsController::class, 'index'])->middleware('module:fixed_assets');
                Route::get('/inventory',         [InventoryController::class, 'index'])->middleware('module:inventory');
                Route::get('/operations',        [OperationsController::class, 'index'])->middleware('module:operations');
                Route::get('/billing',           [BillingController::class, 'index'])->middleware('module:billing');
                Route::get('/billing/reconciliation-export',
                    [BillingController::class, 'reconciliationExcel'])->middleware('module:revenue_reconciliation');
                // Top-30 slowest billings export — gated by the same module
                // flag as the billing page itself (not by revenue_reconciliation,
                // since the slowest-billings table is independent of the
                // reconciliation panel).
                Route::get('/billing/slowest-billings-export',
                    [BillingController::class, 'slowestBillingsExport'])->middleware('module:billing');
                Route::get('/procurement',       [ProcurementController::class, 'index'])->middleware('module:procurement');

                // ── Financial Planning service ────────────────────────────────
                Route::get('/macro-economics',   [MacroEconomicsController::class, 'index'])->middleware('module:macro_economics');
                Route::get('/working-capital',   [WorkingCapitalController::class, 'index'])->middleware('module:working_capital');
                Route::get('/pl-forecast',             [PLForecastController::class, 'index'])->middleware('module:pl_forecast');
                Route::post('/pl-forecast/commentary', [PLForecastController::class, 'saveCommentary'])->middleware('module:pl_forecast');
                Route::get('/cashflow-forecast',   [CashflowForecastController::class,   'index'])->middleware('module:cashflow_forecast');
                Route::get('/investment-analysis', [InvestmentAnalysisController::class, 'index'])->middleware('module:investment_analysis');
                // Budget Upload — gated by its own module key now that the
                // sidebar has a dedicated nav entry. Default=true in the
                // registry so existing tenants pick it up without a DB row.
                Route::get ('/budget-upload',           [BudgetUploadController::class, 'index'])->middleware('module:budget_upload');
                Route::post('/budget-upload',           [BudgetUploadController::class, 'upload'])->middleware('module:budget_upload');
                Route::post('/budget-upload/delete',    [BudgetUploadController::class, 'delete'])->middleware('module:budget_upload');
                Route::get ('/budget-upload/template',  [BudgetUploadController::class, 'template'])->middleware('module:budget_upload');

                // ── Financial Reporting service ───────────────────────────────
                Route::get('/trial-balance',                [TrialBalanceController::class,                'index'])->middleware('module:trial_balance');
                Route::get('/balance-sheet-review',         [BalanceSheetReviewController::class,          'index'])->middleware('module:balance_sheet');
                Route::get('/ohada-financial-statements',   [OhadaFinancialStatementsController::class,    'index'])->middleware('module:income_statement');
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
                Route::post('/income-tax-compliance/donations/lines/{moveLineId}/category', [IncomeTaxComplianceController::class, 'setLineCategory'])     ->whereNumber('moveLineId')->middleware('module:income_tax_compliance');
                Route::post('/income-tax-compliance/donations/lines/bulk-category',         [IncomeTaxComplianceController::class, 'setLineCategoriesBulk'])->middleware('module:income_tax_compliance');
                Route::get ('/income-tax-compliance/donations/export',      [IncomeTaxComplianceController::class, 'exportDonations']) ->middleware('module:income_tax_compliance');
                Route::get('/vat-compliance',         [VATComplianceController::class, 'index'])->middleware('module:vat_compliance');
                Route::get('/vat-invoice-compliance', [VATInvoiceComplianceController::class, 'index'])->middleware('module:vat_invoice_compliance');
                Route::get('/monthly-tax-return',       [MonthlyTaxReturnController::class, 'index'])    ->middleware('module:monthly_tax_return');
                Route::get('/monthly-tax-return/print', [MonthlyTaxReturnController::class, 'printable'])->middleware('module:monthly_tax_return');
                // Return Reconciliation — standalone reconciliation page
                // for the Monthly Tax Return. Reuses the same analyze()
                // payload (and cache namespace) as /monthly-tax-return.
                Route::get('/return-reconciliation',    [ReturnReconciliationController::class, 'index'])->middleware('module:return_reconciliation');
                // Back-compat: old URL redirects to new one (preserves bookmarks).
                Route::get('/monthly-vat-return', fn () => redirect()->to(tenant_url('/monthly-tax-return')));
                // Backward-compat redirects (old URLs from prior to 2026-04-27 reshuffle)
                Route::get('/tax-calendar', fn () => redirect()->to(tenant_url('/tax-dashboard')));

                Route::get ('/settings',                    [SettingsController::class, 'show']);
                Route::post('/settings',                    [SettingsController::class, 'save']);
                Route::post('/settings/test-odoo',          [SettingsController::class, 'testOdoo']);

                // Tenant user management — gated to tenant admins (and
                // platform super admins). The list view is open to any
                // signed-in user (read-only) so analysts can see who's
                // in their workspace without escalation; mutating
                // routes are admin-only.
                Route::get('/settings/users',                          [TenantUsersController::class, 'index']);
                Route::middleware('tenant.admin')->group(function () {
                    Route::get   ('/settings/users/create',            [TenantUsersController::class, 'create']);
                    Route::post  ('/settings/users',                   [TenantUsersController::class, 'store']);
                    Route::get   ('/settings/users/{id}/edit',         [TenantUsersController::class, 'edit'])->whereNumber('id');
                    Route::post  ('/settings/users/{id}',              [TenantUsersController::class, 'update'])->whereNumber('id');
                    Route::post  ('/settings/users/{id}/delete',       [TenantUsersController::class, 'destroy'])->whereNumber('id');
                });
            });
        });
    });
