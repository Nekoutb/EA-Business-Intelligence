<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function showLogin(?string $tenant = null)
    {
        return view('auth.login', ['tenantSlug' => $tenant]);
    }

    public function login(Request $request, ?string $tenant = null)
    {
        $u = (string) $request->input('username', '');
        $p = (string) $request->input('password', '');
        $expectedUser = (string) config('bi.auth.user');
        $expectedPass = (string) config('bi.auth.password');

        $loginUrl = $tenant ? "/t/{$tenant}/login" : '/login';
        $homeUrl  = $tenant ? "/t/{$tenant}" : '/';

        if (hash_equals($expectedUser, $u) && hash_equals($expectedPass, $p)) {
            $request->session()->regenerate();
            $request->session()->put('bi_logged_in', true);
            if ($tenant) {
                $request->session()->put('bi_tenant_slug', $tenant);
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
        $request->session()->invalidate();
        return redirect($tenant ? "/t/{$tenant}/login" : '/login');
    }
}
