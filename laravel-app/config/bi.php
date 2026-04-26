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

    'cache_ttl' => 300, // 5 minutes
];
