<?php
/**
 * Global helpers for the BI app.  Loaded via composer "files" autoload.
 *
 * We intentionally keep these as free functions (not classes) because they
 * preserve API-compatibility with the old plain-PHP app, which made all
 * Services call `Config::get('X')`, `ea_resolve_period(...)`, and
 * `ea_cached(...)`.  The migration to Laravel needs zero changes to those
 * Service classes.
 */

if (!function_exists('config_env')) {
    /**
     * Backwards-compatible shim for the old Config::get() calls embedded in
     * the ported Odoo services.
     *
     * Priority order (multi-tenant aware):
     *   1. AI_* keys — global_settings (super-admin managed) wins always.
     *      Centralising the LLM provider/key means the operator pays one bill,
     *      rolls model upgrades atomically, and tenants can never override.
     *   2. Active tenant's tenant_settings row (Odoo creds, brand, budgets…).
     *   3. Session overrides (Settings page live tweaks; legacy path).
     *   4. Laravel config('bi.*') — seeded from .env at boot.
     *   5. Raw env() fallback.
     *   6. Supplied default.
     */
    function config_env(string $key, $default = null)
    {
        static $map = [
            'ODOO_URL'       => 'bi.odoo.url',
            'ODOO_DB'        => 'bi.odoo.db',
            'ODOO_USERNAME'  => 'bi.odoo.username',
            'ODOO_API_KEY'   => 'bi.odoo.api_key',
            'ODOO_PROTOCOL'  => 'bi.odoo.protocol',
            'REVENUE_SOURCE' => 'bi.revenue_source',
            'APP_BRAND'      => 'bi.brand',
            'APP_LOGO'       => 'bi.logo',
            'AI_PROVIDER'    => 'bi.ai.provider',
            'AI_MODEL'       => 'bi.ai.model',
            'AI_API_KEY'     => 'bi.ai.api_key',
            'CURRENCY'       => 'bi.currency',
        ];

        static $globalKeys = ['AI_PROVIDER', 'AI_MODEL', 'AI_API_KEY'];

        // 1. AI configuration is platform-global and managed by super admins.
        //    We resolve it from the global_settings table before anything else.
        if (in_array($key, $globalKeys, true)) {
            try {
                $v = \App\Models\GlobalSetting::lookup($key);
                if ($v !== null && $v !== '') {
                    return $v;
                }
            } catch (\Throwable $e) {
                // Table may not exist yet during fresh install/migration; fall through.
            }
        }

        // 2. Tenant settings take precedence whenever a tenant is in scope.
        if (function_exists('app') && app()->bound(\App\Tenancy\TenantContext::class)) {
            $ctx = app(\App\Tenancy\TenantContext::class);
            if ($ctx->has()) {
                $v = $ctx->setting($key);
                if ($v !== null && $v !== '') {
                    return $v;
                }
            }
        }

        // 3. Session overrides (legacy path).
        $overrides = session('bi_overrides', []);
        if (array_key_exists($key, $overrides) && $overrides[$key] !== '' && $overrides[$key] !== null) {
            return $overrides[$key];
        }

        if (isset($map[$key])) {
            $v = config($map[$key]);
            if ($v !== null && $v !== '') {
                return $v;
            }
        }

        $e = env($key);
        return ($e !== null && $e !== '') ? $e : $default;
    }
}

if (!function_exists('tenant')) {
    /**
     * Convenience accessor for the active tenant model (or null).
     */
    function tenant(): ?\App\Models\Tenant
    {
        if (!function_exists('app') || !app()->bound(\App\Tenancy\TenantContext::class)) {
            return null;
        }
        return app(\App\Tenancy\TenantContext::class)->tenant();
    }
}

if (!function_exists('tenant_url')) {
    /**
     * Build a URL scoped to the current tenant (path-prefix scheme /t/{slug}).
     * Returns the input unchanged when no tenant is in scope.
     */
    function tenant_url(string $path): string
    {
        $t = tenant();
        if (!$t) return $path;
        $clean = '/' . ltrim($path, '/');
        if ($clean === '/') {
            return '/t/' . $t->slug;
        }
        return '/t/' . $t->slug . $clean;
    }
}

if (!function_exists('ea_resolve_period')) {
    /**
     * Resolve a reporting window from a preset.
     *
     * @return array{0:\DateTimeImmutable,1:\DateTimeImmutable,2:string,3:string}
     */
    function ea_resolve_period(\DateTimeImmutable $today, array $period): array
    {
        $type = strtolower((string) ($period['type'] ?? 'ytd'));
        switch ($type) {
            case 'mtd':
                $from = $today->modify('first day of this month')->setTime(0, 0, 0);
                $to   = $today->setTime(23, 59, 59);
                return [$from, $to, 'Month-to-date (' . $from->format('F Y') . ')', 'mtd'];
            case 'month':
                $y     = (int) ($period['year']  ?? (int) $today->format('Y'));
                $m     = max(1, min(12, (int) ($period['month'] ?? (int) $today->format('m'))));
                $start = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $y, $m));
                $end   = $start->modify('last day of this month')->setTime(23, 59, 59);
                if ($end > $today) $end = $today->setTime(23, 59, 59);
                return [$start, $end, $start->format('F Y'), 'month'];
            case 'yoy':
            case 'ytd':
            default:
                // Year is selectable. For the current year the window
                // closes at "today"; for past years it closes at Dec 31.
                $todayYear = (int) $today->format('Y');
                $y         = (int) ($period['year'] ?? $todayYear);
                $from      = new \DateTimeImmutable(sprintf('%04d-01-01 00:00:00', $y));
                if ($y >= $todayYear) {
                    $to    = $today->setTime(23, 59, 59);
                    $label = 'Year-to-date ' . $y;
                } else {
                    $to    = new \DateTimeImmutable(sprintf('%04d-12-31 23:59:59', $y));
                    $label = 'Year ' . $y;
                }
                return [$from, $to, $label, 'ytd'];
        }
    }
}

if (!function_exists('ea_cache_prefix')) {
    /**
     * Build the cache-key prefix for the active tenant.
     *
     * Shape: ea:{schema}:{scope}:cv{N}:
     *   - schema : global schema version from config (bi.cache_schema_version).
     *              Bump it in code whenever a cached payload's SHAPE changes
     *              so a deploy automatically invalidates every stale-shaped
     *              entry across the whole app — no per-controller version
     *              strings needed.
     *   - scope  : t{tenantId} (or 'g' when no tenant in context). Guarantees
     *              one workspace's cache can never bleed into another's.
     *   - cv{N}  : per-(tenant|global) cache VERSION counter, stored in the
     *              cache itself. ea_cache_clear() increments it, which makes
     *              every prior key for that scope unreachable in O(1) without
     *              needing cache tags (tags aren't supported on the database/
     *              file drivers this app uses).
     */
    function ea_cache_prefix(): string
    {
        $schema = (string) config('bi.cache_schema_version', 'v1');
        $tid    = optional(tenant())->id;
        $scope  = $tid ? 't' . $tid : 'g';
        $cv     = (int) \Illuminate\Support\Facades\Cache::get("ea:cachever:{$scope}", 1);
        return "ea:{$schema}:{$scope}:cv{$cv}:";
    }
}

if (!function_exists('ea_cached')) {
    /**
     * Tenant-scoped cache used by services, backed by the SHARED cache store
     * (database/redis), not the session.
     *
     * Why shared, not session (the old behaviour):
     *   - Two users on the same tenant now share one cached result instead of
     *     each re-paying the full Odoo round-trip cost.
     *   - Large analysis payloads no longer bloat the per-request session blob
     *     (which was read+written on EVERY request, slowing the whole app).
     *   - The schema-version prefix busts stale-shaped payloads automatically
     *     on deploy, fixing the "blank section for 5 min after deploy" class
     *     of bug.
     *
     * Keys are namespaced by tenant id (see ea_cache_prefix) so caches never
     * bleed across workspaces. First caller within TTL wins; the rest reuse.
     */
    function ea_cached(string $key, callable $producer)
    {
        $ttl  = (int) config('bi.cache_ttl', 300);
        $full = ea_cache_prefix() . $key;

        // Keep the per-session "last synced" label working for the UI.
        session(['bi_last_sync' => time()]);

        return \Illuminate\Support\Facades\Cache::remember($full, $ttl, $producer);
    }
}

if (!function_exists('ea_cache_clear')) {
    /**
     * Invalidate cache for the active tenant (or the global scope when no
     * tenant is in context) by bumping that scope's version counter. All
     * prior keys become unreachable immediately and expire on their own TTL.
     * O(1), no tags required.
     */
    function ea_cache_clear(): void
    {
        $tid    = optional(tenant())->id;
        $scope  = $tid ? 't' . $tid : 'g';
        $cvKey  = "ea:cachever:{$scope}";
        $cur    = (int) \Illuminate\Support\Facades\Cache::get($cvKey, 1);
        // forever() so the counter itself never expires and reset-collides
        // with still-live keys from a prior version.
        \Illuminate\Support\Facades\Cache::forever($cvKey, $cur + 1);
        session(['bi_last_sync' => time()]);
    }
}

if (!function_exists('ea_last_sync_label')) {
    function ea_last_sync_label(): string
    {
        $t = session('bi_last_sync');
        if (!$t) return 'never synced';
        $diff = time() - (int) $t;
        if ($diff < 60)    return 'just now';
        if ($diff < 3600)  return intdiv($diff, 60) . ' min ago';
        if ($diff < 86400) return intdiv($diff, 3600) . ' h ago';
        return intdiv($diff, 86400) . ' d ago';
    }
}

if (!function_exists('ea_money')) {
    function ea_money(float $v, string $currency = 'USD'): string
    {
        return number_format($v, 0, '.', ',') . ' ' . $currency;
    }
}

if (!function_exists('ea_safe_error')) {
    /**
     * Convert a caught exception into a SAFE, user-facing message while
     * logging the full detail server-side.
     *
     * Why: several controllers historically passed `$e->getMessage()`
     * straight to a Blade `$error` variable shown to end users. Raw
     * exception text can leak Odoo hostnames, SQL fragments, file paths,
     * and connection strings. This helper logs the real message + trace
     * (so debugging isn't lost) and returns a generic sentence the user
     * can act on.
     *
     * In local/debug environments it still returns the real message so
     * developers see what broke.
     *
     * @param  \Throwable $e
     * @param  string     $context  short label for the log line (e.g. 'revenue_report')
     * @param  string     $userMsg  the generic message shown to the user
     */
    function ea_safe_error(\Throwable $e, string $context = 'operation', string $userMsg = ''): string
    {
        \Illuminate\Support\Facades\Log::error("[{$context}] " . $e->getMessage(), [
            'exception' => get_class($e),
            'file'      => $e->getFile() . ':' . $e->getLine(),
        ]);

        // Developers in local/debug get the real message; everyone else
        // gets the generic one.
        if (config('app.debug')) {
            return $e->getMessage();
        }

        return $userMsg !== ''
            ? $userMsg
            : 'Something went wrong while completing this request. The issue has been logged — please try again, and contact support if it persists.';
    }
}

if (!function_exists('ea_money_short')) {
    function ea_money_short(float $v, string $currency = 'USD'): string
    {
        $abs = abs($v);
        $sign = $v < 0 ? '-' : '';
        if ($abs >= 1e9) return $sign . number_format($abs / 1e9, 2) . 'B ' . $currency;
        if ($abs >= 1e6) return $sign . number_format($abs / 1e6, 2) . 'M ' . $currency;
        if ($abs >= 1e3) return $sign . number_format($abs / 1e3, 1) . 'K ' . $currency;
        return $sign . number_format($abs, 0) . ' ' . $currency;
    }
}

if (!function_exists('tenant_logo_url')) {
    /**
     * Returns the public URL of the active tenant's logo, or null when
     * unset. logo_path is stored on tenants.logo_path as a path relative
     * to public/ — matches the migration's `// public/uploads/<file>`
     * comment and lets the file be served directly by the web server
     * without a Laravel storage symlink (one less ops gotcha).
     *
     * Example: tenants.logo_path = 'uploads/tenant-logos/12-tadsa.png'
     *          tenant_logo_url() = '/uploads/tenant-logos/12-tadsa.png'
     */
    function tenant_logo_url(): ?string
    {
        $t = tenant();
        if (!$t || !$t->logo_path) return null;
        $path = ltrim((string) $t->logo_path, '/');
        // If logo_path already includes a scheme, hand it back verbatim.
        if (preg_match('#^https?://#', $path)) return $path;
        return '/' . $path;
    }
}

if (!function_exists('tenant_brand_name')) {
    /**
     * Brand / etp name shown in the top-right company badge and below the
     * logo. Resolution order: tenant.brand → tenant.name → config('bi.brand')
     * → 'Workspace'. Always returns a non-empty string.
     */
    function tenant_brand_name(): string
    {
        $t = tenant();
        if ($t) {
            if (!empty($t->brand)) return (string) $t->brand;
            if (!empty($t->name))  return (string) $t->name;
        }
        return (string) (config('bi.brand') ?: 'Workspace');
    }
}

if (!function_exists('tenant_brand_initial')) {
    /**
     * One-letter fallback used by the company-badge avatar when no
     * uploaded logo exists. Strips diacritics and uppercases.
     */
    function tenant_brand_initial(): string
    {
        $name = tenant_brand_name();
        $first = mb_substr(trim($name), 0, 1, 'UTF-8');
        return mb_strtoupper($first ?: 'W', 'UTF-8');
    }
}

if (!function_exists('current_period')) {
    /**
     * Returns the active period array as resolved by the RememberPeriod
     * middleware. Reads from the request first (URL params), then falls
     * back to session, then to YTD-current-year defaults.
     *
     * Shape: ['type' => 'ytd|mtd|month|yoy', 'year' => int, 'month' => int]
     *
     * Controllers may keep using $request->query('period', ...) — this
     * helper is for views/components that need the period without a
     * Request injection.
     */
    function current_period(): array
    {
        $today = new \DateTimeImmutable();
        return [
            'type'  => session('bms.period.type',  'ytd'),
            'year'  => (int) session('bms.period.year',  (int) $today->format('Y')),
            'month' => (int) session('bms.period.month', (int) $today->format('m')),
        ];
    }
}
