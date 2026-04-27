<?php

namespace App\Providers;

use App\Tenancy\TenantContext;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One TenantContext per request; populated by ResolveTenant middleware.
        $this->app->singleton(TenantContext::class, fn () => new TenantContext());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Safety net for slow Odoo-backed pages: give web requests up to
        // 120s so a single slow upstream call surfaces as a clean
        // OdooException instead of a Symfony FatalError. CLI / queue
        // workers keep their own limits (typically 0 = unlimited).
        if (PHP_SAPI !== 'cli' && function_exists('set_time_limit')) {
            @set_time_limit(120);
        }
    }
}

