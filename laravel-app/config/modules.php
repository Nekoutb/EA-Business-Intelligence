<?php
/**
 * Module registry.
 *
 * Single source of truth for every business-intelligence module exposed to
 * tenants. The sidebar, the route guard, the super-admin module-toggle UI,
 * and per-tenant feature flags all read from this map.
 *
 * To add a new module: append an entry below, drop the controller in place,
 * register the route in routes/web.php, and ship.
 *
 * Each entry:
 *   - key        Stable slug stored in tenant_modules.module_key.
 *   - label      Human label shown in nav and admin UI.
 *   - url        Path users land on when clicking the nav item.
 *   - active     Slug compared against $active in the layout for highlight.
 *   - icon       SVG path data for the sidebar icon (24x24 viewBox).
 *   - default    Whether new tenants get this module enabled by default.
 *   - core       Core modules cannot be disabled (e.g. dashboard / settings).
 *   - group      Logical grouping for the admin UI ('analytics' | 'finance' | 'system').
 */
return [
    'modules' => [
        [
            'key'     => 'dashboard',
            'label'   => 'Dashboard',
            'url'     => '/',
            'active'  => 'dashboard',
            'icon'    => 'M3 12l9-9 9 9M5 10v10h14V10',
            'default' => true,
            'core'    => true,
            'group'   => 'analytics',
        ],
        [
            'key'     => 'revenue',
            'label'   => 'Revenue',
            'url'     => '/revenue',
            'active'  => 'revenue',
            'icon'    => 'M3 3v18h18M7 14l4-4 3 3 5-5',
            'default' => true,
            'core'    => false,
            'group'   => 'analytics',
        ],
        [
            'key'     => 'customer_insights',
            'label'   => 'Customer Insights',
            'url'     => '/customer-insights',
            'active'  => 'customers',
            'icon'    => 'M4 6h16M4 12h16M4 18h10M14 18l6-6-6-6',
            'default' => true,
            'core'    => false,
            'group'   => 'analytics',
        ],
        [
            'key'     => 'receivables',
            'label'   => 'Receivables',
            'url'     => '/receivables',
            'active'  => 'receivables',
            'icon'    => 'M12 2v20M5 9h14M5 15h14',
            'default' => true,
            'core'    => false,
            'group'   => 'finance',
        ],
        [
            'key'     => 'treasury',
            'label'   => 'Treasury',
            'url'     => '/treasury',
            'active'  => 'treasury',
            'icon'    => 'M12 1v22M2 12h20M4 5h4M4 19h4M16 5h4M16 19h4',
            'default' => true,
            'core'    => false,
            'group'   => 'finance',
        ],
        [
            'key'     => 'profit_loss',
            'label'   => 'Profit & Loss',
            'url'     => '/profit-loss',
            'active'  => 'pl',
            'icon'    => 'M4 4v16h16M8 14l3-4 3 3 4-6',
            'default' => true,
            'core'    => false,
            'group'   => 'finance',
        ],
        [
            'key'     => 'payables',
            'label'   => 'Payables',
            'url'     => '/payables',
            'active'  => 'payables',
            'icon'    => 'M4 6h16M4 12h16M4 18h16',
            'default' => true,
            'core'    => false,
            'group'   => 'finance',
        ],
        [
            'key'     => 'fixed_assets',
            'label'   => 'Fixed Assets',
            'url'     => '/fixed-assets',
            'active'  => 'fixed_assets',
            'icon'    => 'M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6',
            'default' => true,
            'core'    => false,
            'group'   => 'finance',
        ],
        [
            'key'     => 'ai_insights',
            'label'   => 'AI Insights',
            'url'     => null, // Rendered as a side rail, not a primary nav item.
            'active'  => null,
            'icon'    => 'M12 2L9.5 8.5 3 11l6.5 2.5L12 20l2.5-6.5L21 11l-6.5-2.5z',
            'default' => false,
            'core'    => false,
            'group'   => 'analytics',
        ],
    ],
];
