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
        // ── SECURITY ──────────────────────────────────────────────
        // The shared-password fallback (one credential that logs into
        // ANY tenant as tenant_admin) is DISABLED by default. It was a
        // rollout convenience while tenants set up real DB users; now
        // that real users exist it's a universal-account-takeover risk.
        //
        // Path 1 (real DB users) is unaffected — everyone with a real
        // account keeps logging in normally. This only governs the
        // legacy Path 2 fallback.
        //
        // To re-enable it (NOT recommended), you must BOTH:
        //   1. set BI_AUTH_FALLBACK_ENABLED=true, and
        //   2. set APP_USER + APP_PASSWORD to non-default, non-empty
        //      values (the shipped 'admin'/'changeme' are rejected even
        //      when the flag is on — see AuthController::resolveUser).
        'fallback_enabled' => (bool) env('BI_AUTH_FALLBACK_ENABLED', false),
        // No insecure defaults — empty means "no fallback credential".
        'user'     => env('APP_USER', ''),
        'password' => env('APP_PASSWORD', ''),
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

    /**
     * How long a computed dataset may be SERVED, not how often it refreshes.
     * The bms:warm background worker recomputes every page roughly every
     * 3 minutes (see config/warm.php + routes/console.php); this TTL is only
     * the stale-serve ceiling if that worker stalls. Beyond it, a web request
     * falls back to computing inline — the pre-worker behaviour.
     */
    'cache_ttl' => (int) env('BI_CACHE_TTL', 1800), // 30 minutes

    /**
     * Global cache schema version. Bump this string (v1 → v2 → …) whenever
     * the SHAPE of any ea_cached() payload changes, so a deploy invalidates
     * every stale-shaped entry across the whole app at once. Replaces the
     * old per-controller manual version strings (e.g. monthly_tax_return_v6).
     */
    'cache_schema_version' => env('BI_CACHE_SCHEMA_VERSION', 'v1'),
];
