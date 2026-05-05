<?php
/**
 * Elite Advisors Management Suite (EAMS) — app-specific config.
 * Accessed via config('bi.odoo.url') etc.
 *
 * The 'bi.*' key is kept for backward-compat with existing helpers; the
 * brand metadata now reflects the parent suite, while individual services
 * (Business Intelligence, Tax Compliance, etc.) are described in
 * config/modules.php under the 'services' array.
 */
return [
    'suite_name' => env('APP_SUITE_NAME', 'Elite Advisors Business Management Suite'),
    'brand' => env('APP_BRAND', 'Elite Advisors Business Management Suite'),
    'logo'  => env('APP_LOGO', ''),

    'auth' => [
        'user'     => env('APP_USER', 'admin'),
        'password' => env('APP_PASSWORD', 'changeme'),
    ],

    'odoo' => [
        'url'      => env('ODOO_URL', ''),
        'db'       => env('ODOO_DB', ''),
        'username' => env('ODOO_USERNAME', ''),
        'api_key'  => env('ODOO_API_KEY', ''),
        'protocol' => env('ODOO_PROTOCOL', 'json2'),
    ],

    'revenue_source' => env('REVENUE_SOURCE', 'invoice'),

    'ai' => [
        'provider' => env('AI_PROVIDER', 'claude'),
        'model'    => env('AI_MODEL', 'claude-sonnet-4-20250514'),
        'api_key'  => env('AI_API_KEY', ''),
    ],

    /**
     * Daily Business Insights — super-admin section on /admin.
     *
     * Cheapest tier by default (Haiku ~$1/MTok input, $5/MTok output).
     * For ~30 tenants × ~3K input + ~2K output tokens per day, that's
     * ~$0.10 fleet-wide per day at Haiku — and prompt caching cuts the
     * input side by ~80% across a fleet pass. Swap to Sonnet / Opus
     * via AI_BUSINESS_INSIGHTS_MODEL when the cost head-room allows.
     */
    'business_insights' => [
        'model' => env('AI_BUSINESS_INSIGHTS_MODEL', 'claude-haiku-4-5'),
    ],

    /**
     * GL-backed DSO calculation (ReceivablesService).
     *
     *   DSO = (class-{ar_prefix} balance today
     *          ÷ class-{sales_prefix} credit over trailing window_days)
     *         × window_days
     *
     * Defaults match OHADA / SYSCOHADA practice — class 41 for clients,
     * class 70 for ventes, 90-day rolling window. Override via .env if
     * the firm uses a different chart prefix or wants 60 / 120 days.
     */
    'dso_window_days'  => (int) env('DSO_WINDOW_DAYS', 90),
    'dso_ar_prefix'    => env('DSO_AR_PREFIX', '41'),
    'dso_sales_prefix' => env('DSO_SALES_PREFIX', '70'),

    'cache_ttl' => 300, // 5 minutes
];
