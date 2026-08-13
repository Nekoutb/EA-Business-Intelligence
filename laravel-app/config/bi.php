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
     * Reconciliation buckets: batched (a handful of grouped Odoo calls for
     * the whole window) vs the original per-month loop (~13 calls per month).
     * Kept as a switch so the old path is one config change away if a figure
     * ever looks wrong. Verified equal by the reconcheck diagnostic.
     */
    'recon_batched' => (bool) env('BI_RECON_BATCHED', true),

    /**
     * Revenue: derive totals, the daily series and per-customer aggregates
     * from grouped queries, and fetch only discounted lines, instead of
     * downloading every document and every line for two years. Same figures;
     * set false to restore the row fetch. Verified by the revcheck diagnostic.
     */
    'revenue_batched' => (bool) env('BI_REVENUE_BATCHED', true),

    /**
     * P3 — read the open-AR set from the local warehouse instead of Odoo.
     *
     * ON since 13 Aug 2026: the p3check dual-run gate reported 0.00 variance
     * on both tenants across headline figures, the whole 8-month evolution
     * series and the 10-customer offender scorecard. Set false to fall back
     * to reading account.move over the network; the service also falls back
     * on its own for any tenant that has not been synced yet.
     *
     * Note this only re-points the invoice FETCH. DSO's denominator still
     * comes from Odoo via GLAccountService, and that — not the invoice set —
     * is now the floor on this page (~0.8s regardless of AR size). It clears
     * once GL move lines land in the warehouse.
     */
    'warehouse_receivables' => (bool) env('BI_WAREHOUSE_RECEIVABLES', true),

    /**
     * P3 — read GL balances (debit/credit by account-code prefix) from the
     * warehouse instead of Odoo. This is what the DSO denominator, the
     * Treasury liquidity snapshot and the short-term-debt figures all go
     * through, so it is the fixed per-page cost that re-pointing the
     * invoice fetch alone left behind.
     *
     * Default OFF until glcheck reports 0.00 for a tenant. The gate is
     * also what proves the GL backfill actually completed: a half-synced
     * tenant cannot tie out, so it cannot pass.
     */
    'warehouse_gl' => (bool) env('BI_WAREHOUSE_GL', false),

    /**
     * How long a computed dataset may be SERVED, not how often it refreshes.
     * The bms:warm worker recomputes pages as fast as it can sweep; this TTL
     * is only the stale-serve ceiling if that worker stalls. It must exceed
     * the worst sweep duration — 30 min proved too short (entries expired
     * before the sweep cycled back and visitors paid inline rebuilds again,
     * observed 30 Jul). Freshness is communicated by the "Data as of" stamp,
     * not by expiring data.
     */
    'cache_ttl' => (int) env('BI_CACHE_TTL', 21600), // 6 hours

    'cache_schema_version' => env('BI_CACHE_SCHEMA_VERSION', 'v1'),
];
