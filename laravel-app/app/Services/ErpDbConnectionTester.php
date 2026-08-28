<?php
declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Probes whether the saved direct-database ERP credentials actually work.
 *
 * Used when ERP_TYPE is anything other than 'odoo': instead of the Odoo
 * web API, BMS connects straight to the ERP's (or its BI replica's)
 * database over PDO. Mirrors the uniform result shape rendered by the
 * Settings UI:
 *   [
 *     'ok'             => bool,
 *     'latency_ms'     => int,
 *     'message'        => string,
 *     'server_version' => string|null,
 *     'protocol'       => string,   // here: the DB driver
 *   ]
 *
 * Reads credentials via config_env(), which honours the active tenant
 * context — call it inside a TenantContext::set() the same way
 * OdooConnectionTester is used.
 */
final class ErpDbConnectionTester
{
    /** Drivers the tester (and the runtime connector) will accept. */
    public const DRIVERS = ['pgsql', 'mysql', 'sqlsrv'];

    public static function testCurrent(?DataSource $src = null): array
    {
        $src ??= DataSource::primary();
        $k = fn (string $base) => $src->key($base);
        $driver = strtolower(trim((string) config_env($k('ERP_DB_DRIVER'), 'pgsql')));

        $result = [
            'ok'             => false,
            'latency_ms'     => 0,
            'message'        => '',
            'server_version' => null,
            'protocol'       => $driver,
        ];

        if (!in_array($driver, self::DRIVERS, true)) {
            $result['message'] = "Unsupported database driver '{$driver}'. Use one of: " . implode(', ', self::DRIVERS) . '.';
            return $result;
        }

        // Cheap pre-flight so the user gets a clear "fill these in first"
        // message instead of a stack trace.
        $missing = [];
        foreach (['ERP_DB_HOST' => 'Host', 'ERP_DB_DATABASE' => 'Database', 'ERP_DB_USERNAME' => 'Username', 'ERP_DB_PASSWORD' => 'Password'] as $key => $label) {
            if (trim((string) config_env($k($key), '')) === '') {
                $missing[] = $label;
            }
        }
        if ($missing !== []) {
            $result['message'] = 'Missing: ' . implode(', ', $missing) . '. Save credentials before testing.';
            return $result;
        }

        if (!extension_loaded('pdo_' . $driver)) {
            $result['message'] = "The PHP extension pdo_{$driver} is not installed on the BMS server, so this connection type cannot be used yet. Ask the platform operator to enable it.";
            return $result;
        }

        $connection = 'erp_probe_' . bin2hex(random_bytes(4));
        config(["database.connections.{$connection}" => self::connectionConfig($driver, $src)]);

        $started = hrtime(true);
        try {
            $pdo = DB::connection($connection)->getPdo();
            $result['latency_ms'] = (int) round((hrtime(true) - $started) / 1e6);

            try {
                $result['server_version'] = (string) $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);
            } catch (\Throwable $e) {
                // Some drivers don't expose it; not fatal.
            }

            // Confirm the configured schema is actually visible, so a typo
            // in the schema name fails here rather than on the first report.
            $schema = trim((string) config_env($k('ERP_DB_SCHEMA'), ''));
            if ($schema !== '' && $driver === 'pgsql') {
                $found = DB::connection($connection)
                    ->select('select 1 from information_schema.schemata where schema_name = ?', [$schema]);
                if ($found === []) {
                    $result['message'] = "Connected to the server, but schema \"{$schema}\" does not exist in this database.";
                    return $result;
                }
            }

            $result['ok']      = true;
            $result['message'] = 'Connected to ' . $driver . ' database'
                . ($schema !== '' ? " (schema \"{$schema}\")" : '')
                . '. Latency ' . $result['latency_ms'] . ' ms.';
            return $result;
        } catch (\Throwable $e) {
            $result['latency_ms'] = (int) round((hrtime(true) - $started) / 1e6);
            $result['message']    = self::friendlyError($e->getMessage());
            return $result;
        } finally {
            DB::purge($connection);
        }
    }

    /**
     * Build a Laravel connection config array from the tenant's ERP_DB_*
     * settings. Shared by the tester and (later) the runtime connector.
     */
    public static function connectionConfig(?string $driver = null, ?DataSource $src = null): array
    {
        $src ??= DataSource::primary();
        $k = fn (string $base) => $src->key($base);
        $driver = $driver ?: strtolower(trim((string) config_env($k('ERP_DB_DRIVER'), 'pgsql')));

        $config = [
            'driver'   => $driver,
            'host'     => trim((string) config_env($k('ERP_DB_HOST'), '')),
            'port'     => trim((string) config_env($k('ERP_DB_PORT'), '')) ?: self::defaultPort($driver),
            'database' => trim((string) config_env($k('ERP_DB_DATABASE'), '')),
            'username' => trim((string) config_env($k('ERP_DB_USERNAME'), '')),
            'password' => (string) config_env($k('ERP_DB_PASSWORD'), ''),
            'charset'  => 'utf8',
        ];

        if ($driver === 'pgsql') {
            // sslmode=require encrypts without verifying the certificate,
            // which is what self-signed ERP servers need ("refuse
            // verify-full" as providers put it).
            $config['sslmode'] = trim((string) config_env($k('ERP_DB_SSLMODE'), 'prefer')) ?: 'prefer';
            $schema = trim((string) config_env($k('ERP_DB_SCHEMA'), ''));
            if ($schema !== '') {
                $config['search_path'] = $schema;
            }
        }

        return $config;
    }

    private static function defaultPort(string $driver): string
    {
        return match ($driver) {
            'mysql'  => '3306',
            'sqlsrv' => '1433',
            default  => '5432',
        };
    }

    /**
     * Translate raw PDO messages into something a user can act on.
     */
    private static function friendlyError(string $raw): string
    {
        $r = strtolower($raw);

        if (str_contains($r, 'timeout') || str_contains($r, 'timed out')) {
            return 'Connection timed out. The server may be up but BMS cannot reach it — ask the provider to whitelist the BMS server IP, and verify the host/port.';
        }
        if (str_contains($r, 'could not translate host name') || str_contains($r, 'getaddrinfo') || str_contains($r, 'name or service not known')) {
            return 'DNS lookup failed. The database host name is unresolvable.';
        }
        if (str_contains($r, 'connection refused')) {
            return 'Connection refused. The host is up but nothing is listening on that port — check the port number and the server firewall.';
        }
        if (str_contains($r, 'password authentication failed') || str_contains($r, 'access denied for user') || str_contains($r, 'login failed')) {
            return 'The database rejected the credentials. Check the username and password.';
        }
        if (str_contains($r, 'does not exist') && str_contains($r, 'database')) {
            return 'Database not found on this server. The database name is case-sensitive.';
        }
        if (str_contains($r, 'no pg_hba.conf entry')) {
            return 'The server refused this client (pg_hba.conf). Usually the BMS server IP is not whitelisted, or SSL is required — try SSL mode "require".';
        }
        if (str_contains($r, 'ssl')) {
            return 'SSL negotiation failed. Try SSL mode "require" (self-signed certificates are accepted; "verify-full" is not supported for them). Raw: ' . $raw;
        }
        if (str_contains($r, 'could not find driver')) {
            return 'The PHP database driver for this engine is not installed on the BMS server. Ask the platform operator to enable it.';
        }
        return 'Database error: ' . $raw;
    }
}
