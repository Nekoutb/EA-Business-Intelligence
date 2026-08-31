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
        // Safety net for slow / memory-heavy Odoo-backed pages.
        //
        // - max_execution_time: bumped to 120s so a single slow upstream
        //   call surfaces as a clean OdooException instead of a Symfony
        //   FatalError page.
        // - memory_limit: bumped to 512M because GL Quality (and other
        //   audit pages) fetch tens of thousands of move lines for
        //   description/manual-entry/timing checks. Apache mod_php
        //   defaults to 128M which OOM-kills mid-request — and the OOM
        //   happens too late for Laravel's exception handler to catch,
        //   so the user sees a generic Apache 500 with no clue what
        //   went wrong. 512M is comfortable headroom for the whole
        //   suite without being so high that a runaway leak hangs the
        //   box.
        //
        // CLI / queue workers keep their own (usually unlimited) limits.
        if (PHP_SAPI !== 'cli') {
            if (function_exists('set_time_limit')) {
                @set_time_limit(120);
            }
            @ini_set('memory_limit', '512M');
        }

        // MailerSend HTTP API transport — active when MAIL_MAILER=mailersend.
        \Illuminate\Support\Facades\Mail::extend('mailersend', fn () => new \App\Mail\Transport\MailerSendApiTransport(
            (string) config('services.mailersend.api_key', '')
        ));
    }
}

