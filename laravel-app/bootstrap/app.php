<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'bi.auth'                    => \App\Http\Middleware\RequireAppLogin::class,
            'tenant.resolve'             => \App\Http\Middleware\ResolveTenant::class,
            'module'                     => \App\Http\Middleware\EnsureModuleEnabled::class,
            'admin.auth'                 => \App\Http\Middleware\RequireAdminLogin::class,
            'redirect.if.not.onboarded' => \App\Http\Middleware\RedirectIfNotOnboarded::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
