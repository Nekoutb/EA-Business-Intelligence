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
        'status', 'plan', 'trial_ends_at',
    ];

    protected $casts = [
        'trial_ends_at' => 'datetime',
    ];

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

    /** Convenience accessor for an active tenant in the request context. */
    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_TRIAL], true);
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
}
