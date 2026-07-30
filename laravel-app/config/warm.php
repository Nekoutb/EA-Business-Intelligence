<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Background page-warmer (bms:warm)
    |--------------------------------------------------------------------------
    |
    | The async-data architecture: a worker sweeps every Odoo-heavy page for
    | every real tenant, recomputing each page's datasets from the CLI (where
    | Cloudflare's ~100s ceiling does not apply) and storing them in the
    | shared cache with a computed-at timestamp. Web requests then only ever
    | READ the cache — pages render in ~1–3s regardless of tenant size — and
    | the topbar shows "Data as of HH:MM" from the envelope timestamp.
    |
    | Scheduled every 3 minutes (routes/console.php). A sweep that takes
    | longer than the interval simply causes the next tick to skip (lock), so
    | freshness degrades gracefully to "as fast as the data can be computed".
    |
    | Each entry: label (metrics/log name), module (tenant module key that
    | gates the page — the worker skips pages whose module is disabled for a
    | tenant), controller (its index() is invoked exactly like a web hit),
    | query (request parameters — how Option-B hosted views are selected).
    | Order = priority: put the pages people actually open first.
    */

    'enabled' => (bool) env('BMS_WARM_ENABLED', true),

    'pages' => [
        ['label' => 'dashboard',         'module' => 'dashboard',             'controller' => \App\Http\Controllers\DashboardController::class,               'query' => ['period' => 'ytd']],
        ['label' => 'dashboard-mtd',     'module' => 'dashboard',             'controller' => \App\Http\Controllers\DashboardController::class,               'query' => ['period' => 'mtd']],
        ['label' => 'treasury',          'module' => 'treasury',              'controller' => \App\Http\Controllers\TreasuryController::class,                'query' => []],
        ['label' => 'revenue',           'module' => 'revenue',               'controller' => \App\Http\Controllers\RevenueController::class,                 'query' => []],
        ['label' => 'receivables',       'module' => 'receivables',           'controller' => \App\Http\Controllers\ReceivablesController::class,             'query' => []],
        ['label' => 'payables',          'module' => 'payables',              'controller' => \App\Http\Controllers\PayablesController::class,                'query' => []],
        ['label' => 'profit-loss',       'module' => 'profit_loss',           'controller' => \App\Http\Controllers\ProfitLossController::class,              'query' => []],
        ['label' => 'profitability',     'module' => 'profitability',         'controller' => \App\Http\Controllers\ProfitLossController::class,              'query' => ['view' => 'profitability']],
        ['label' => 'billing',           'module' => 'billing',               'controller' => \App\Http\Controllers\BillingController::class,                 'query' => []],
        ['label' => 'operations',        'module' => 'operations',            'controller' => \App\Http\Controllers\OperationsController::class,              'query' => []],
        ['label' => 'inventory',         'module' => 'inventory',             'controller' => \App\Http\Controllers\InventoryController::class,               'query' => []],
        ['label' => 'reports',           'module' => 'reports',               'controller' => \App\Http\Controllers\InventoryController::class,               'query' => ['view' => 'reports']],
        ['label' => 'customer-insights', 'module' => 'customer_insights',     'controller' => \App\Http\Controllers\CustomerInsightsController::class,        'query' => []],
        ['label' => 'product-revenue',   'module' => 'product_revenue_tsd',   'controller' => \App\Http\Controllers\ProductRevenueTsdController::class,       'query' => []],
        ['label' => 'procurement',       'module' => 'procurement',           'controller' => \App\Http\Controllers\ProcurementController::class,             'query' => []],
        ['label' => 'procurement-dash',  'module' => 'procurement_dashboard', 'controller' => \App\Http\Controllers\ProcurementController::class,             'query' => ['view' => 'dashboard']],
        ['label' => 'fixed-assets',      'module' => 'fixed_assets',          'controller' => \App\Http\Controllers\FixedAssetsController::class,             'query' => []],
        ['label' => 'working-capital',   'module' => 'working_capital',       'controller' => \App\Http\Controllers\WorkingCapitalController::class,          'query' => []],
        ['label' => 'pl-forecast',       'module' => 'pl_forecast',           'controller' => \App\Http\Controllers\PLForecastController::class,              'query' => []],
        ['label' => 'trial-balance',     'module' => 'trial_balance',         'controller' => \App\Http\Controllers\TrialBalanceController::class,            'query' => []],
        ['label' => 'gl-quality',        'module' => 'gl_quality',            'controller' => \App\Http\Controllers\GLQualityController::class,               'query' => []],
        ['label' => 'balance-sheet',     'module' => 'balance_sheet',         'controller' => \App\Http\Controllers\BalanceSheetReviewController::class,      'query' => []],
        ['label' => 'ohada-statements',  'module' => 'income_statement',      'controller' => \App\Http\Controllers\OhadaFinancialStatementsController::class,'query' => []],
        ['label' => 'cashflow-forecast', 'module' => 'cashflow_forecast',     'controller' => \App\Http\Controllers\CashflowForecastController::class,        'query' => []],
    ],
];
