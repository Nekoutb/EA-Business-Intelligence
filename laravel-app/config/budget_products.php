<?php
/**
 * Registry of every revenue product surfaced on the Product Revenue TSD
 * sub-page (Business Insights → Product Revenue TSD).
 *
 * Single source of truth for:
 *   - the per-product trajectory chart layout (Section A)
 *   - the budget editor (Section C) — one row per product
 *   - the variance summary table (Section B')
 *   - the ProductVolumeService dispatcher (decides which Odoo round-trip
 *     fetches the actuals for each product)
 *
 * Adding a new product:
 *   1. Append an entry below with a unique `key` slug.
 *   2. Set `actuals_source = 'odoo'` if Odoo can already provide actuals,
 *      with an `odoo` block describing the filter; otherwise set
 *      `actuals_source = 'pending'` and the budget side will render alone
 *      until you flip it on.
 *   3. The view + service pick the new product up automatically — no
 *      hard-coded keys anywhere else.
 *
 * Categories drive the section headers in the editor and the colour
 * coding in the small multiples. Their order here is the visual order
 * on the page.
 *
 * Storage shape inside tenant_budgets.data.products:
 *   [
 *     'cars' => [
 *       'label'      => 'Cars (4-6 cyl)',
 *       'category'   => 'vehicles',
 *       'unit_price' => 50000,
 *       'volumes'    => ['1' => 3500, '2' => 3500, ..., '12' => 3500],
 *     ],
 *     ...
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
        // ── Containers — aw_cargo.equipment grouped by (direction, size, full)
        //
        // Container tariffs were zeroed in OperationsService::scanRevenueBreakdown
        // per client direction (May 2026). They remain in the registry so the
        // operation can budget operational throughput separately from revenue.
        // If the client publishes a non-zero grille tarifaire, the budget
        // editor accepts a unit price and revenue lights up automatically.
        [
            'key'            => 'container_import_40',
            'label'          => "Container · Import 40' / 45'",
            'category'       => 'containers',
            'color'          => '#1e3a8a',
            'default_price'  => 0,
            'actuals_source' => 'odoo',
            'odoo'           => [
                'model'  => 'aw_cargo.equipment',
                'filter' => [
                    ['cargo_id.direction', '=', 'in'],
                    ['full', '=', 'yes'],
                    ['size', 'in', ['40', '45']],
                    ['cargo_id.journey_type', '!=', 'transit'],
                ],
            ],
        ],
        [
            'key'            => 'container_import_20',
            'label'          => "Container · Import 20'",
            'category'       => 'containers',
            'color'          => '#3b82f6',
            'default_price'  => 0,
            'actuals_source' => 'odoo',
            'odoo'           => [
                'model'  => 'aw_cargo.equipment',
                'filter' => [
                    ['cargo_id.direction', '=', 'in'],
                    ['full', '=', 'yes'],
                    ['size', '=', '20'],
                    ['cargo_id.journey_type', '!=', 'transit'],
                ],
            ],
        ],
        [
            'key'            => 'container_export_40',
            'label'          => "Container · Export 40' / 45'",
            'category'       => 'containers',
            'color'          => '#0ea5e9',
            'default_price'  => 0,
            'actuals_source' => 'odoo',
            'odoo'           => [
                'model'  => 'aw_cargo.equipment',
                'filter' => [
                    ['cargo_id.direction', '=', 'out'],
                    ['full', '=', 'yes'],
                    ['size', 'in', ['40', '45']],
                    ['cargo_id.journey_type', '!=', 'transit'],
                ],
            ],
        ],
        [
            'key'            => 'container_export_20',
            'label'          => "Container · Export 20'",
            'category'       => 'containers',
            'color'          => '#06b6d4',
            'default_price'  => 0,
            'actuals_source' => 'odoo',
            'odoo'           => [
                'model'  => 'aw_cargo.equipment',
                'filter' => [
                    ['cargo_id.direction', '=', 'out'],
                    ['full', '=', 'yes'],
                    ['size', '=', '20'],
                    ['cargo_id.journey_type', '!=', 'transit'],
                ],
            ],
        ],
        [
            // Conteneurs vides — empty containers (any size, any direction).
            // Billed at 0 per the grille tarifaire (systematic free check),
            // but volumes are tracked so the operation can monitor handling
            // throughput separately from revenue.
            'key'            => 'container_empty',
            'label'          => 'Conteneurs vides (Empty)',
            'category'       => 'containers',
            'color'          => '#64748b',
            'default_price'  => 0,
            'volume_only'    => true,
            'actuals_source' => 'odoo',
            'odoo'           => [
                'model'  => 'aw_cargo.equipment',
                'filter' => [['full', '=', 'no']],
            ],
        ],

        // ── Vehicles — aw_vehicule.vehicule split by cylinder count
        //
        // The customer's billing legend (aw_cargo_invoice.product) carries
        // the same size codes the vehicle table uses for `cylinders`. See
        // OperationsService::scanRevenueBreakdown for the canonical mapping:
        //   4|6 → Voiture/Cars · 8 → Camion/Trucks · 12 → Heavy Machinery
        //
        // Heavy Machinery was historically labelled "Bulldoze" in the Odoo
        // notes; the customer's budget uses "Heavy Machinery" so the BI app
        // adopts that label. Same Odoo source, friendlier name.
        [
            'key'            => 'cars',
            'label'          => 'Cars (4-6 cyl)',
            'category'       => 'vehicles',
            'color'          => '#f59e0b',
            'default_price'  => 50000,
            'actuals_source' => 'odoo',
            'odoo'           => [
                'model'  => 'aw_vehicule.vehicule',
                'filter' => [
                    ['scanned', '=', true],
                    ['cylinders', 'in', [4, 6]],
                ],
                'date_field' => 'scanned_date',
            ],
        ],
        [
            'key'            => 'trucks',
            'label'          => 'Trucks (Camion · 8 cyl)',
            'category'       => 'vehicles',
            'color'          => '#b45309',
            'default_price'  => 95000,
            'actuals_source' => 'odoo',
            'odoo'           => [
                'model'  => 'aw_vehicule.vehicule',
                'filter' => [
                    ['scanned', '=', true],
                    ['cylinders', '=', 8],
                ],
                'date_field' => 'scanned_date',
            ],
        ],
        [
            'key'            => 'heavy_machinery',
            'label'          => 'Heavy Machinery (12 cyl)',
            'category'       => 'vehicles',
            'color'          => '#78350f',
            'default_price'  => 105000,
            'actuals_source' => 'odoo',
            'odoo'           => [
                'model'  => 'aw_vehicule.vehicule',
                'filter' => [
                    ['scanned', '=', true],
                    ['cylinders', '=', 12],
                ],
                'date_field' => 'scanned_date',
            ],
        ],

        // ── Wood — aw_bois.bois grouped by cargo (each cargo ≈ one truckload)
        [
            'key'            => 'wood',
            'label'          => 'Wood (per truck/wagon)',
            'category'       => 'wood',
            'color'          => '#166534',
            'default_price'  => 35000,
            'actuals_source' => 'odoo',
            'odoo'           => [
                'model'      => 'aw_bois.bois',
                'filter'     => [['scanned', '=', true]],
                'date_field' => 'scanned_date',
                'group_by'   => 'cargo_id',  // distinct cargo count = truckloads
            ],
        ],

        // ── Other Cargo — services not yet tracked in Odoo (Year 1 budget-only)
        //
        // Flip `actuals_source` to 'odoo' + add an `odoo` block when each
        // becomes trackable. The view automatically picks up the new feed
        // — no code changes required.
        [
            'key'            => 'general_cargo',
            'label'          => 'General Cargo',
            'category'       => 'other_cargo',
            'color'          => '#ea580c',
            'default_price'  => 65000,
            'actuals_source' => 'pending',
        ],
        [
            'key'            => 'dry_bulk',
            'label'          => 'Dry Bulk (Rice, Sugar etc.)',
            'category'       => 'other_cargo',
            'color'          => '#6d28d9',
            'default_price'  => 0,   // price varies by commodity — operator fills in
            'actuals_source' => 'pending',
        ],
        [
            'key'            => 'pacs_mafi',
            'label'          => 'Packs + MAFI',
            'category'       => 'other_cargo',
            'color'          => '#db2777',
            'default_price'  => 3500,
            'actuals_source' => 'pending',
        ],
    ],

    /**
     * VAT rate applied for inc-VAT display in the editor footer (HT × (1 + rate)).
     * Doesn't affect any persisted value — budgets always live pre-VAT (HT).
     */
    'vat_rate' => 0.1925,
];
