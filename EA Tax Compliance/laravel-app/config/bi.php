<?php
/**
 * EA Compliance — app-specific config.
 * Accessed via config('bi.odoo.url') etc.
 */
return [
    'brand' => env('APP_BRAND', 'EA Compliance'),
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
