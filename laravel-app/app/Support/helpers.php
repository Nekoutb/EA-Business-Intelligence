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
                $from = $today->modify('first day of January')->setTime(0, 0, 0);
                $to   = $today->setTime(23, 59, 59);
                return [$from, $to, 'Year-to-date ' . $today->format('Y'), 'ytd'];
        }
    }
}

if (!function_exists('ea_cached')) {
    /**
     * Session-scoped cache used by services. Keys are namespaced by the active
     * tenant id so caches never bleed across workspaces. First caller within
     * TTL wins, subsequent callers reuse.
     */
    function ea_cached(string $key, callable $producer)
    {
        $ttl    = (int) config('bi.cache_ttl', 300);
        $tid    = optional(tenant())->id;
        $scoped = ($tid ? 't' . $tid . ':' : 'g:') . $key;
        $bucket = session('bi_cache', []);
        if (isset($bucket[$scoped]) && (time() - (int) $bucket[$scoped]['at']) < $ttl) {
            return $bucket[$scoped]['data'];
        }
        $data            = $producer();
        $bucket[$scoped] = ['at' => time(), 'data' => $data];
        session(['bi_cache' => $bucket, 'bi_last_sync' => time()]);
        return $data;
    }
}

if (!function_exists('ea_cache_clear')) {
    /**
     * Clear cache. When a tenant is in context only that tenant's keys are
     * dropped; otherwise the entire bucket is wiped.
     */
    function ea_cache_clear(): void
    {
        $tid = optional(tenant())->id;
        if (!$tid) {
            session()->forget('bi_cache');
        } else {
            $bucket = session('bi_cache', []);
            $prefix = 't' . $tid . ':';
            foreach (array_keys($bucket) as $k) {
                if (str_starts_with((string) $k, $prefix)) {
                    unset($bucket[$k]);
                }
            }
            session(['bi_cache' => $bucket]);
        }
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
