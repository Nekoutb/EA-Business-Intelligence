<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\RevenueController;
use App\Http\Controllers\ReceivablesController;
use App\Http\Controllers\ProfitLossController;
use App\Http\Controllers\PayablesController;
use App\Http\Controllers\TreasuryController;
use App\Http\Controllers\FixedAssetsController;
use App\Http\Controllers\CustomerInsightsController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

/* ── Public ──────────────────────────────────────────────────────────── */
Route::get('/login',  [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::get('/logout', [AuthController::class, 'logout']);

/* ── Protected ───────────────────────────────────────────────────────── */
Route::middleware('bi.auth')->group(function () {
    Route::get('/',              [DashboardController::class,  'index']);
    Route::post('/sync',         [DashboardController::class,  'sync']);

    Route::get('/revenue',       [RevenueController::class,    'index']);
    Route::get('/customer-insights', [CustomerInsightsController::class, 'index']);
    Route::get('/receivables',   [ReceivablesController::class,'index']);
    Route::get('/treasury',      [TreasuryController::class,'index']);
    Route::get('/profit-loss',   [ProfitLossController::class, 'index']);
    Route::get('/payables',      [PayablesController::class,   'index']);
    Route::get('/fixed-assets',  [FixedAssetsController::class,'index']);

    Route::get('/settings',      [SettingsController::class,   'show']);
    Route::post('/settings',     [SettingsController::class,   'save']);
});
