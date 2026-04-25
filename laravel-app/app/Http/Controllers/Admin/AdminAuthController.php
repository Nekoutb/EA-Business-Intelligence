<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Authentication for the platform super-admin backend.
 *
 * Distinct from tenant auth: validates against the users table where
 * role='super_admin'. Successful login stores admin_user_id in the session
 * (separate from bi_logged_in / bi_tenant_slug), preventing cross-realm
 * privilege confusion.
 */
class AdminAuthController extends Controller
{
    public function showLogin()
    {
        return view('admin.login');
    }

    public function login(Request $request)
    {
        $email    = trim((string) $request->input('email', ''));
        $password = (string) $request->input('password', '');

        $user = User::where('email', $email)
            ->whereNull('tenant_id')
            ->where('role', User::ROLE_SUPER_ADMIN)
            ->where('status', User::STATUS_ACTIVE)
            ->first();

        if (!$user || !Hash::check($password, $user->password)) {
            return redirect('/admin/login')->with('flash', [
                'type' => 'error',
                'msg'  => 'Invalid credentials or insufficient privileges.',
            ]);
        }

        $request->session()->regenerate();
        $request->session()->put('admin_user_id', $user->id);

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        AuditLog::record('admin.login', [
            'user_id' => $user->id,
            'target'  => $user->email,
        ]);

        return redirect('/admin');
    }

    public function logout(Request $request)
    {
        $uid = $request->session()->get('admin_user_id');
        if ($uid) {
            AuditLog::record('admin.logout', ['user_id' => $uid]);
        }
        $request->session()->forget('admin_user_id');
        return redirect('/admin/login');
    }
}
