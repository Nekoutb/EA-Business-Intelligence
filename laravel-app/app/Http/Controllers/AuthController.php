<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Tenant-scoped login.
 *
 * Phase A — DB-auth first, config-password fallback:
 *   1. Look up a `users` row with (tenant_id, email == input).
 *   2. If found AND status=active AND Hash::check passes → log in via
 *      Auth::login(), set the legacy session flags, done.
 *   3. Else, if the `bi.auth.user/password` env config matches → log
 *      in as a synthetic tenant_admin (creating the row if missing).
 *      This keeps the original "single shared password per workspace"
 *      flow alive during the multi-user rollout, so nobody is locked
 *      out before tenant admins have set up real users.
 *
 * Once every tenant has at least one DB user the fallback can be
 * removed in a small follow-up PR.
 */
class AuthController extends Controller
{
    public function showLogin(?string $tenant = null)
    {
        return view('auth.login', ['tenantSlug' => $tenant]);
    }

    public function login(Request $request, ?string $tenant = null)
    {
        $u = trim((string) $request->input('username', ''));
        $p = (string) $request->input('password', '');

        $loginUrl = $tenant ? "/t/{$tenant}/login" : '/login';
        $homeUrl  = $tenant ? "/t/{$tenant}" : '/';

        $tenantModel = $tenant ? Tenant::where('slug', $tenant)->first() : null;
        $authedUser  = $this->resolveUser($tenantModel, $u, $p);

        if ($authedUser) {
            // Stamp last login.
            $authedUser->forceFill([
                'last_login_at' => now(),
                'last_login_ip' => $request->ip(),
            ])->save();

            Auth::login($authedUser);
            $request->session()->regenerate();
            $request->session()->put('bi_logged_in', true);
            if ($tenant) {
                $request->session()->put('bi_tenant_slug', $tenant);
            }
            // Temporary-password holders go straight to the change form
            // (RequireAppLogin blocks every other page for them anyway).
            if ($tenant && $authedUser->must_change_password) {
                return redirect("/t/{$tenant}/account/password");
            }
            return redirect($homeUrl);
        }

        return redirect($loginUrl)->with('flash', [
            'type' => 'error',
            'msg'  => 'Invalid username or password.',
        ]);
    }

    public function logout(Request $request, ?string $tenant = null)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect($tenant ? "/t/{$tenant}/login" : '/login');
    }

    /**
     * Change-password form. Serves both the forced first-sign-in flow
     * (must_change_password set by an admin-created account) and a
     * voluntary password change from the same URL.
     */
    public function showChangePassword(Request $request, ?string $tenant = null)
    {
        return view('auth.change-password', [
            'tenantSlug' => $tenant,
            'forced'     => (bool) $request->user()?->must_change_password,
        ]);
    }

    public function changePassword(Request $request, ?string $tenant = null)
    {
        $user = $request->user();
        abort_unless($user, 403);

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password'         => ['required', 'string', 'min:10', 'max:128', 'confirmed', 'different:current_password'],
        ]);

        if (!Hash::check($data['current_password'], $user->password)) {
            return back()->withErrors(['current_password' => 'That is not your current password.']);
        }

        $user->forceFill([
            'password'             => Hash::make($data['password']),
            'must_change_password' => false,
        ])->save();

        \App\Models\AuditLog::record('user.password_changed', [
            'tenant_id' => $user->tenant_id,
            'target'    => "user:{$user->id}",
        ]);

        return redirect($tenant ? "/t/{$tenant}" : '/')->with('flash', [
            'type' => 'success',
            'msg'  => 'Password updated — welcome aboard.',
        ]);
    }

    /**
     * Try DB user first; fall back to config-shared-password.
     *
     * Returns an authenticated User or null.
     */
    private function resolveUser(?Tenant $tenant, string $u, string $p): ?User
    {
        if ($u === '' || $p === '') return null;

        // Path 1 — DB user. Email match within the tenant scope.
        $tenantId = $tenant?->id;
        $dbUser = User::query()
            ->when($tenantId !== null,  fn ($q) => $q->where('tenant_id', $tenantId))
            ->when($tenantId === null,  fn ($q) => $q->whereNull('tenant_id'))
            ->where('email', $u)
            ->where('status', User::STATUS_ACTIVE)
            ->first();

        if ($dbUser && $dbUser->password && Hash::check($p, $dbUser->password)) {
            return $dbUser;
        }

        // Path 2 — config-shared-password fallback. DISABLED by default
        // (universal-account-takeover risk: one credential logged into
        // any tenant as tenant_admin). Only honoured when explicitly
        // re-enabled AND configured with non-default, non-empty creds.
        if (config('bi.auth.fallback_enabled', false)) {
            $expectedUser = (string) config('bi.auth.user');
            $expectedPass = (string) config('bi.auth.password');

            // Refuse the shipped defaults / empties even if the flag is
            // on — these are public knowledge and must never authenticate.
            $insecureDefaults = ['', 'admin', 'changeme', 'password'];
            $configIsSafe = $expectedUser !== '' && $expectedPass !== ''
                && !in_array(strtolower($expectedPass), $insecureDefaults, true);

            if ($configIsSafe
                && hash_equals($expectedUser, $u)
                && hash_equals($expectedPass, $p)
            ) {
                return $this->getOrCreateSyntheticAdmin($tenant, $u);
            }
        }

        return null;
    }

    /**
     * Lazy-create a tenant_admin row for the config-password user the
     * first time they log in. Idempotent on subsequent logins. The
     * synthetic user gets a random hashed password (never used — they
     * authenticate via the config fallback path) so DB-only checks
     * downstream don't crash.
     */
    private function getOrCreateSyntheticAdmin(?Tenant $tenant, string $u): User
    {
        $tenantId = $tenant?->id;

        $existing = User::query()
            ->when($tenantId !== null,  fn ($q) => $q->where('tenant_id', $tenantId))
            ->when($tenantId === null,  fn ($q) => $q->whereNull('tenant_id'))
            ->where('email', $u)
            ->first();

        if ($existing) {
            // If the row exists with a different role/status, leave
            // it alone — admin may have intentionally configured it.
            return $existing;
        }

        return User::create([
            'tenant_id' => $tenantId,
            'name'      => $tenant ? ($tenant->brand ?: $tenant->name ?: 'Workspace Admin') : 'Super Admin',
            'email'     => $u,
            'password'  => Hash::make(Str::random(40)),
            'role'      => $tenantId ? User::ROLE_TENANT_ADMIN : User::ROLE_SUPER_ADMIN,
            'status'    => User::STATUS_ACTIVE,
        ]);
    }
}
