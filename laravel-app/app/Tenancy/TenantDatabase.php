<?php

namespace App\Tenancy;

use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Where a client's DATA lives (the data layer), as opposed to the control
 * layer (tenants, users, modules, settings) which always stays on the
 * platform database.
 *
 *   data_location = 'shared'  (default) rows live in the platform database,
 *                             separated by tenant_id — today's behaviour.
 *                 = 'ea'      the client's own database on the EA server.
 *                 = 'client'  the client's own database on THEIR server,
 *                             reached through a tunnel / allow-listed TLS.
 *
 * Code that touches client data never names a connection itself: it uses
 * ea_tdb() / ea_tconn() / ea_cache() or a model with OnTenantDatabase,
 * which resolve here. For 'shared' clients they resolve to the default
 * connection and store, so behaviour is identical to before.
 *
 * There is deliberately NO fallback: if an isolated client's database is
 * unreachable the query fails — client data is never written to the
 * platform database instead.
 */
final class TenantDatabase
{
    public const CONN  = 'tenant';
    public const STORE = 'tenant';
    public const LOCATIONS = ['shared', 'ea', 'client'];

    /** Tenant id the 'tenant' connection is currently configured for. */
    private static ?int $active = null;

    public static function location(?Tenant $t): string
    {
        if (!$t) return 'shared';
        $loc = (string) ($t->getAttribute('data_location') ?? 'shared');
        return in_array($loc, self::LOCATIONS, true) ? $loc : 'shared';
    }

    public static function isolated(?Tenant $t): bool
    {
        return self::location($t) !== 'shared';
    }

    /** Connection name for the client in context; null = default connection. */
    public static function connectionName(): ?string
    {
        $t = app(TenantContext::class)->tenant();
        if (!self::isolated($t)) return null;
        if (self::$active !== $t->id) self::activate($t);
        return self::CONN;
    }

    /** Cache store name for the client in context; null = default store. */
    public static function storeName(): ?string
    {
        return self::connectionName() === null ? null : self::STORE;
    }

    /** Point the 'tenant' connection at this client's database (no-op when shared). */
    public static function activate(Tenant $t): void
    {
        if (!self::isolated($t)) { self::reset(); return; }
        if (self::$active === $t->id) return;
        self::configure($t, self::location($t));
        self::$active = $t->id;
    }

    /**
     * Point the 'tenant' connection at the database this client WOULD use
     * at $location ('ea' | 'client'), whatever its current data_location —
     * used to provision / copy / check before a switch-over. The next
     * request-path lookup re-activates normally.
     */
    public static function target(Tenant $t, string $location): void
    {
        if (!in_array($location, ['ea', 'client'], true)) {
            throw new \InvalidArgumentException("Not a dedicated location: '{$location}'.");
        }
        self::configure($t, $location);
        self::$active = -1; // force a normal activate() on the next lookup
    }

    private static function configure(Tenant $t, string $location): void
    {
        $default = (string) config('database.default');
        config(['database.connections.' . self::CONN => self::configFor($t, (array) config("database.connections.{$default}"), $location)]);
        config(['cache.stores.' . self::STORE => [
            'driver' => 'database', 'connection' => self::CONN, 'table' => 'cache',
            'lock_connection' => self::CONN, 'lock_table' => 'cache_locks',
        ]]);
        DB::purge(self::CONN);
        Cache::forgetDriver(self::STORE);
    }

    public static function reset(): void
    {
        if (self::$active !== null) {
            DB::purge(self::CONN);
            Cache::forgetDriver(self::STORE);
        }
        self::$active = null;
    }

    /** Default database name for a client's own database on the EA server. */
    public static function defaultDatabaseName(Tenant $t): string
    {
        return 'bms_' . trim(preg_replace('/[^a-z0-9]+/', '_', strtolower((string) $t->slug)), '_');
    }

    /**
     * Connection config for a client's own database. Starts from the
     * platform connection (driver, charset, options) and overrides only
     * the address and credentials.
     */
    public static function configFor(Tenant $t, array $base, ?string $location = null): array
    {
        $c = (array) ($t->getAttribute('db_config') ?? []);
        $cfg = $base;
        $cfg['url'] = null;
        $location ??= self::location($t);

        if ($location === 'ea') {
            $db = (string) ($c['database'] ?? self::defaultDatabaseName($t));
            if (($base['driver'] ?? '') === 'sqlite') {
                // local / test only: a bare name lives next to the platform file
                $db = preg_match('#[/\\\\]#', $db) ? $db : database_path($db);
                $db .= str_ends_with($db, '.sqlite') ? '' : '.sqlite';
            }
            $cfg['database'] = $db;
            return $cfg;
        }

        foreach (['host', 'port', 'database', 'username'] as $k) {
            if (!isset($c[$k]) || $c[$k] === '') {
                throw new \RuntimeException("Data hosting for '{$t->slug}' is missing '{$k}'.");
            }
        }
        foreach (['driver', 'host', 'port', 'database', 'username', 'password'] as $k) {
            if (array_key_exists($k, $c)) $cfg[$k] = $c[$k];
        }
        $opts = (array) ($cfg['options'] ?? []);
        $opts[\PDO::ATTR_TIMEOUT] = 5;
        if (!empty($c['ssl_ca']) && defined('PDO::MYSQL_ATTR_SSL_CA')) {
            $opts[constant('PDO::MYSQL_ATTR_SSL_CA')] = (string) $c['ssl_ca'];
            if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                $opts[constant('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')] = true;
            }
        }
        $cfg['options'] = $opts;
        return $cfg;
    }
}
