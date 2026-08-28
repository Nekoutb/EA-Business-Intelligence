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
            'description' => 'Working-capital ratios, budgeting, forecasting and scenario modelling on top of your live ledger.',
            'icon'        => 'M3 17l6-6 4 4 8-8M14 7h7v7',
            'order'       => 30,
            'default'     => true,
            'status'      => 'live',
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
        [
            'key'         => 'human_resources',
            'label'       => 'Human Resources',
            'description' => 'Payroll processing, statutory contributions, and employee-benefits accounting connected to the live ledger.',
            'icon'        => 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 014-4h.5m4-2a4 4 0 100-8 4 4 0 000 8zm6-2a3 3 0 100-6 3 3 0 000 6z',
            'order'       => 50,
            'default'     => false,
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
            // Reports — day-level operational report. A calendar picks a date;
            // the page discloses that day's sales (cash vs credit, outfits,
            // customers) and inventory movements (inward/outward at standard
            // cost). Universal for any tenant with the Odoo accounting + stock
            // modules; default=true.
            'key'     => 'reports',
            'service' => 'business_intelligence',
            'label'   => 'Reports',
            // Direct route. (Was '/inventory?view=reports' while this host's
            // route cache was frozen — that detour gated Reports behind the
            // INVENTORY module and 403'd every workspace without it, e.g.
            // logistics tenants. Deploys rebuild the route cache since
            // 2026-07-30, so the workaround is obsolete.)
            'url'     => '/reports',
            'active'  => 'reports',
            'icon'    => 'M8 7V3m8 4V3M3 11h18M5 5h14a2 2 0 012 2v12a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2z',
            'default' => true,
            'core'    => false,
            'status'  => 'live',
        ],
        [
            // Daily Report Email — hidden module (url=null → no sidebar entry)
            // gating the 08:00 prior-day activity email (reports:send-daily).
            // default=false: the super admin enables it per tenant whose owner
            // wants the daily digest; recipients are the tenant's active
            // admin/owner users.
            'key'     => 'daily_report',
            'service' => 'business_intelligence',
            'label'   => 'Daily Report Email',
            'url'     => null,
            'active'  => null,
            'icon'    => 'M3 8l9 6 9-6M5 5h14a2 2 0 012 2v10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2z',
            'default' => false,
            'core'    => false,
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
            // Product Revenue TSD — per-product revenue trajectory page
            // (Containers · Vehicles · Wood · Other Cargo), comparing
            // scanned actuals to a per-product unit-price × monthly-volume
            // budget. Built for Transatlantic Douala (TSD); the registry
            // in config/budget_products.php is generic so any tenant with
            // a comparable product mix can enable it. default=false because
            // it only makes sense for tenants whose Odoo carries the cargo
            // models (aw_cargo.equipment, aw_vehicule.vehicule, aw_bois.bois).
            'key'     => 'product_revenue_tsd',
            'service' => 'business_intelligence',
            'label'   => 'Product Revenue TSD',
            'url'     => '/product-revenue-tsd',
            'active'  => 'product_revenue_tsd',
            'icon'    => 'M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z',
            'default' => false,
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
            // Profitability — unit economics (revenue / cost / employee cost
            // per scan) + profit growth. Scan-specific (logistics), so
            // default=false like Operations; super admin enables per tenant.
            // Served via the already-cached /profit-loss route
            // (?view=profitability) because a dedicated /profitability route
            // 404s behind this host's stale route cache.
            'key'     => 'profitability',
            'service' => 'business_intelligence',
            'label'   => 'Profitability',
            'url'     => '/profitability',
            'active'  => 'profitability',
            'icon'    => 'M12 1v22M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6',
            'default' => false,
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
            // Operations — container & logistics analytics, populated from
            // the customer's Odoo Logistique module (scans, manifestes,
            // EDI). Distinct from Inventory (which deals with stock-on-hand
            // for retail/manufacturing): Operations is for businesses whose
            // P&L revolves around moving physical units through a logistics
            // pipeline (containers, shipments, deliveries).
            //
            // default=false on purpose — only tenants with a Logistique
            // module in their Odoo benefit. Super admin enables per tenant.
            'key'     => 'operations',
            'service' => 'business_intelligence',
            'label'   => 'Operations',
            'url'     => '/operations',
            'active'  => 'operations',
            'icon'    => 'M4 7v10a2 2 0 002 2h12a2 2 0 002-2V7M4 7l8-4 8 4M4 7h16M9 11h6M9 15h6',
            'default' => false,
            'core'    => false,
            'status'  => 'live',
        ],
        [
            // Logistics Operations (dossier-based sector, e.g. Elimelec):
            // clearing-file throughput, backlog and aging.
            'key'     => 'logistics_dossiers',
            'service' => 'business_intelligence',
            'label'   => 'Files',
            'url'     => '/logistics/dossiers',
            'active'  => 'logistics_dossiers',
            'icon'    => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
            'default' => false,
            'core'    => false,
            'status'  => 'live',
        ],
        [
            'key'     => 'logistics_workflow',
            'service' => 'business_intelligence',
            'label'   => 'Workflow',
            'url'     => '/logistics/workflow',
            'active'  => 'logistics_workflow',
            'icon'    => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4',
            'default' => false,
            'core'    => false,
            'status'  => 'live',
        ],
        [
            // Container-scanning operations for instances WITHOUT the
            // aw_cargo.* models (e.g. Transatlantic's ERP_TSA_SIP_PRODUCTION).
            // Admin sees "Operations_TSA" so it is distinguishable from the
            // original aw_cargo-based Operations module; the tenant sidebar
            // shows plain "Operations". Enable whichever fits the instance.
            'key'       => 'operations_tsa',
            'service'   => 'business_intelligence',
            'label'     => 'Operations_TSA',
            'nav_label' => 'Operations',
            'url'       => '/operations-tsa',
            'active'    => 'operations_tsa',
            'icon'      => 'M3 7v10a2 2 0 002 2h14a2 2 0 002-2V7M3 7l9-4 9 4M3 7h18M7 11h2m6 0h2M7 15h2m6 0h2',
            'default'   => false,
            'core'      => false,
            'status'    => 'live',
        ],
        [
            'key'     => 'logistics_oop',
            'service' => 'business_intelligence',
            'label'   => 'OOP Compliance',
            'url'     => '/logistics/oop',
            'active'  => 'logistics_oop',
            'icon'    => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-1 9l-3 3-1.5-1.5',
            'default' => false,
            'core'    => false,
            'status'  => 'live',
        ],
        [
            'key'     => 'reports_logistics',
            'service' => 'business_intelligence',
            // 'label' is what admin screens show (module toggles, tenant
            // create wizard) — kept as "Reports_Logistics" so it's never
            // confused with the generic 'reports' module in that context.
            // 'nav_label' overrides ONLY the tenant-facing sidebar entry,
            // where it should read plainly as "Reports" (see
            // layouts/app.blade.php's nav loop).
            'label'     => 'Reports_Logistics',
            'nav_label' => 'Reports',
            'url'     => '/logistics/reports',
            'active'  => 'reports_logistics',
            'icon'    => 'M9 17v-6m4 6V7m4 10v-4M5 5h14a2 2 0 012 2v12a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2z',
            'default' => false,
            'core'    => false,
            'status'  => 'live',
        ],
        [
            'key'     => 'logistics_volumes',
            'service' => 'business_intelligence',
            'label'   => 'Volumes',
            'url'     => '/logistics/volumes',
            'active'  => 'logistics_volumes',
            'icon'    => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4',
            'default' => false,
            'core'    => false,
            'status'  => 'live',
        ],
        [
            // Billing — invoice issuance lens (volume, time-to-invoice on
            // shipment basis, credit-note rate). Complements Receivables
            // (which lives in the collection lens). Universally applicable
            // for any tenant with the Odoo accounting module.
            'key'     => 'billing',
            'service' => 'business_intelligence',
            'label'   => 'Billing',
            'url'     => '/billing',
            'active'  => 'billing',
            'icon'    => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2M9 12h6m-6 4h4',
            'default' => true,
            'core'    => false,
            'status'  => 'live',
        ],
        [
            // Revenue Reconciliation — sub-feature of Billing that surfaces
            // the Expected → Billed → Collected three-way reconciliation
            // (the scan-to-cash sankey on /Dashboard, the two-step panel +
            // detail table + XLS export on /Billing).
            //
            // Modelled as a hidden module (`url => null` so it doesn't
            // appear in the sidebar) so super-admins can toggle it per-
            // tenant from /admin/tenants/{id} without disabling the rest
            // of Billing — useful for clients whose ledger doesn't carry
            // the operational scan data the "Expected" leg requires.
            'key'     => 'revenue_reconciliation',
            'service' => 'business_intelligence',
            'label'   => 'Revenue Reconciliation',
            'url'     => null,
            'active'  => null,
            'icon'    => 'M3 4v16h18M9 17V9m4 8V5m4 12v-7',
            'default' => true,
            'core'    => false,
            'status'  => 'live',
        ],
        [
            // Procurement Dashboard — one-page governance view of the PO
            // cycle: 8 KPIs vs admin-set targets (operator + value), computed
            // cumulative YTD as at the last closed month-end. Designed to be
            // printed/exported for meetings; Procurement (below) is its
            // detail page. Hosted on the cached /procurement route
            // (?view=dashboard) because a dedicated route 404s behind this
            // host's frozen route cache.
            //
            // default=false — same prerequisite as Procurement (Odoo
            // `purchase` module). Super admin enables per tenant.
            'key'     => 'procurement_dashboard',
            'service' => 'business_intelligence',
            'label'   => 'Procurement Dashboard',
            'url'     => '/procurement-dashboard',
            'active'  => 'procurement_dashboard',
            'icon'    => 'M9 17V9m4 8V5m4 12v-4M5 21h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z',
            'default' => false,
            'core'    => false,
            'status'  => 'live',
        ],
        [
            // Procurement — PO lifecycle lens (volume + spend, cycle time,
            // PO compliance / maverick spend). Complements Payables (which
            // lives in the what-we-owe lens).
            //
            // default=false — requires the Odoo `purchase` module installed
            // at the tenant. Super admin enables per tenant once confirmed.
            'key'     => 'procurement',
            'service' => 'business_intelligence',
            'label'   => 'Procurement',
            'url'     => '/procurement',
            'active'  => 'procurement',
            'icon'    => 'M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z',
            'default' => false,
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

        // ── Tax Compliance ───────────────────────────────────────────────
        [
            'key'     => 'tax_dashboard',
            'service' => 'tax_compliance',
            'label'   => 'Tax Dashboard',
            'url'     => '/tax-dashboard',
            'active'  => 'tax_dashboard',
            'icon'    => 'M3 3v18h18M7 14l4-4 3 3 5-5',
            'default' => true,
            'core'    => false,
            'status'  => 'live',
        ],
        [
            'key'     => 'income_tax_compliance',
            'service' => 'tax_compliance',
            'label'   => 'Income Tax Compliance',
            'url'     => '/income-tax-compliance',
            'active'  => 'income_tax_compliance',
            'icon'    => 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
            'default' => true,
            'core'    => false,
            'status'  => 'live',
        ],
        [
            'key'     => 'vat_compliance',
            'service' => 'tax_compliance',
            'label'   => 'VAT Compliance',
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
            'key'     => 'monthly_tax_return',
            'service' => 'tax_compliance',
            'label'   => 'Monthly Tax Return',
            'url'     => '/monthly-tax-return',
            'active'  => 'monthly_tax_return',
            'icon'    => 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
            'default' => true,
            'core'    => false,
            'status'  => 'live',
        ],
        [
            // Return Reconciliation — sub-feature positioned under
            // Tax Returns. Reconciles filed returns to the GL so the
            // auditor can spot mis-declarations or carry-forward gaps
            // between successive periods.
            //
            // Live since 2026-05-14 (PR #142): the page ties the
            // Monthly Tax Return's §2 declared CA Global HT against
            // the GL ground truth (sum of class-70 movements) and the
            // sales-ledger total (L10 + L14), with two checks and a
            // list of discrepancy origins per check for investigation.
            'key'     => 'return_reconciliation',
            'service' => 'tax_compliance',
            'label'   => 'Return Reconciliation',
            'url'     => '/return-reconciliation',
            'active'  => 'return_reconciliation',
            'icon'    => 'M4 4v16h16M8 14l3-4 3 3 4-6M16 4h4v4',
            'default' => true,
            'core'    => false,
            'status'  => 'live',
        ],

        // ── Financial Reporting ───────────────────────────────────────────
        [
            'key'     => 'trial_balance',
            'service' => 'financial_reporting',
            'label'   => 'Trial Balance',
            'url'     => '/trial-balance',
            'active'  => 'trial_balance',
            'icon'    => 'M3 6h18M3 12h18M3 18h18',
            'default' => true,
            'core'    => false,
            'status'  => 'live',
        ],
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
            'key'     => 'balance_sheet',
            'service' => 'financial_reporting',
            'label'   => 'Balance Sheet Review',
            'url'     => '/balance-sheet-review',
            'active'  => 'balance_sheet',
            'icon'    => 'M3 3h18v18H3zM3 12h18M12 3v18',
            'default' => true,
            'core'    => false,
            'status'  => 'live',
        ],
        [
            'key'     => 'income_statement',
            'service' => 'financial_reporting',
            'label'   => 'OHADA Financial Statements',
            'url'     => '/ohada-financial-statements',
            'active'  => 'income_statement',
            'icon'    => 'M4 4v16h16M8 14l3-4 3 3 4-6',
            'default' => true,
            'core'    => false,
            'status'  => 'live',
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
        [
            // Post-Retirement Benefits Calculator — SYSCOHADA-aligned
            // actuarial valuation of end-of-career indemnities owed
            // under the applicable Collective Bargaining Agreement.
            // Uses the Projected Unit Credit method (same as IAS 19)
            // to compute the present value of future indemnity
            // payments per employee and aggregate to a tenant-level
            // Provision pour engagements de retraite (account 197).
            // Output mirrors a Mercer-style actuarial valuation
            // report: roll-forward, components of net periodic
            // benefit cost, assumptions, plan provisions, participant
            // data, projected cash flows. Sits in Financial Reporting
            // because the result feeds the employee-benefits
            // provision on the balance sheet.
            'key'     => 'post_retirement_benefits',
            'service' => 'financial_reporting',
            'label'   => 'Post-Retirement Benefits',
            'url'     => '/post-retirement-benefits',
            'active'  => 'post_retirement_benefits',
            'icon'    => 'M12 14l9-5-9-5-9 5 9 5zM12 14v7M5 13.18v3.82a8 8 0 0014 0v-3.82',
            // default=true so new tenants get it auto-enabled
            // alongside the other Financial Reporting modules; a
            // companion migration enables it for existing tenants
            // that pre-date this row.
            'default' => true,
            'core'    => false,
            'status'  => 'live',
        ],
        [
            // Bank Reconciliation Tool — pairs cleared bank-statement
            // lines against journal entries on the matching cash
            // account, surfaces unreconciled stragglers, and produces
            // the period-end reconciliation statement auditors expect
            // alongside the trial balance. Coming-soon placeholder
            // until the matcher is wired up.
            'key'     => 'bank_reconciliation',
            'service' => 'financial_reporting',
            'label'   => 'Bank Reconciliation Tool',
            'url'     => '/bank-reconciliation',
            'active'  => 'bank_reconciliation',
            'icon'    => 'M3 21h18M3 10l9-7 9 7M5 10v11M19 10v11M9 14h6M9 18h6',
            'default' => false,
            'core'    => false,
            'status'  => 'coming_soon',
        ],

        // ── Financial Planning ───────────────────────────────────────────
        [
            'key'     => 'macro_economics',
            'service' => 'financial_planning',
            'label'   => 'Macro Economics',
            'url'     => '/macro-economics',
            'active'  => 'macro_economics',
            'icon'    => 'M2 12h2l2-7 4 14 3-10 4 8 3-5h4',
            'default' => true,
            'core'    => false,
            'status'  => 'live',
        ],
        [
            'key'     => 'working_capital',
            'service' => 'financial_planning',
            'label'   => 'Working Capital',
            'url'     => '/working-capital',
            'active'  => 'working_capital',
            'icon'    => 'M3 12h4l3-9 4 18 3-9h4',
            'default' => true,
            'core'    => false,
            'status'  => 'live',
        ],
        [
            'key'     => 'pl_forecast',
            'service' => 'financial_planning',
            'label'   => 'P&L Forecast',
            'url'     => '/pl-forecast',
            'active'  => 'pl_forecast',
            'icon'    => 'M4 4v16h16M8 14l3-4 3 3 4-6',
            'default' => true,
            'core'    => false,
            'status'  => 'live',
        ],
        [
            // Sidebar entry restored — the page existed at /budget-upload
            // but the v5 sidebar migration dropped its nav item. Keeps
            // the same parent service ('financial_planning') so users
            // discover it next to the forecast where they actually need
            // the budget feed. default=true means existing tenants pick
            // it up automatically (no per-tenant toggle row required).
            'key'     => 'budget_upload',
            'service' => 'financial_planning',
            'label'   => 'Budget Upload',
            'url'     => '/budget-upload',
            'active'  => 'budget_upload',
            'icon'    => 'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12',
            'default' => true,
            'core'    => false,
            'status'  => 'live',
        ],
        [
            'key'     => 'cashflow_forecast',
            'service' => 'financial_planning',
            'label'   => 'Cashflow Forecast',
            'url'     => '/cashflow-forecast',
            'active'  => 'cashflow_forecast',
            'icon'    => 'M3 10h18M3 14h18M7 6h10v12H7z',
            'default' => true,
            'core'    => false,
            'status'  => 'live',
        ],
        [
            'key'     => 'investment_analysis',
            'service' => 'financial_planning',
            'label'   => 'Investment Analysis',
            'url'     => '/investment-analysis',
            'active'  => 'investment_analysis',
            'icon'    => 'M12 2v20M5 9l7-7 7 7M5 17h14',
            'default' => true,
            'core'    => false,
            'status'  => 'live',
        ],
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

        // ── Human Resources ──────────────────────────────────────────────
        [
            // Payroll Processing — gross-to-net engine plus statutory
            // contributions (CNPS, IRPP, CAC, FNE, etc.) feeding the
            // payroll provision and payment journals on the ledger.
            // Coming-soon placeholder until the calc engine and the
            // monthly-cycle UI are built; super admin enables per-tenant
            // once the tenant's HR data feed is live.
            'key'     => 'payroll_processing',
            'service' => 'human_resources',
            'label'   => 'Payroll Processing',
            'url'     => '/payroll',
            'active'  => 'payroll',
            'icon'    => 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
            'default' => false,
            'core'    => false,
            'status'  => 'coming_soon',
        ],
    ],
];
