<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for /admin/* routes. Only users with role=super_admin and an active
 * admin session pass through. The session key is intentionally separate
 * from the tenant-side bi_logged_in flag so a super admin can be logged in
 * to /admin and to a tenant workspace simultaneously without collisions.
 */
class RequireAdminLogin
{
    public function handle(Request $request, Closure $next): Response
    {
        $userId = $request->session()->get('admin_user_id');
        if (!$userId) {
            return redirect('/admin/login');
        }
        $user = User::find($userId);
        if (!$user || !$user->isSuperAdmin() || !$user->isActive()) {
            $request->session()->forget('admin_user_id');
            return redirect('/admin/login');
        }
        // Make the admin user available downstream as request->attributes 'admin_user'.
        $request->attributes->set('admin_user', $user);
        return $next($request);
    }
}
