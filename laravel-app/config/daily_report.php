<?php

return [
    /*
    |--------------------------------------------------------------------------
    | MailerSend API key
    |--------------------------------------------------------------------------
    |
    | Read HERE, not via env() at call time: once `php artisan config:cache`
    | runs (the deploy workflow does), env() returns null outside config files
    | and the senders silently fall back to SMTP — losing the per-message id
    | that makes delivery traceable.
    */
    'mailersend_api_key' => env('MAILERSEND_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Daily activity report — recipients per tenant
    |--------------------------------------------------------------------------
    |
    | The 08:00 daily digest (reports:send-daily) is sent only to tenants that
    | have the hidden `daily_report` module enabled AND resolve to at least one
    | recipient. Recipient resolution order in the command is:
    |
    |   1. --to override (comma-separated, for manual test sends)
    |   2. this config, keyed by tenant slug
    |   3. the tenant's active admin/owner users (fallback)
    |
    | Keep this list to the specific addresses the client asked for. To roll the
    | report out to another client, enable its `daily_report` module and add its
    | slug here.
    */
    'recipients' => [
        // Kissywears SARL — per client request (both addresses confirmed).
        'kwr' => [
            'Kissywears1@gmail.com',
            'contact@cm-ea.com',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Receivables Top-Offender Scorecard — recipients per tenant
    |--------------------------------------------------------------------------
    |
    | Sent every 3 days by reports:send-offenders. Separate list from the
    | daily digest above: this one goes to the people who chase collections.
    | A tenant with no entry here is skipped.
    */
    'offenders' => [
        'kwr' => [
            'Contact@cm-ea.com',
            'cmahouvi@kissywears.co',
        ],
    ],
];
