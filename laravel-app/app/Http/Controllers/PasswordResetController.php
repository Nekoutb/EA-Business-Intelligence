<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use App\Services\PasswordResetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Self-service "forgot password" for workspace users (/t/{tenant}/…) and
 * platform admins (/admin/…). The request step answers identically
 * whether or not the account exists, so the form cannot be used to
 * discover which emails have accounts.
 */
class PasswordResetController extends Controller
{
    public function showForgot(Request $request)
    {
        return view('auth.forgot-password', ['base' => $this->base($request)]);
    }

    public function sendLink(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:191']]);
        $user = $this->findUser($request, trim($data['email']));

        // Per-account cooldown on top of the per-IP throttle: one email a
        // minute at most, so the form can't be used to flood an inbox.
        if ($user && !DB::table('user_password_resets')
                ->where('user_id', $user->id)->whereNull('used_at')
                ->where('created_at', '>', now()->subMinute())->exists()) {
            $slug = (string) $request->route('tenant');
            PasswordResetService::sendLink($user, 'self', $slug !== '' ? $slug : 'admin');
        }

        return redirect($this->base($request) . '/forgot-password')->with('flash', [
            'type' => 'success',
            'msg'  => 'If an account exists for that email, a reset link is on its way. Check your inbox (and spam) — the link expires in '
                . PasswordResetService::TTL_MINUTES . ' minutes.',
        ]);
    }

    public function showReset(Request $request)
    {
        $token = (string) $request->route('token');
        return view('auth.reset-password', [
            'base'  => $this->base($request),
            'token' => $token,
            'valid' => PasswordResetService::userForToken($token) !== null,
        ]);
    }

    public function reset(Request $request)
    {
        $token = (string) $request->route('token');
        $data = $request->validate([
            'password' => ['required', 'string', 'min:10', 'max:128', 'confirmed'],
        ]);

        $user = PasswordResetService::complete($token, $data['password']);
        if (!$user) {
            return redirect($this->base($request) . '/forgot-password')->with('flash', [
                'type' => 'error',
                'msg'  => 'That reset link has expired or was already used. Request a new one below.',
            ]);
        }

        return redirect($this->base($request) . '/login')->with('flash', [
            'type' => 'success',
            'msg'  => 'Password updated. Sign in with your new password.',
        ]);
    }

    /** '/t/{slug}' for a workspace request, '/admin' for the platform console. */
    private function base(Request $request): string
    {
        $slug = (string) $request->route('tenant');
        return $slug !== '' ? '/t/' . $slug : '/admin';
    }

    /** Same account scope the matching login form accepts. */
    private function findUser(Request $request, string $email): ?User
    {
        $slug = (string) $request->route('tenant');
        $q = User::where('email', $email)->where('status', User::STATUS_ACTIVE);
        if ($slug !== '') {
            $tenant = Tenant::where('slug', $slug)->first();
            if (!$tenant) return null;
            $q->where('tenant_id', $tenant->id);
        } else {
            $q->whereNull('tenant_id')->where('role', User::ROLE_SUPER_ADMIN);
        }
        return $q->first();
    }
}
