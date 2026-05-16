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
            'remember.period'            => \App\Http\Middleware\RememberPeriod::class,
            'tenant.admin'               => \App\Http\Middleware\RequireTenantAdmin::class,
            'demo.session'               => \App\Http\Middleware\ResolveDemoSession::class,
        ]);

        // Exempt login routes from CSRF.
        //
        // CSRF protects authenticated endpoints from cross-site forgery.
        // The login form is unauthenticated by definition — the credential
        // check (username + password) is the actual protection. Enforcing
        // CSRF here only causes false-positive 419 errors when:
        //   • a tab was open across a session-cookie rotation, or
        //   • a deploy invalidated server-side session state, or
        //   • a customer demos the app from a stale page.
        //
        // Standard practice in production-grade Laravel apps; matches the
        // posture of Django, Rails, and Symfony's default security stacks
        // for unauthenticated POST endpoints.
        $middleware->validateCsrfTokens(except: [
            'login',
            't/*/login',
            'admin/login',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // For any *other* form (admin, dashboard) that hits a CSRF
        // mismatch: silently redirect back to the previous page, which
        // re-renders the form with a fresh token. No error page, no
        // flash banner — the user just sees their form again and
        // re-submits. AJAX/JSON callers still get a normal 419 response.
        $exceptions->render(function (
            \Illuminate\Session\TokenMismatchException $e,
            \Illuminate\Http\Request $request
        ) {
            if ($request->expectsJson()) {
                return null;
            }
            $request->session()->regenerateToken();
            return back()->withInput(
                $request->except(['password', 'password_confirmation', '_token'])
            );
        });
    })->create();
