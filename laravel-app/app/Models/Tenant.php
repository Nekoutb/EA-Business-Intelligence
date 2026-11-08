<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tenant — root entity of multi-tenancy.
 *
 * Resolved by the ResolveTenant middleware from the {tenant} URL segment.
 * Holds branding, plan, and status; configuration values and module flags
 * live in the related TenantSetting and TenantModule rows.
 */
class Tenant extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_ACTIVE     = 'active';
    public const STATUS_TRIAL      = 'trial';
    public const STATUS_SUSPENDED  = 'suspended';

    protected $fillable = [
        'slug', 'name', 'brand', 'logo_path',
        'status', 'plan', 'trial_ends_at', 'onboarded_at',
        'industry_id', 'is_demo', 'demo_expires_at',
    ];

    protected $casts = [
        'trial_ends_at'   => 'datetime',
        'onboarded_at'    => 'datetime',
        'is_demo'         => 'boolean',
        'demo_expires_at' => 'datetime',
        'db_config'       => 'encrypted:array',
        'db_checked_at'   => 'datetime',
    ];

    /** Database credentials never leave the model in arrays / JSON. */
    protected $hidden = ['db_config'];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function settings(): HasMany
    {
        return $this->hasMany(TenantSetting::class);
    }

    public function modules(): HasMany
    {
        return $this->hasMany(TenantModule::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(TenantService::class);
    }

    public function industry()
    {
        return $this->belongsTo(Industry::class);
    }

    /** Convenience accessor for an active tenant in the request context. */
    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_TRIAL], true);
    }

    /**
     * Scope: real (non-demo) production tenants only.
     *
     * Demo sandboxes spawn via /demo/enter and pile up dozens per day
     * during prospecting. Most admin surfaces (Business Insights,
     * fleet-wide analyses, scheduled cron jobs) want to ignore them
     * entirely. Use:
     *
     *   Tenant::real()->get()
     *
     * Resilient to the case where the is_demo column doesn't exist
     * (e.g. fresh install pre-2026_05_15 migration): if the column
     * isn't there, all rows are returned (nothing to filter on).
     */
    public function scopeReal($query)
    {
        if (!\Illuminate\Support\Facades\Schema::hasColumn('tenants', 'is_demo')) {
            return $query;
        }
        return $query->where(function ($w) {
            $w->where('is_demo', false)->orWhereNull('is_demo');
        });
    }

    /** Get a setting value (decrypted automatically), or default. */
    public function setting(string $key, $default = null)
    {
        $row = $this->settings()->where('key', $key)->first();
        return $row?->value ?? $default;
    }

    /**
     * Whether a top-level service is enabled for this tenant.
     * A service must be enabled for any of its modules to be reachable.
     */
    public function serviceEnabled(?string $serviceKey): bool
    {
        if (!$serviceKey) return true; // No service tag = ungated.
        return (bool) $this->services()
            ->where('service_key', $serviceKey)
            ->where('enabled', true)
            ->exists();
    }

    /**
     * Whether a module is enabled for this tenant.
     * Note: this does NOT check the parent service flag; callers that need
     * combined gating (sidebar, middleware) should also call serviceEnabled().
     */
    public function moduleEnabled(string $key): bool
    {
        return (bool) $this->modules()
            ->where('module_key', $key)
            ->where('enabled', true)
            ->exists();
    }

    public function dashboardKpis(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(TenantDashboardKpi::class);
    }

    /**
     * Whether a Dashboard KPI card should render for this tenant.
     *
     * Resolution order:
     *   1. If a tenant_dashboard_kpis row exists for (tenant, kpi), use it.
     *   2. Otherwise fall back to the registry default in
     *      config/dashboard_kpis.php (lets new tenants inherit a sensible
     *      default without needing per-row backfill).
     *   3. If the KPI isn't in the registry at all, allow it (assume the
     *      view is rendering something we don't yet manage).
     *
     * Result is memoised per request to avoid the small but additive
     * lookup cost when a Dashboard renders 12 cards.
     */
    public function dashboardKpiEnabled(string $key): bool
    {
        // ── Tenant-level flag (existing behaviour)
        if (!isset($this->_kpiCache)) {
            $this->_kpiCache = $this->dashboardKpis()
                ->get(['kpi_key', 'enabled'])
                ->mapWithKeys(fn ($r) => [$r->kpi_key => (bool) $r->enabled])
                ->all();
        }
        $tenantOn = true;
        if (array_key_exists($key, $this->_kpiCache)) {
            $tenantOn = $this->_kpiCache[$key];
        } else {
            // No row → fall back to registry default.
            foreach (config('dashboard_kpis.kpis', []) as $entry) {
                if (($entry['key'] ?? null) === $key) {
                    $tenantOn = (bool) ($entry['default'] ?? true);
                    break;
                }
            }
        }
        if (!$tenantOn) return false;

        // ── User-level override (Phase A — multi-user). If a user is in
        // session and has an explicit override for this KPI, that wins
        // over the role default. Tenant admins / managers without an
        // explicit override see everything.
        $user = function_exists('auth') ? auth()->user() : null;
        if ($user && method_exists($user, 'canSeeKpi')) {
            return $user->canSeeKpi($key);
        }

        return true;
    }

    /** Per-request memo cache, populated on first dashboardKpiEnabled() call. */
    public ?array $_kpiCache = null;
}
