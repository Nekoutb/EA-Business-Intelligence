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
        // Graceful CSRF / session-expiry handling.
        //
        // Default Laravel 11 behaviour on a TokenMismatchException is to
        // render the bare-bones "419 | PAGE EXPIRED" page, which is
        // confusing for end users and looks unfinished. The most common
        // way to hit this is by leaving a form open across a deploy or
        // session-cookie rotation, then submitting it.
        //
        // We turn it into a clean redirect back to the form (refreshing
        // the CSRF token in the process) with a flash message, so the
        // user can simply re-enter and retry. AJAX/JSON callers still
        // receive a normal 419 response.
        $exceptions->render(function (
            \Illuminate\Session\TokenMismatchException $e,
            \Illuminate\Http\Request $request
        ) {
            if ($request->expectsJson()) {
                return null;
            }

            $request->session()->regenerateToken();

            return back()
                ->withInput($request->except(['password', 'password_confirmation', '_token']))
                ->with('flash', [
                    'type' => 'error',
                    'msg'  => 'Your session expired (the page was open too long, or another tab logged out). Please try again.',
                ]);
        });
    })->create();
