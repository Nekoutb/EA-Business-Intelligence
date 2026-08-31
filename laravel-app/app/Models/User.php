<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * User — belongs optionally to a Tenant.
 *
 *   - tenant_id == null  → super admin (can access /admin/* under the
 *                          'admin' guard).
 *   - tenant_id != null  → tenant user with a role:
 *                            tenant_admin | manager | analyst | viewer.
 *
 * The `role` column gives a coarse default. The `permissions` JSON
 * column lets the tenant admin override individual services / modules /
 * KPIs per user (see migration 2026_05_04_000001).
 */
class User extends Authenticatable
{
    use HasFactory, Notifiable;

    public const ROLE_SUPER_ADMIN = 'super_admin';
    public const ROLE_TENANT_ADMIN = 'tenant_admin';
    public const ROLE_MANAGER     = 'manager';
    public const ROLE_ANALYST     = 'analyst';
    public const ROLE_VIEWER      = 'viewer';

    /** Legacy alias — kept so older rows with role='owner' keep working. */
    public const ROLE_OWNER = 'owner';

    /** Roles that can manage other users in their tenant. */
    public const ADMIN_ROLES = [self::ROLE_TENANT_ADMIN, self::ROLE_OWNER, self::ROLE_SUPER_ADMIN];

    public const STATUS_ACTIVE   = 'active';
    public const STATUS_DISABLED = 'disabled';

    protected $fillable = [
        'tenant_id', 'name', 'email', 'password',
        'role', 'status', 'permissions',
        'last_login_at', 'last_login_ip',
        'must_change_password',
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at'     => 'datetime',
            'password'          => 'hashed',
            'permissions'       => 'array',
            'must_change_password' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPER_ADMIN;
    }

    public function isTenantAdmin(): bool
    {
        return in_array($this->role, [self::ROLE_TENANT_ADMIN, self::ROLE_OWNER], true);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    /** Roles that have full read across all enabled services/modules/KPIs. */
    public function hasFullAccess(): bool
    {
        return in_array($this->role, [
            self::ROLE_SUPER_ADMIN,
            self::ROLE_TENANT_ADMIN,
            self::ROLE_OWNER,
            self::ROLE_MANAGER,
            self::ROLE_ANALYST,
        ], true);
    }

    // ── Per-user permission overrides ──────────────────────────────
    //
    // Resolution: each canAccess*() returns the effective bool after:
    //   role-default → user-explicit-override (if present in $perms)
    // It does NOT also AND with the tenant-level flag — that's the
    // caller's job (see Tenant::moduleEnabled / serviceEnabled /
    // dashboardKpiEnabled — those keep being the source of truth for
    // tenant-level enablement, and we layer the user check on top).

    /** Effective access to a service for this user. */
    public function canAccessService(?string $key): bool
    {
        if ($key === null || $key === '') return true;
        if ($this->hasFullAccess() === false) {
            // viewer also gets read access by default — only explicit
            // override can take it away.
            // (No restricted role ships in Phase A; placeholder for
            // future "external" / "limited" roles.)
        }
        $perms = $this->permissionsArray();
        if (isset($perms['services'][$key])) {
            return (bool) $perms['services'][$key];
        }
        return true;  // role-default: every signed-in user sees every
                      // tenant-enabled service unless explicitly opted out.
    }

    /** Effective access to a module. */
    public function canAccessModule(string $key): bool
    {
        $perms = $this->permissionsArray();
        if (isset($perms['modules'][$key])) {
            return (bool) $perms['modules'][$key];
        }
        return true;  // role-default
    }

    /** Effective access to a dashboard KPI. */
    public function canSeeKpi(string $key): bool
    {
        $perms = $this->permissionsArray();
        if (isset($perms['kpis'][$key])) {
            return (bool) $perms['kpis'][$key];
        }
        return true;  // role-default
    }

    /** Boolean form for ON/OFF toggles in the user editor. */
    public function permOverride(string $bucket, string $key): ?bool
    {
        $perms = $this->permissionsArray();
        return isset($perms[$bucket][$key]) ? (bool) $perms[$bucket][$key] : null;
    }

    private function permissionsArray(): array
    {
        $p = $this->permissions;
        if (is_array($p)) return $p;
        if (is_string($p) && $p !== '') {
            $decoded = json_decode($p, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    /** Human label for the role pill. */
    public function roleLabel(): string
    {
        return match ($this->role) {
            self::ROLE_SUPER_ADMIN  => 'Super Admin',
            self::ROLE_TENANT_ADMIN => 'Tenant Admin',
            self::ROLE_OWNER        => 'Tenant Admin',
            self::ROLE_MANAGER      => 'Manager',
            self::ROLE_ANALYST      => 'Analyst',
            self::ROLE_VIEWER       => 'Viewer',
            default                 => ucfirst((string) $this->role),
        };
    }
}
