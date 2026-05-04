<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * Tenant-scoped user management — visible to tenant admins only
 * (gated by RequireTenantAdmin middleware on the route group).
 *
 * Each tenant manages its own users; super admins can also reach this
 * UI by visiting the same /t/{slug}/settings/users URL — they see the
 * tenant's user list, not the global one.
 */
class TenantUsersController extends Controller
{
    public function __construct(private TenantContext $context) {}

    public function index()
    {
        $tenant = $this->requireTenant();
        $users  = User::where('tenant_id', $tenant->id)
            ->orderBy('role')
            ->orderBy('name')
            ->get();

        return view('settings.users.index', [
            'users'  => $users,
            'active' => 'settings',
        ]);
    }

    public function create()
    {
        $this->requireTenant();
        return view('settings.users.create', [
            'active'   => 'settings',
            'services' => collect(config('modules.services', []))->sortBy('order')->values(),
            'modules'  => collect(config('modules.modules', []))->filter(fn ($m) => !empty($m['url']))->values(),
            'kpis'     => collect(config('dashboard_kpis.kpis', []))->values(),
            'roleOptions' => $this->roleOptions(),
            'editing'  => null,
        ]);
    }

    public function store(Request $request)
    {
        $tenant = $this->requireTenant();

        $data = $request->validate([
            'name'     => ['required', 'string', 'max:120'],
            'email'    => ['required', 'email', 'max:191',
                Rule::unique('users')->where(fn ($q) => $q->where('tenant_id', $tenant->id))],
            'password' => ['required', 'string', 'min:8', 'max:128'],
            'role'     => ['required', Rule::in([User::ROLE_TENANT_ADMIN, User::ROLE_MANAGER, User::ROLE_ANALYST, User::ROLE_VIEWER])],
            'status'   => ['required', Rule::in([User::STATUS_ACTIVE, User::STATUS_DISABLED])],
        ]);

        $permissions = $this->collectPermissions($request);

        $user = User::create([
            'tenant_id'   => $tenant->id,
            'name'        => $data['name'],
            'email'       => $data['email'],
            'password'    => Hash::make($data['password']),
            'role'        => $data['role'],
            'status'      => $data['status'],
            'permissions' => $permissions,
        ]);

        AuditLog::record([
            'tenant_id' => $tenant->id,
            'user_id'   => optional($request->user())->id,
            'action'    => 'user.created',
            'target'    => "user:{$user->id}",
            'payload'   => ['email' => $user->email, 'role' => $user->role],
        ]);

        return redirect(tenant_url('/settings/users'))->with('flash', [
            'type' => 'success',
            'msg'  => "User {$user->email} created.",
        ]);
    }

    public function edit(int $id)
    {
        $tenant = $this->requireTenant();
        $user   = User::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();

        return view('settings.users.create', [
            'active'   => 'settings',
            'services' => collect(config('modules.services', []))->sortBy('order')->values(),
            'modules'  => collect(config('modules.modules', []))->filter(fn ($m) => !empty($m['url']))->values(),
            'kpis'     => collect(config('dashboard_kpis.kpis', []))->values(),
            'roleOptions' => $this->roleOptions(),
            'editing'  => $user,
        ]);
    }

    public function update(Request $request, int $id)
    {
        $tenant = $this->requireTenant();
        $user   = User::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();

        $data = $request->validate([
            'name'     => ['required', 'string', 'max:120'],
            'email'    => ['required', 'email', 'max:191',
                Rule::unique('users')->ignore($user->id)
                    ->where(fn ($q) => $q->where('tenant_id', $tenant->id))],
            'password' => ['nullable', 'string', 'min:8', 'max:128'],
            'role'     => ['required', Rule::in([User::ROLE_TENANT_ADMIN, User::ROLE_MANAGER, User::ROLE_ANALYST, User::ROLE_VIEWER])],
            'status'   => ['required', Rule::in([User::STATUS_ACTIVE, User::STATUS_DISABLED])],
        ]);

        $user->fill([
            'name'        => $data['name'],
            'email'       => $data['email'],
            'role'        => $data['role'],
            'status'      => $data['status'],
            'permissions' => $this->collectPermissions($request),
        ]);
        if (!empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }
        $user->save();

        AuditLog::record([
            'tenant_id' => $tenant->id,
            'user_id'   => optional($request->user())->id,
            'action'    => 'user.updated',
            'target'    => "user:{$user->id}",
            'payload'   => ['email' => $user->email, 'role' => $user->role],
        ]);

        return redirect(tenant_url('/settings/users'))->with('flash', [
            'type' => 'success',
            'msg'  => "User {$user->email} updated.",
        ]);
    }

    public function destroy(Request $request, int $id)
    {
        $tenant = $this->requireTenant();
        $user   = User::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();

        // Don't let an admin delete themselves out of the tenant.
        if ($request->user() && $request->user()->id === $user->id) {
            return redirect(tenant_url('/settings/users'))->with('flash', [
                'type' => 'error',
                'msg'  => 'You cannot delete your own account.',
            ]);
        }
        // Refuse to delete the LAST tenant admin so a workspace can
        // never end up locked.
        if ($user->isTenantAdmin()) {
            $remaining = User::where('tenant_id', $tenant->id)
                ->whereIn('role', [User::ROLE_TENANT_ADMIN, User::ROLE_OWNER])
                ->where('id', '!=', $user->id)
                ->where('status', User::STATUS_ACTIVE)
                ->count();
            if ($remaining === 0) {
                return redirect(tenant_url('/settings/users'))->with('flash', [
                    'type' => 'error',
                    'msg'  => 'Cannot delete the last tenant admin. Promote another user first.',
                ]);
            }
        }

        $email = $user->email;
        $user->delete();

        AuditLog::record([
            'tenant_id' => $tenant->id,
            'user_id'   => optional($request->user())->id,
            'action'    => 'user.deleted',
            'target'    => "user:{$id}",
            'payload'   => ['email' => $email],
        ]);

        return redirect(tenant_url('/settings/users'))->with('flash', [
            'type' => 'success',
            'msg'  => "User {$email} deleted.",
        ]);
    }

    /**
     * Build the JSON `permissions` payload from the form. Only
     * keys with an explicit override (true/false) are persisted —
     * absence means "fall back to role default" so admins don't
     * have to maintain the entire grid for every user.
     */
    private function collectPermissions(Request $request): ?array
    {
        $perms = [];
        foreach (['services', 'modules', 'kpis'] as $bucket) {
            $rows = (array) $request->input("perm.$bucket", []);
            $bucketOut = [];
            foreach ($rows as $key => $val) {
                if ($val === '' || $val === null) continue;  // unset → role default
                if ($val === 'on'  || $val === '1' || $val === 1 || $val === true)  $bucketOut[$key] = true;
                if ($val === 'off' || $val === '0' || $val === 0 || $val === false) $bucketOut[$key] = false;
            }
            if (!empty($bucketOut)) $perms[$bucket] = $bucketOut;
        }
        return $perms ?: null;
    }

    private function roleOptions(): array
    {
        return [
            User::ROLE_TENANT_ADMIN => 'Tenant Admin · full access + can manage users',
            User::ROLE_MANAGER      => 'Manager · full read + can change settings',
            User::ROLE_ANALYST      => 'Analyst · read all, edit some',
            User::ROLE_VIEWER       => 'Viewer · read-only',
        ];
    }

    private function requireTenant(): \App\Models\Tenant
    {
        if (!$this->context->has()) abort(404, 'No tenant in scope.');
        return $this->context->tenant();
    }
}
