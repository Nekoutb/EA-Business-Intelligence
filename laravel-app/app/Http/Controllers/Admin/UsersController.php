<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\WelcomeUserMail;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * Super-admin user management — cross-tenant CRUD.
 *
 * Mirrors the per-tenant TenantUsersController but operates over ALL
 * users in the platform: super admins (tenant_id=null) and tenant
 * users (tenant_id set). Filterable by tenant via ?tenant=<id>.
 *
 * Gated by the existing 'admin.auth' middleware on the route group,
 * so anyone reaching these methods is already a super admin.
 */
class UsersController extends Controller
{
    public function index(Request $request)
    {
        $tenantId = $request->query('tenant');
        $search   = trim((string) $request->query('q', ''));

        $users = User::query()
            ->with('tenant:id,slug,name,brand')
            ->when($tenantId === 'platform', fn ($q) => $q->whereNull('tenant_id'))
            ->when(is_numeric($tenantId),    fn ($q) => $q->where('tenant_id', (int) $tenantId))
            ->when($search !== '', fn ($q) => $q->where(function ($q) use ($search) {
                $q->where('email', 'like', "%{$search}%")
                  ->orWhere('name',  'like', "%{$search}%");
            }))
            ->orderByRaw('tenant_id IS NULL DESC')   // super admins first
            ->orderBy('tenant_id')
            ->orderBy('role')
            ->orderBy('name')
            ->get();

        $tenants = Tenant::orderBy('name')->get(['id', 'slug', 'name', 'brand']);

        return view('admin.users.index', [
            'users'    => $users,
            'tenants'  => $tenants,
            'tenantId' => $tenantId,
            'search'   => $search,
            'active'   => 'users',
        ]);
    }

    public function create(Request $request)
    {
        // Pre-fill tenant from `?tenant={id|platform}` so the "Add user"
        // button on /admin/tenants/{id} lands on a form that already has
        // the right tenant selected. Falls back to no preselection.
        $preselectTenantId = $request->query('tenant');
        if ($preselectTenantId !== 'platform' && !is_numeric($preselectTenantId)) {
            $preselectTenantId = null;
        }

        // One-time random password suggestion. Admin can keep it or
        // overwrite. 14 chars, alphanumeric + a couple of safe symbols
        // — passes most password meters without containing characters
        // that get mangled by terminals or email clients.
        $suggestedPassword = $this->generatePassword();

        return view('admin.users.edit', [
            'editing'           => null,
            'tenants'           => Tenant::orderBy('name')->get(['id', 'slug', 'name', 'brand']),
            'services'          => collect(config('modules.services', []))->sortBy('order')->values(),
            'modules'           => collect(config('modules.modules', []))->filter(fn ($m) => !empty($m['url']))->values(),
            'kpis'              => collect(config('dashboard_kpis.kpis', []))->values(),
            'roleOptions'       => $this->roleOptions(),
            'preselectTenantId' => $preselectTenantId,
            'suggestedPassword' => $suggestedPassword,
            'active'            => 'users',
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validateUser($request);

        $plainPassword = $data['password'];
        $sendWelcome   = (bool) $request->input('send_welcome_email', false);

        $user = User::create([
            'tenant_id'   => $this->normaliseTenantId($data['tenant_id']),
            'name'        => $data['name'],
            'email'       => $data['email'],
            'password'    => Hash::make($plainPassword),
            'role'        => $data['role'],
            'status'      => $data['status'],
            'permissions' => $this->collectPermissions($request),
        ]);

        AuditLog::record('admin.user.created', [
            'tenant_id' => $user->tenant_id,
            'target'    => "user:{$user->id}",
            'payload'   => [
                'email' => $user->email,
                'role'  => $user->role,
                'welcome_email_requested' => $sendWelcome,
            ],
        ]);

        // Best-effort welcome email. If the mail driver is `log` (the
        // default in this codebase), the rendered email lands in
        // storage/logs/laravel.log so the admin can copy the credentials
        // from there. Real SMTP / Mailgun delivery requires
        // MAIL_MAILER=smtp + SMTP credentials in .env. Caught so a mail
        // failure never breaks user creation.
        $mailStatus = ['attempted' => false, 'error' => null, 'driver' => config('mail.default')];
        if ($sendWelcome) {
            $mailStatus['attempted'] = true;
            try {
                Mail::to($user->email)->send(new WelcomeUserMail($user, $plainPassword));
                AuditLog::record('admin.user.welcome_email_sent', [
                    'tenant_id' => $user->tenant_id,
                    'target'    => "user:{$user->id}",
                    'payload'   => ['driver' => $mailStatus['driver']],
                ]);
            } catch (\Throwable $e) {
                $mailStatus['error'] = $e->getMessage();
                Log::warning('WelcomeUserMail dispatch failed', [
                    'user_id' => $user->id,
                    'error'   => $e->getMessage(),
                ]);
                AuditLog::record('admin.user.welcome_email_failed', [
                    'tenant_id' => $user->tenant_id,
                    'target'    => "user:{$user->id}",
                    'payload'   => ['error' => $e->getMessage()],
                ]);
            }
        }

        // Stash the plaintext password + login URL in the flash bag so
        // the index page can show it ONCE in a one-time copy-able banner.
        // Keys are explicit so it's clear what's transient and not part
        // of the persistent flash message.
        $loginUrl = $user->tenant_id
            ? url('/t/' . optional($user->tenant)->slug . '/login')
            : url('/admin/login');

        return redirect('/admin/users')
            ->with('flash', [
                'type' => 'success',
                'msg'  => "User {$user->email} created.",
            ])
            ->with('new_user_credentials', [
                'name'      => $user->name,
                'email'     => $user->email,
                'password'  => $plainPassword,
                'login_url' => $loginUrl,
                'mail'      => $mailStatus,
            ]);
    }

    public function edit(int $id)
    {
        $user = User::findOrFail($id);

        return view('admin.users.edit', [
            'editing'  => $user,
            'tenants'  => Tenant::orderBy('name')->get(['id', 'slug', 'name', 'brand']),
            'services' => collect(config('modules.services', []))->sortBy('order')->values(),
            'modules'  => collect(config('modules.modules', []))->filter(fn ($m) => !empty($m['url']))->values(),
            'kpis'     => collect(config('dashboard_kpis.kpis', []))->values(),
            'roleOptions' => $this->roleOptions(),
            'active'   => 'users',
        ]);
    }

    public function update(Request $request, int $id)
    {
        $user = User::findOrFail($id);
        $data = $this->validateUser($request, $user);

        $user->fill([
            'tenant_id'   => $this->normaliseTenantId($data['tenant_id']),
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

        AuditLog::record('admin.user.updated', [
            'tenant_id' => $user->tenant_id,
            'target'    => "user:{$user->id}",
            'payload'   => ['email' => $user->email, 'role' => $user->role],
        ]);

        return redirect('/admin/users')->with('flash', [
            'type' => 'success',
            'msg'  => "User {$user->email} updated.",
        ]);
    }

    public function destroy(Request $request, int $id)
    {
        $user = User::findOrFail($id);

        // Refuse to delete the last active super admin so the platform
        // never ends up locked.
        if ($user->isSuperAdmin()) {
            $remaining = User::whereNull('tenant_id')
                ->where('role', User::ROLE_SUPER_ADMIN)
                ->where('status', User::STATUS_ACTIVE)
                ->where('id', '!=', $user->id)
                ->count();
            if ($remaining === 0) {
                return redirect('/admin/users')->with('flash', [
                    'type' => 'error',
                    'msg'  => 'Cannot delete the last super admin. Promote another user first.',
                ]);
            }
        }
        // Same guard for tenant_admins on a per-tenant basis.
        if ($user->isTenantAdmin() && $user->tenant_id) {
            $remaining = User::where('tenant_id', $user->tenant_id)
                ->whereIn('role', [User::ROLE_TENANT_ADMIN, User::ROLE_OWNER])
                ->where('status', User::STATUS_ACTIVE)
                ->where('id', '!=', $user->id)
                ->count();
            if ($remaining === 0) {
                return redirect('/admin/users')->with('flash', [
                    'type' => 'error',
                    'msg'  => "Cannot delete the last tenant admin of this workspace. Promote another user in that tenant first.",
                ]);
            }
        }

        $email = $user->email;
        $tid   = $user->tenant_id;
        $user->delete();

        AuditLog::record('admin.user.deleted', [
            'tenant_id' => $tid,
            'target'    => "user:{$id}",
            'payload'   => ['email' => $email],
        ]);

        return redirect('/admin/users')->with('flash', [
            'type' => 'success',
            'msg'  => "User {$email} deleted.",
        ]);
    }

    // ──────────────────────────────────────────────────────────────

    private function validateUser(Request $request, ?User $existing = null): array
    {
        return $request->validate([
            'tenant_id' => ['required'],   // allows 'platform' OR a numeric tenant id
            'name'      => ['required', 'string', 'max:120'],
            'email'     => ['required', 'email', 'max:191',
                Rule::unique('users')
                    ->ignore($existing?->id)
                    ->where(function ($q) use ($request) {
                        $tid = $this->normaliseTenantId($request->input('tenant_id'));
                        return $tid === null ? $q->whereNull('tenant_id') : $q->where('tenant_id', $tid);
                    })],
            'password'  => $existing
                ? ['nullable', 'string', 'min:8', 'max:128']
                : ['required', 'string', 'min:8', 'max:128'],
            'role'      => ['required', Rule::in([
                User::ROLE_SUPER_ADMIN, User::ROLE_TENANT_ADMIN, User::ROLE_MANAGER,
                User::ROLE_ANALYST, User::ROLE_VIEWER,
            ])],
            'status'    => ['required', Rule::in([User::STATUS_ACTIVE, User::STATUS_DISABLED])],
        ]);
    }

    /** Form posts 'platform' or a numeric tenant id; turn that into null|int. */
    private function normaliseTenantId(mixed $val): ?int
    {
        if ($val === 'platform' || $val === null || $val === '') return null;
        return (int) $val;
    }

    private function collectPermissions(Request $request): ?array
    {
        $perms = [];
        foreach (['services', 'modules', 'kpis'] as $bucket) {
            $rows = (array) $request->input("perm.$bucket", []);
            $bucketOut = [];
            foreach ($rows as $key => $val) {
                if ($val === '' || $val === null) continue;
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
            User::ROLE_SUPER_ADMIN  => 'Super Admin · platform-level (no tenant)',
            User::ROLE_TENANT_ADMIN => 'Tenant Admin · full access + can manage users',
            User::ROLE_MANAGER      => 'Manager · full read + can change settings',
            User::ROLE_ANALYST      => 'Analyst · read all, edit some',
            User::ROLE_VIEWER       => 'Viewer · read-only',
        ];
    }

    /**
     * Generate a 14-char random password from a safe alphabet.
     * Avoids 0/O and 1/l/I to keep credentials readable when copied
     * out of an email or console; includes 2 punctuation marks so
     * it passes most "complexity" requirements without being a pain
     * to type. The password is shown to the admin once (after store)
     * and never persisted in plaintext — only the hash is saved.
     */
    private function generatePassword(int $length = 14): string
    {
        $alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789@#';
        $max      = strlen($alphabet) - 1;
        $out      = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
    }
}
