<?php

namespace App\Tenancy;

use App\Models\Tenant;

/**
 * Per-request holder for the active tenant.
 *
 * Bound as a singleton in AppServiceProvider so anywhere in the request
 * lifecycle can ask `app(TenantContext::class)->tenant()` or use the
 * `tenant()` global helper. Set by the ResolveTenant middleware.
 *
 * Stays null when no tenant is in scope (e.g. /admin/* routes for the
 * super admin). Services that need a tenant should check ::has() and
 * fail loudly rather than silently using a default.
 */
class TenantContext
{
    private ?Tenant $tenant = null;

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function clear(): void
    {
        $this->tenant = null;
    }

    public function has(): bool
    {
        return $this->tenant !== null;
    }

    public function tenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->id;
    }

    public function slug(): ?string
    {
        return $this->tenant?->slug;
    }

    /** Look up a tenant setting; returns $default if absent or no context. */
    public function setting(string $key, $default = null)
    {
        if (!$this->tenant) return $default;
        return $this->tenant->setting($key, $default);
    }

    /** Whether a module is enabled for the active tenant. */
    public function moduleEnabled(string $key): bool
    {
        return $this->tenant ? $this->tenant->moduleEnabled($key) : false;
    }

    /** Whether a top-level service is enabled for the active tenant. */
    public function serviceEnabled(?string $serviceKey): bool
    {
        if (!$this->tenant) return false;
        return $this->tenant->serviceEnabled($serviceKey);
    }
}
