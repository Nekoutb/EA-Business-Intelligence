<?php

return [
    'industries' => [
        [
            'key'         => 'retail',
            'name'        => 'Retail',
            'description' => 'Retail stores, e-commerce, multi-location sales',
            'icon'        => 'M3 3h2l.4 2.6M7 13v4a2 2 0 01-2 2H3m0 0a2 2 0 01-2-2v-4m6-6v-2a2 2 0 012-2h2a2 2 0 012 2v2m0 0h5a2 2 0 012 2v4m0 0a2 2 0 01-2 2h-2.5',
            'sort_order'  => 10,
            'active'      => true,
        ],
        [
            'key'         => 'manufacturing',
            'name'        => 'Manufacturing',
            'description' => 'Production, inventory, supply chain management',
            'icon'        => 'M5 9V7a2 2 0 012-2h6a2 2 0 012 2v2m-6 4h6m-6 0H9m2 0h2m0 0h2m-4 3v2a2 2 0 01-2 2H5a2 2 0 01-2-2v-2m12-3v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2',
            'sort_order'  => 20,
            'active'      => true,
        ],
        [
            'key'         => 'professional_services',
            'name'        => 'Professional Services',
            'description' => 'Consulting, law, accounting, design agencies',
            'icon'        => 'M9 12l2 2 4-4m7 0a9 9 0 11-18 0 9 9 0 0118 0z',
            'sort_order'  => 30,
            'active'      => true,
        ],
        [
            'key'         => 'saas',
            'name'        => 'SaaS / Technology',
            'description' => 'Software, subscriptions, tech products',
            'icon'        => 'M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4',
            'sort_order'  => 40,
            'active'      => true,
        ],
        [
            'key'         => 'healthcare',
            'name'        => 'Healthcare',
            'description' => 'Medical practices, clinics, hospitals',
            'icon'        => 'M12 8v4m0 0v4m0-4h4m0 0h-4m0 0H8m4-12a9 9 0 110 18 9 9 0 010-18z',
            'sort_order'  => 50,
            'active'      => true,
        ],
        [
            'key'         => 'non_profit',
            'name'        => 'Non-Profit',
            'description' => 'Charities, foundations, social enterprises',
            'icon'        => 'M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z',
            'sort_order'  => 60,
            'active'      => true,
        ],
        [
            'key'         => 'real_estate',
            'name'        => 'Real Estate',
            'description' => 'Properties, development, property management',
            'icon'        => 'M3 21l8.68-9.231m0 0l7.32-7.231 1.414 1.414L13 12.586l8.68 9.231m-6-6l6-6',
            'sort_order'  => 70,
            'active'      => true,
        ],
        [
            'key'         => 'hospitality',
            'name'        => 'Hospitality & Food Service',
            'description' => 'Hotels, restaurants, catering, events',
            'icon'        => 'M12 6v6m0 0v6m0-6h6m0 0h-6m0 0H6m6 0a6 6 0 110-12 6 6 0 010 12z',
            'sort_order'  => 80,
            'active'      => true,
        ],

        // ── Sectors of activity (2026-08): segment tenants whose reports
        //    differ structurally — fashion (Kissywears), logistics service
        //    provider (Elimelec), container scanning (Transatlantic).
        //    The sector drives module defaults AND dashboard-KPI defaults
        //    (outfit metrics only make sense for fashion).
        [
            'key'         => 'fashion',
            'name'        => 'Fashion',
            'description' => 'Apparel retail & production — outfit-level revenue, discount and cost metrics',
            'icon'        => 'M16 3l4 4-2 3-2-1v12H8V9L6 10 4 7l4-4c.7 1.2 2.2 2 4 2s3.3-.8 4-2z',
            'sort_order'  => 90,
            'active'      => true,
        ],
        [
            'key'         => 'logistics_services',
            'name'        => 'Logistics Service Provider',
            'description' => 'Customs clearing, freight forwarding, transit — dossier-based operations',
            'icon'        => 'M9 17a2 2 0 11-4 0 2 2 0 014 0zM19 17a2 2 0 11-4 0 2 2 0 014 0zM13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a1 1 0 001 1h1',
            'sort_order'  => 100,
            'active'      => true,
        ],
        [
            'key'         => 'container_scanning',
            'name'        => 'Container Scanning',
            'description' => 'Port scanning operations — vessel calls, containers handled, scan revenue',
            'icon'        => 'M3 7v10a2 2 0 002 2h14a2 2 0 002-2V7M3 7l9-4 9 4M3 7h18M7 11h2m6 0h2M7 15h2m6 0h2',
            'sort_order'  => 110,
            'active'      => true,
        ],
    ],

    'bundles' => [
        'retail' => [
            'services' => ['business_intelligence', 'tax_compliance'],
            'modules'  => [
                'dashboard'              => ['default' => true, 'required' => true],
                'revenue'                => ['default' => true, 'required' => false],
                'inventory'              => ['default' => true, 'required' => false],
                'customer_insights'      => ['default' => true, 'required' => false],
                'receivables'            => ['default' => true, 'required' => false],
                'payables'               => ['default' => false, 'required' => false],
                'treasury'               => ['default' => false, 'required' => false],
                'profit_loss'            => ['default' => true, 'required' => false],
                'fixed_assets'           => ['default' => false, 'required' => false],
                'ai_insights'            => ['default' => false, 'required' => false],
                'vat_compliance'         => ['default' => true, 'required' => false],
                'vat_invoice_compliance' => ['default' => true, 'required' => false],
                'monthly_tax_return'     => ['default' => true, 'required' => false],
                'tax_calendar'           => ['default' => false, 'required' => false],
                'gl_quality'             => ['default' => false, 'required' => false],
            ],
        ],

        'manufacturing' => [
            'services' => ['business_intelligence', 'financial_reporting', 'tax_compliance'],
            'modules'  => [
                'dashboard'              => ['default' => true, 'required' => true],
                'revenue'                => ['default' => true, 'required' => false],
                'inventory'              => ['default' => true, 'required' => false],
                'fixed_assets'           => ['default' => true, 'required' => false],
                'payables'               => ['default' => true, 'required' => false],
                'profit_loss'            => ['default' => true, 'required' => false],
                'receivables'            => ['default' => true, 'required' => false],
                'customer_insights'      => ['default' => false, 'required' => false],
                'treasury'               => ['default' => false, 'required' => false],
                'ai_insights'            => ['default' => false, 'required' => false],
                'vat_compliance'         => ['default' => true, 'required' => false],
                'vat_invoice_compliance' => ['default' => true, 'required' => false],
                'monthly_tax_return'     => ['default' => true, 'required' => false],
                'tax_calendar'           => ['default' => false, 'required' => false],
                'gl_quality'             => ['default' => true, 'required' => false],
                'trial_balance'          => ['default' => false, 'required' => false],
                'balance_sheet'          => ['default' => false, 'required' => false],
                'income_statement'       => ['default' => false, 'required' => false],
                'cash_flow'              => ['default' => false, 'required' => false],
            ],
        ],

        'professional_services' => [
            'services' => ['business_intelligence', 'financial_reporting', 'tax_compliance'],
            'modules'  => [
                'dashboard'              => ['default' => true, 'required' => true],
                'revenue'                => ['default' => true, 'required' => false],
                'receivables'            => ['default' => true, 'required' => false],
                'profit_loss'            => ['default' => true, 'required' => false],
                'customer_insights'      => ['default' => true, 'required' => false],
                'payables'               => ['default' => true, 'required' => false],
                'treasury'               => ['default' => false, 'required' => false],
                'inventory'              => ['default' => false, 'required' => false],
                'fixed_assets'           => ['default' => false, 'required' => false],
                'ai_insights'            => ['default' => false, 'required' => false],
                'vat_compliance'         => ['default' => true, 'required' => false],
                'vat_invoice_compliance' => ['default' => true, 'required' => false],
                'monthly_tax_return'     => ['default' => true, 'required' => false],
                'tax_calendar'           => ['default' => false, 'required' => false],
                'gl_quality'             => ['default' => true, 'required' => false],
            ],
        ],

        'saas' => [
            'services' => ['business_intelligence', 'financial_planning', 'tax_compliance'],
            'modules'  => [
                'dashboard'              => ['default' => true, 'required' => true],
                'revenue'                => ['default' => true, 'required' => false],
                'receivables'            => ['default' => true, 'required' => false],
                'profit_loss'            => ['default' => true, 'required' => false],
                'customer_insights'      => ['default' => true, 'required' => false],
                'payables'               => ['default' => true, 'required' => false],
                'treasury'               => ['default' => true, 'required' => false],
                'ai_insights'            => ['default' => true, 'required' => false],
                'inventory'              => ['default' => false, 'required' => false],
                'fixed_assets'           => ['default' => false, 'required' => false],
                'vat_compliance'         => ['default' => true, 'required' => false],
                'vat_invoice_compliance' => ['default' => false, 'required' => false],
                'monthly_tax_return'     => ['default' => false, 'required' => false],
                'tax_calendar'           => ['default' => false, 'required' => false],
                'gl_quality'             => ['default' => true, 'required' => false],
                'forecasting'            => ['default' => false, 'required' => false],
            ],
        ],

        'healthcare' => [
            'services' => ['business_intelligence', 'financial_reporting', 'tax_compliance'],
            'modules'  => [
                'dashboard'              => ['default' => true, 'required' => true],
                'revenue'                => ['default' => true, 'required' => false],
                'receivables'            => ['default' => true, 'required' => false],
                'payables'               => ['default' => true, 'required' => false],
                'profit_loss'            => ['default' => true, 'required' => false],
                'inventory'              => ['default' => true, 'required' => false],
                'customer_insights'      => ['default' => false, 'required' => false],
                'treasury'               => ['default' => false, 'required' => false],
                'fixed_assets'           => ['default' => true, 'required' => false],
                'ai_insights'            => ['default' => false, 'required' => false],
                'vat_compliance'         => ['default' => true, 'required' => false],
                'vat_invoice_compliance' => ['default' => true, 'required' => false],
                'monthly_tax_return'     => ['default' => true, 'required' => false],
                'tax_calendar'           => ['default' => false, 'required' => false],
                'gl_quality'             => ['default' => true, 'required' => false],
            ],
        ],

        'non_profit' => [
            'services' => ['business_intelligence', 'financial_reporting'],
            'modules'  => [
                'dashboard'              => ['default' => true, 'required' => true],
                'revenue'                => ['default' => true, 'required' => false],
                'receivables'            => ['default' => true, 'required' => false],
                'payables'               => ['default' => true, 'required' => false],
                'profit_loss'            => ['default' => true, 'required' => false],
                'customer_insights'      => ['default' => true, 'required' => false],
                'inventory'              => ['default' => false, 'required' => false],
                'treasury'               => ['default' => true, 'required' => false],
                'fixed_assets'           => ['default' => true, 'required' => false],
                'ai_insights'            => ['default' => false, 'required' => false],
                'vat_compliance'         => ['default' => false, 'required' => false],
                'gl_quality'             => ['default' => true, 'required' => false],
                'trial_balance'          => ['default' => false, 'required' => false],
                'balance_sheet'          => ['default' => false, 'required' => false],
                'income_statement'       => ['default' => false, 'required' => false],
            ],
        ],

        'real_estate' => [
            'services' => ['business_intelligence', 'financial_reporting', 'tax_compliance'],
            'modules'  => [
                'dashboard'              => ['default' => true, 'required' => true],
                'revenue'                => ['default' => true, 'required' => false],
                'receivables'            => ['default' => true, 'required' => false],
                'payables'               => ['default' => true, 'required' => false],
                'profit_loss'            => ['default' => true, 'required' => false],
                'fixed_assets'           => ['default' => true, 'required' => false],
                'treasury'               => ['default' => false, 'required' => false],
                'customer_insights'      => ['default' => false, 'required' => false],
                'inventory'              => ['default' => false, 'required' => false],
                'ai_insights'            => ['default' => false, 'required' => false],
                'vat_compliance'         => ['default' => true, 'required' => false],
                'vat_invoice_compliance' => ['default' => true, 'required' => false],
                'monthly_tax_return'     => ['default' => true, 'required' => false],
                'tax_calendar'           => ['default' => false, 'required' => false],
                'gl_quality'             => ['default' => true, 'required' => false],
            ],
        ],

        'hospitality' => [
            'services' => ['business_intelligence', 'tax_compliance'],
            'modules'  => [
                'dashboard'              => ['default' => true, 'required' => true],
                'revenue'                => ['default' => true, 'required' => false],
                'inventory'              => ['default' => true, 'required' => false],
                'customer_insights'      => ['default' => true, 'required' => false],
                'profit_loss'            => ['default' => true, 'required' => false],
                'receivables'            => ['default' => true, 'required' => false],
                'payables'               => ['default' => true, 'required' => false],
                'treasury'               => ['default' => false, 'required' => false],
                'fixed_assets'           => ['default' => false, 'required' => false],
                'ai_insights'            => ['default' => false, 'required' => false],
                'vat_compliance'         => ['default' => true, 'required' => false],
                'vat_invoice_compliance' => ['default' => true, 'required' => false],
                'monthly_tax_return'     => ['default' => true, 'required' => false],
                'tax_calendar'           => ['default' => false, 'required' => false],
            ],
        ],

        // ── Sector bundles ─────────────────────────────────────────────
        // These may carry an extra 'dashboard_kpis' key:
        //   'dashboard_kpis' => ['disabled' => [<kpi keys off by default>]]
        // Applied at tenant creation (TenantController@store) on top of the
        // registry defaults in config/dashboard_kpis.php. Outfit metrics
        // (RpO, OpD, DpOT, CpO, product tables) only exist for fashion.

        'fashion' => [
            'services' => ['business_intelligence', 'financial_reporting'],
            'modules'  => [
                'dashboard'           => ['default' => true,  'required' => true],
                'revenue'             => ['default' => true,  'required' => false],
                'product_revenue_tsd' => ['default' => true,  'required' => false],
                'customer_insights'   => ['default' => true,  'required' => false],
                'receivables'         => ['default' => true,  'required' => false],
                'treasury'            => ['default' => true,  'required' => false],
                'billing'             => ['default' => true,  'required' => false],
                'profit_loss'         => ['default' => true,  'required' => false],
                'inventory'           => ['default' => false, 'required' => false],
                'gl_quality'          => ['default' => false, 'required' => false],
            ],
        ],

        'logistics_services' => [
            'services' => ['business_intelligence'],
            'modules'  => [
                'dashboard'           => ['default' => true,  'required' => true],
                'revenue'             => ['default' => true,  'required' => false],
                'customer_insights'   => ['default' => true,  'required' => false],
                'receivables'         => ['default' => true,  'required' => false],
                'billing'             => ['default' => false, 'required' => false],
                // The Logistics Operations pages (dossiers, workflow,
                // volumes, billing & advances — approved Elimelec plan)
                // default TRUE here once their module keys ship.
                'product_revenue_tsd' => ['default' => false, 'required' => false],
                'inventory'           => ['default' => false, 'required' => false],
                'operations'          => ['default' => false, 'required' => false],
            ],
            'dashboard_kpis' => [
                'disabled' => ['rpo', 'opd', 'dpot', 'cpo', 'top_profitable_products'],
            ],
        ],

        'container_scanning' => [
            'services' => ['business_intelligence'],
            'modules'  => [
                'dashboard'           => ['default' => true,  'required' => true],
                // The existing Operations page (vessel calls, containers,
                // scan revenue — built for Transatlantic) IS the scanning
                // sector's operations module.
                'operations'          => ['default' => true,  'required' => true],
                'revenue'             => ['default' => true,  'required' => false],
                'receivables'         => ['default' => true,  'required' => false],
                'treasury'            => ['default' => false, 'required' => false],
                'customer_insights'   => ['default' => false, 'required' => false],
                'product_revenue_tsd' => ['default' => false, 'required' => false],
                'inventory'           => ['default' => false, 'required' => false],
            ],
            'dashboard_kpis' => [
                'disabled' => ['rpo', 'opd', 'dpot', 'cpo', 'top_profitable_products'],
            ],
        ],
    ],
];
