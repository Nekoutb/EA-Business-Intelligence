<?php
/**
 * Registry of every revenue product surfaced on the Product Revenue TSD
 * sub-page (Business Insights → Product Revenue TSD).
 *
 * Single source of truth for:
 *   - the per-product trajectory chart layout (Section A)
 *   - the budget editor (Section C) — one row per product
 *   - the variance summary table (Section B')
 *   - ProductVolumeService → ProductRevenueService composition
 *
 * Data flow for ACTUALS:
 *   ProductVolumeService delegates to OperationsService::scanRevenueBreakdown
 *   for each month of the requested year. The `ops_key` field on each
 *   product names the OperationsService category to read from. Products
 *   with `actuals_source: 'pending'` skip the read entirely and show
 *   budget-only — flip to 'odoo' + add an `ops_key` when the upstream
 *   service starts tracking them.
 *
 * Operational tariff: the per-unit price OperationsService applies to
 * scanned items when computing revenue (the "grille tarifaire"). Used
 * for two things:
 *   1. actual_revenue = actual_qty × operational_tariff
 *   2. actual_price displayed in the variance table = operational_tariff
 *      (which equals actual_revenue ÷ actual_qty by construction)
 *
 * When `operational_tariff` differs from the budgeted `unit_price`, the
 * variance table shows a non-zero price_effect — the user can see at a
 * glance that achieved billing differs from planned billing.
 *
 * Adding a new product:
 *   1. Append an entry below with a unique `key` slug.
 *   2. Set `actuals_source = 'odoo'` and an `ops_key` matching a category
 *      in OperationsService::scanRevenueBreakdown's return shape; or
 *      'pending' for budget-only lines.
 *   3. The view + service pick the new product up automatically.
 *
 * Storage shape inside tenant_budgets.data.products:
 *   [ 'cars' => [
 *       'label'      => 'Cars (4-6 cyl)',
 *       'category'   => 'vehicles',
 *       'unit_price' => 50000,
 *       'volumes'    => ['1' => 3500, '2' => 3500, ..., '12' => 3500],
 *     ], ...
 *   ]
 */
return [
    'categories' => [
        'containers'  => ['label' => 'Containers',  'color' => '#1e3a8a', 'order' => 10],
        'vehicles'    => ['label' => 'Vehicles',    'color' => '#b45309', 'order' => 20],
        'wood'        => ['label' => 'Wood',        'color' => '#166534', 'order' => 30],
        'other_cargo' => ['label' => 'Other Cargo', 'color' => '#6d28d9', 'order' => 40],
    ],

    'products' => [
        // ── Containers — actual VOLUMES from aw_cargo.equipment (scanned),
        //   actual REVENUES from class 706 sub-accounts matched by name.
        //
        // TSD's chart of accounts splits container scanning revenue by
        // direction + size, e.g. 706110 "Scanning 40\" import domestique"
        // for full Import 40' / 45' boxes. We map each container product
        // to the matching account by string-matching the account's name
        // (the `revenue_match` block below). The diagnostic panel on the
        // page surfaces every 706 account and which product it landed in
        // (or "unmatched" if none of the patterns claim it) so the
        // operator can spot a mis-mapping at a glance.
        [
            'key'                => 'container_import_40',
            'label'              => "Container · Import 40' / 45'",
            'category'           => 'containers',
            'color'              => '#1e3a8a',
            'default_price'      => 0,
            'operational_tariff' => 0,        // scan tariff zeroed since May 2026
            'actuals_source'     => 'odoo',
            'ops_key'            => 'import_full_40',
            'revenue_source'     => 'gl_706_named',
            'revenue_match'      => [
                'includes_all' => ['import', '40'],
                'excludes'     => ['20', 'export', 'oog', 'vide', 'empty'],
            ],
        ],
        [
            'key'                => 'container_import_20',
            'label'              => "Container · Import 20'",
            'category'           => 'containers',
            'color'              => '#3b82f6',
            'default_price'      => 0,
            'operational_tariff' => 0,
            'actuals_source'     => 'odoo',
            'ops_key'            => 'import_full_20',
            'revenue_source'     => 'gl_706_named',
            'revenue_match'      => [
                'includes_all' => ['import', '20'],
                'excludes'     => ['40', '45', 'export', 'oog', 'vide', 'empty'],
            ],
        ],
        [
            'key'                => 'container_export_40',
            'label'              => "Container · Export 40' / 45'",
            'category'           => 'containers',
            'color'              => '#0ea5e9',
            'default_price'      => 0,
            'operational_tariff' => 0,
            'actuals_source'     => 'odoo',
            'ops_key'            => 'export_full_40',
            'revenue_source'     => 'gl_706_named',
            'revenue_match'      => [
                'includes_all' => ['export', '40'],
                'excludes'     => ['20', 'import', 'oog', 'vide', 'empty'],
            ],
        ],
        [
            'key'                => 'container_export_20',
            'label'              => "Container · Export 20'",
            'category'           => 'containers',
            'color'              => '#06b6d4',
            'default_price'      => 0,
            'operational_tariff' => 0,
            'actuals_source'     => 'odoo',
            'ops_key'            => 'export_full_20',
            'revenue_source'     => 'gl_706_named',
            'revenue_match'      => [
                'includes_all' => ['export', '20'],
                'excludes'     => ['40', '45', 'import', 'oog', 'vide', 'empty'],
            ],
        ],
        [
            // Conteneurs vides — empty containers (any size, any direction).
            // Billed at 0 per the grille tarifaire (systematic free check).
            // Per direction (2026-05): keep the line in both budget and
            // actuals even though there's no 706 account mapping (revenue
            // stays 0 — vol-only operational tracking).
            'key'                => 'container_empty',
            'label'              => 'Conteneurs vides (Empty)',
            'category'           => 'containers',
            'color'              => '#64748b',
            'default_price'      => 0,
            'operational_tariff' => 0,
            'volume_only'        => true,
            'actuals_source'     => 'odoo',
            'ops_key'            => 'empty',
            'revenue_source'     => 'gl_706_named',
            'revenue_match'      => [
                'includes_any' => ['vide', 'empty', 'mty'],
            ],
        ],
        [
            // Container OOG (Out-Of-Gauge) — oversized cargo that doesn't
            // fit standard ISO container sizes. Revenue lives in
            // 706130 "Scanning OOG"; no scan source in OperationsService
            // (scanRevenueBreakdown doesn't yet expose an OOG category),
            // so volume stays at zero until upstream tracking is added.
            // The chart shows revenue only; unit price (rev ÷ qty) is
            // suppressed when qty == 0 to avoid divide-by-zero noise.
            'key'                => 'container_oog',
            'label'              => 'Container · OOG (Out-of-Gauge)',
            'category'           => 'containers',
            'color'              => '#0891b2',
            'default_price'      => 0,
            'operational_tariff' => 0,
            'actuals_source'     => 'odoo',
            'ops_key'            => null,  // no scan source yet
            'revenue_source'     => 'gl_706_named',
            'revenue_match'      => [
                'includes_any' => ['oog'],
            ],
        ],

        // ── Vehicles — aw_vehicule.vehicule, split by cylinder count.
        //   4 or 6 cyl → Cars      · OperationsService::PRICE_HT_VOITURE  = 50,000
        //   8 cyl       → Trucks    · OperationsService::PRICE_HT_CAMION   = 95,000
        //   12 cyl      → Heavy Machinery · PRICE_HT_BULLDOZE = 105,000
        // Same Odoo model + filter pattern (scanned=true, scanned_date),
        // so OperationsService::scanRevenueBreakdown returns these as the
        // 'voiture', 'camion', 'bulldoze' categories we map from below.
        // Vehicles + Wood: revenue stays on the scan_count × operational_tariff
        // method (unchanged per direction). Class 70 GL revenue for these
        // products will appear as "unmatched" in the diagnostic panel —
        // expected, since the scan-tariff and GL ledger don't reconcile
        // exactly (timing + tax-vs-fee differences).
        [
            'key'                => 'cars',
            'label'              => 'Cars (4-6 cyl)',
            'category'           => 'vehicles',
            'color'              => '#f59e0b',
            'default_price'      => 50_000,
            'operational_tariff' => 50_000,
            'actuals_source'     => 'odoo',
            'ops_key'            => 'voiture',
            // Scanned vehicles with NO cylinders value in Odoo. Confirmed
            // real cars by sampling; priced at the Voiture rate everywhere
            // (Billing Expected, collections scan-mix), so they belong in
            // this product's volume too - otherwise the page's scanned
            // revenue runs permanently below the Billing section.
            'ops_keys_extra'     => ['vehicles_unclassified'],
            'revenue_source'     => 'scan_tariff',
        ],
        [
            'key'                => 'trucks',
            'label'              => 'Trucks (Camion · 8 cyl)',
            'category'           => 'vehicles',
            'color'              => '#b45309',
            'default_price'      => 95_000,
            'operational_tariff' => 95_000,
            'actuals_source'     => 'odoo',
            'ops_key'            => 'camion',
            'revenue_source'     => 'scan_tariff',
        ],
        [
            'key'                => 'heavy_machinery',
            'label'              => 'Heavy Machinery (12 cyl)',
            'category'           => 'vehicles',
            'color'              => '#78350f',
            'default_price'      => 105_000,
            'operational_tariff' => 105_000,
            'actuals_source'     => 'odoo',
            'ops_key'            => 'bulldoze',
            'revenue_source'     => 'scan_tariff',
        ],

        // ── Wood — aw_bois.bois (distinct cargo count per period).
        // OperationsService category key is `wood_per_truckload`.
        [
            'key'                => 'wood',
            'label'              => 'Wood (per truck/wagon)',
            'category'           => 'wood',
            'color'              => '#166534',
            'default_price'      => 35_000,
            'operational_tariff' => 35_000,
            'actuals_source'     => 'odoo',
            'ops_key'            => 'wood_per_truckload',
            'revenue_source'     => 'scan_tariff',
        ],

        // ── Other Cargo — services not yet tracked in Odoo (Year 1 budget-only)
        // Flip `actuals_source` to 'odoo' + add a matching `ops_key` once
        // the service surfaces in OperationsService::scanRevenueBreakdown.
        [
            'key'            => 'general_cargo',
            'label'          => 'General Cargo',
            'category'       => 'other_cargo',
            'color'          => '#ea580c',
            'default_price'  => 65_000,
            'actuals_source' => 'pending',
        ],
        [
            'key'            => 'dry_bulk',
            'label'          => 'Dry Bulk (Rice, Sugar etc.)',
            'category'       => 'other_cargo',
            'color'          => '#6d28d9',
            'default_price'  => 0,   // varies by commodity — operator fills in
            'actuals_source' => 'pending',
        ],
        [
            'key'            => 'pacs_mafi',
            'label'          => 'Packs + MAFI',
            'category'       => 'other_cargo',
            'color'          => '#db2777',
            'default_price'  => 3_500,
            'actuals_source' => 'pending',
        ],
    ],

    /**
     * VAT rate applied for inc-VAT display in the editor footer (HT × (1 + rate)).
     * Doesn't affect any persisted value — budgets always live pre-VAT (HT).
     */
    'vat_rate' => 0.1925,

    /**
     * Redistribution rules — applied AFTER the per-product `revenue_match`
     * pass in GLRevenueService::matchAccountsToProducts. Each rule names
     * a source account (by code or name pattern) and divides its monthly
     * revenue across multiple target products according to fixed shares.
     *
     * Use this for "catch-all" GL accounts whose revenue properly belongs
     * to specific products but was booked at a regional/aggregate level
     * — typical of older charts of accounts that didn't break out
     * service revenue per direction × size.
     *
     * The targets[] map must sum to 1.0 across the row. Shares are
     * applied at the same monthly granularity as the source account, so
     * the per-month redistribution preserves the timing of the original
     * postings.
     */
    'revenue_redistribute' => [
        [
            // 706100 "Services vendus dans la Région" — TSD's catch-all
            // regional services account, 771.55M YTD 2026 and the single
            // largest revenue account (44% of class 7).
            //
            // Superseded (2026-08-18): the May 2026 direction split it
            // 75/25 across container streams, which force-attributed ALL
            // of it to containers. Investigation showed vehicle and wood
            // billing exists nowhere else in the chart — no product, no
            // invoice-line label, no named account (the scan vocabulary
            // BULDOOZER / CAMION / VEHICULE lives only in
            // aw_vehicule.vehicule) — so this account is where that money
            // has to be, and the fixed split both inflated containers and
            // hid vehicles entirely from Treasury collections.
            //
            // scan_mix splits each MONTH of 706100 in proportion to that
            // month's scanned operational value across the listed products
            // (qty × tariff, the same basis as the Product Revenue page),
            // so attribution follows what was actually scanned and can be
            // verified against the scan system. Months with no scans fall
            // back to the old static container split. The clean long-term
            // fix stays: book each cargo type to its own named 706x
            // sub-account, and direct matching takes over automatically.
            'description'  => "706100 — Services vendus dans la Région (catch-all → monthly scan mix)",
            'mode'         => 'scan_mix',
            'mix_products' => ['cars', 'trucks', 'heavy_machinery', 'wood'],
            'source_match' => [
                'code_equals' => '706100',
            ],
            'fallback_targets' => [
                'container_import_40' => 0.375,
                'container_import_20' => 0.375,
                'container_export_40' => 0.125,
                'container_export_20' => 0.125,
            ],
            'targets' => [],
        ],
    ],
];
