<?php
/**
 * Service & module registry — Elite Advisors Management Suite (EAMS).
 *
 * Two-level catalogue:
 *   - 'services' : top-level offerings the suite delivers (BI, Tax, etc.).
 *   - 'modules'  : the actual features tenants navigate to. Every module
 *                  belongs to exactly one service via its `service` key.
 *
 * Single source of truth for the sidebar, route guard, super-admin toggle UI,
 * and per-tenant feature flags. To add a new module: append an entry below,
 * drop the controller in place, register the route in routes/web.php, ship.
 *
 * Service entry fields:
 *   - key             Stable slug stored in tenant_services.service_key.
 *   - label           Human label rendered as the sidebar section header.
 *   - description     Short blurb for admin UI / marketing pages.
 *   - icon            SVG path data (24x24 viewBox) for the section icon.
 *   - order           Display order in sidebar / admin (ascending).
 *   - default         Default enablement for newly created tenants.
 *   - status          'live' | 'coming_soon'. Coming-soon services render
 *                     greyed-out in the sidebar with a badge until enabled.
 *
 * Module entry fields:
 *   - key             Stable slug stored in tenant_modules.module_key.
 *   - service         FK to a services[].key.
 *   - label           Human label shown in nav and admin UI.
 *   - url             Path users land on when clicking the nav item, or null
 *                     for non-nav modules (e.g. AI Insights rail).
 *   - active          Slug compared against $active in the layout for highlight.
 *   - icon            SVG path data for the sidebar icon (24x24 viewBox).
 *   - default         Whether new tenants get this module enabled by default.
 *   - core            Core modules cannot be disabled (e.g. dashboard).
 *   - status          'live' | 'coming_soon'. Coming-soon modules render in
 *                     the sidebar as greyed-out and route to a placeholder.
 */
return [
    'services' => [
        [
            'key'         => 'business_intelligence',
            'label'       => 'Business Intelligence',
            'description' => 'Real-time KPIs, revenue, profit & loss, customer insights and AI advisory pulled live from Odoo.',
            'icon'        => 'M3 3v18h18M7 14l4-4 3 3 5-5',
            'order'       => 10,
            'default'     => true,
            'status'      => 'live',
        ],
        [
            'key'         => 'financial_reporting',
            'label'       => 'Financial Reporting',
            'description' => 'Statutory and management reports — trial balance, balance sheet, income statement, cash flow, plus GL-quality reconciliation.',
            'icon'        => 'M9 2h6l5 5v15H4V2zM9 2v6h6',
            'order'       => 20,
            'default'     => true,
            'status'      => 'live',
        ],
        [
            'key'         => 'financial_planning',
            'label'       => 'Financial Planning',
            'description' => 'Budgeting, forecasting and scenario modelling on top of your live ledger.',
            'icon'        => 'M3 17l6-6 4 4 8-8M14 7h7v7',
            'order'       => 30,
            'default'     => false,
            'status'      => 'coming_soon',
        ],
        [
            'key'         => 'tax_compliance',
            'label'       => 'Tax Compliance',
            'description' => 'Tax-regime classification, VAT calendar, and invoice-level CGI compliance checks.',
            'icon'        => 'M9 12l2 2 4-4M5 4h14a2 2 0 012 2v14l-4-2-3 2-3-2-4 2V6a2 2 0 012-2z',
            'order'       => 40,
            'default'     => true,
            'status'      => 'live',
        ],
    ],

    'modules' => [
        // ── Business Intelligence ────────────────────────────────────────
        [
            'key'     => 'dashboard',
            'service' => 'business_intelligence',
            'label'   => 'Dashboard',
            'url'     => '/',
            'active'  => 'dashboard',
            'icon'    => 'M3 12l9-9 9 9M5 10v10h14V10',
            'default' => true,
            'core'    => true,
            'status'  => 'live',
        ],
        [
            'key'     => 'revenue',
            'service' => 'business_intelligence',
            'label'   => 'Revenue',
            'url'     => '/revenue',
            'active'  => 'revenue',
            'icon'    => 'M3 3v18h18M7 14l4-4 3 3 5-5',
            'default' => true,
            'core'    => false,
            'status'  => 'live',
        ],
        [
            'key'     => 'customer_insights',
            'service' => 'business_intelligence',
            'label'   => 'Customer Insights',
            'url'     => '/customer-insights',
            'active'  => 'customers',
            'icon'    => 'M4 6h16M4 12h16M4 18h10M14 18l6-6-6-6',
            'default' => true,
            'core'    => false,
            'status'  => 'live',
        ],
        [
            'key'     => 'receivables',
            'service' => 'business_intelligence',
            'label'   => 'Receivables',
            'url'     => '/receivables',
            'active'  => 'receivables',
            'icon'    => 'M12 2v20M5 9h14M5 15h14',
            'default' => true,
            'core'    => false,
            'status'  => 'live',
        ],
        [
            'key'     => 'treasury',
            'service' => 'business_intelligence',
            'label'   => 'Treasury',
            'url'     => '/treasury',
            'active'  => 'treasury',
            'icon'    => 'M12 1v22M2 12h20M4 5h4M4 19h4M16 5h4M16 19h4',
            'default' => true,
            'core'    => false,
            'status'  => 'live',
        ],
        [
            'key'     => 'profit_loss',
            'service' => 'business_intelligence',
            'label'   => 'Profit & Loss',
            'url'     => '/profit-loss',
            'active'  => 'pl',
            'icon'    => 'M4 4v16h16M8 14l3-4 3 3 4-6',
            'default' => true,
            'core'    => false,
            'status'  => 'live',
        ],
        [
            'key'     => 'payables',
            'service' => 'business_intelligence',
            'label'   => 'Payables',
            'url'     => '/payables',
            'active'  => 'payables',
            'icon'    => 'M4 6h16M4 12h16M4 18h16',
            'default' => true,
            'core'    => false,
            'status'  => 'live',
        ],
        [
            'key'     => 'fixed_assets',
            'service' => 'business_intelligence',
            'label'   => 'Fixed Assets',
            'url'     => '/fixed-assets',
            'active'  => 'fixed_assets',
            'icon'    => 'M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6',
            'default' => true,
            'core'    => false,
            'status'  => 'live',
        ],
        [
            'key'     => 'inventory',
            'service' => 'business_intelligence',
            'label'   => 'Inventory',
            'url'     => '/inventory',
            'active'  => 'inventory',
            'icon'    => 'M3 3v18h18V3H3m3 2h12v2H6V5m0 6h12v2H6v-2m0 6h12v2H6v-2',
            'default' => true,
            'core'    => false,
            'status'  => 'live',
        ],
        [
            'key'     => 'ai_insights',
            'service' => 'business_intelligence',
            'label'   => 'AI Insights',
            'url'     => null, // Rendered as a side rail, not a primary nav item.
            'active'  => null,
            'icon'    => 'M12 2L9.5 8.5 3 11l6.5 2.5L12 20l2.5-6.5L21 11l-6.5-2.5z',
            'default' => false,
            'core'    => false,
            'status'  => 'live',
        ],

        // ── Tax Compliance (ported from EA Tax Compliance) ───────────────
        [
            'key'     => 'vat_compliance',
            'service' => 'tax_compliance',
            'label'   => 'Tax Console',
            'url'     => '/vat-compliance',
            'active'  => 'vat_compliance',
            'icon'    => 'M9 12l2 2 4-4M5 4h14a2 2 0 012 2v14l-4-2-3 2-3-2-4 2V6a2 2 0 012-2z',
            'default' => true,
            'core'    => false,
            'status'  => 'live',
        ],
        [
            'key'     => 'vat_invoice_compliance',
            'service' => 'tax_compliance',
            'label'   => 'Invoice Compliance',
            'url'     => '/vat-invoice-compliance',
            'active'  => 'vat_invoice_compliance',
            'icon'    => 'M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8zM14 2v6h6M9 13h6M9 17h6',
            'default' => true,
            'core'    => false,
            'status'  => 'live',
        ],
        [
            'key'     => 'tax_calendar',
            'service' => 'tax_compliance',
            'label'   => 'Tax Calendar',
            'url'     => '/tax-calendar',
            'active'  => 'tax_calendar',
            'icon'    => 'M8 2v4M16 2v4M3 10h18M5 6h14a2 2 0 012 2v12a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2z',
            'default' => true,
            'core'    => false,
            'status'  => 'live',
        ],

        // ── Financial Reporting ───────────────────────────────────────────
        [
            'key'     => 'gl_quality',
            'service' => 'financial_reporting',
            'label'   => 'GL Quality',
            'url'     => '/general-ledger-quality',
            'active'  => 'gl_quality',
            'icon'    => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
            'default' => true,
            'core'    => false,
            'status'  => 'live',
        ],
        [
            'key'     => 'trial_balance',
            'service' => 'financial_reporting',
            'label'   => 'Trial Balance',
            'url'     => '/trial-balance',
            'active'  => 'trial_balance',
            'icon'    => 'M3 6h18M3 12h18M3 18h18',
            'default' => false,
            'core'    => false,
            'status'  => 'coming_soon',
        ],
        [
            'key'     => 'balance_sheet',
            'service' => 'financial_reporting',
            'label'   => 'Balance Sheet',
            'url'     => '/balance-sheet',
            'active'  => 'balance_sheet',
            'icon'    => 'M3 3h18v18H3zM3 12h18M12 3v18',
            'default' => false,
            'core'    => false,
            'status'  => 'coming_soon',
        ],
        [
            'key'     => 'income_statement',
            'service' => 'financial_reporting',
            'label'   => 'Income Statement',
            'url'     => '/income-statement',
            'active'  => 'income_statement',
            'icon'    => 'M4 4v16h16M8 14l3-4 3 3 4-6',
            'default' => false,
            'core'    => false,
            'status'  => 'coming_soon',
        ],
        [
            'key'     => 'cash_flow',
            'service' => 'financial_reporting',
            'label'   => 'Cash Flow',
            'url'     => '/cash-flow',
            'active'  => 'cash_flow',
            'icon'    => 'M12 2v20M5 9l7-7 7 7M5 15l7 7 7-7',
            'default' => false,
            'core'    => false,
            'status'  => 'coming_soon',
        ],

        // ── Financial Planning (coming soon) ─────────────────────────────
        [
            'key'     => 'budgeting',
            'service' => 'financial_planning',
            'label'   => 'Budgeting',
            'url'     => '/budgeting',
            'active'  => 'budgeting',
            'icon'    => 'M3 3v18h18M7 14l4-4 3 3 5-5',
            'default' => false,
            'core'    => false,
            'status'  => 'coming_soon',
        ],
        [
            'key'     => 'forecasting',
            'service' => 'financial_planning',
            'label'   => 'Forecasting',
            'url'     => '/forecasting',
            'active'  => 'forecasting',
            'icon'    => 'M3 17l6-6 4 4 8-8M14 7h7v7',
            'default' => false,
            'core'    => false,
            'status'  => 'coming_soon',
        ],
        [
            'key'     => 'scenario_modeling',
            'service' => 'financial_planning',
            'label'   => 'Scenario Modeling',
            'url'     => '/scenarios',
            'active'  => 'scenarios',
            'icon'    => 'M4 6h16M4 12h10M4 18h7M14 14l3 3 5-5',
            'default' => false,
            'core'    => false,
            'status'  => 'coming_soon',
        ],
    ],
];
