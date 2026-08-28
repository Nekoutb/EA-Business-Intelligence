<?php
declare(strict_types=1);

namespace App\Services;

use App\Odoo\OdooFactory;

/**
 * Probes whether the currently saved Odoo credentials actually work.
 *
 * Returns a uniform shape that the Settings UI renders:
 *   [
 *     'ok'             => bool,
 *     'latency_ms'     => int,
 *     'message'        => string,    // human-readable
 *     'server_version' => string|null,
 *     'user_id'        => int|null,
 *     'protocol'       => string,
 *   ]
 *
 * Reads credentials via OdooFactory::fromConfig(), which honours the
 * active tenant context. Calls authenticate() — that round-trips the
 * full chain (URL → DB → username → API key) and returns the user id
 * on success, or throws an OdooException with a useful message.
 */
final class OdooConnectionTester
{
    public static function testCurrent(?DataSource $src = null): array
    {
        $src ??= DataSource::primary();
        $protocol = (string) config_env($src->key('ODOO_PROTOCOL'), 'jsonrpc');

        $result = [
            'ok'             => false,
            'latency_ms'     => 0,
            'message'        => '',
            'server_version' => null,
            'user_id'        => null,
            'protocol'       => $protocol,
        ];

        // Cheap pre-flight on the saved fields so the user gets a clear
        // "fill these in first" message instead of a stack trace.
        $missing = [];
        foreach (['ODOO_URL' => 'Odoo URL', 'ODOO_DB' => 'Database', 'ODOO_USERNAME' => 'Username', 'ODOO_API_KEY' => 'API Key'] as $key => $label) {
            if (trim((string) config_env($src->key($key), '')) === '') {
                $missing[] = $label;
            }
        }
        if ($missing !== []) {
            $result['message'] = 'Missing: ' . implode(', ', $missing) . '. Save credentials before testing.';
            return $result;
        }

        $started = hrtime(true);
        try {
            $client = OdooFactory::fromSource($src);
            $uid    = $client->authenticate();

            $result['latency_ms'] = (int) round((hrtime(true) - $started) / 1e6);

            if (!$uid) {
                $result['message'] = 'Odoo rejected the credentials. Check username, database name (case-sensitive), and API key.';
                return $result;
            }

            $result['ok']      = true;
            $result['user_id'] = (int) $uid;
            $result['message'] = 'Connected. Authenticated as user #' . $uid . ' on Odoo. Latency ' . $result['latency_ms'] . ' ms.';
            return $result;
        } catch (\Throwable $e) {
            $result['latency_ms'] = (int) round((hrtime(true) - $started) / 1e6);
            $result['message']    = self::friendlyError($e->getMessage());
            return $result;
        }
    }

    /**
     * Translate raw curl / OdooException messages into something a user
     * can act on. Falls through to the original on no match.
     */
    private static function friendlyError(string $raw): string
    {
        $r = strtolower($raw);

        if (str_contains($r, 'connection timed out') || str_contains($r, 'operation timed out')) {
            return 'Connection timed out. Server may be up but BMS cannot reach it — check the firewall on the Odoo host (whitelist BMS IP) or verify the URL/port.';
        }
        if (str_contains($r, 'could not resolve host') || str_contains($r, 'name or service not known')) {
            return 'DNS lookup failed. The Odoo URL hostname is unresolvable.';
        }
        if (str_contains($r, 'connection refused')) {
            return 'Connection refused. The host is up but nothing is listening on that port.';
        }
        if (str_contains($r, 'ssl certificate problem') || str_contains($r, 'unable to get local issuer certificate')) {
            return 'TLS certificate problem. The server\'s HTTPS certificate could not be verified.';
        }
        if (str_contains($r, 'http 404') || str_contains($r, 'json-2 404') || str_contains($r, 'jsonrpc 404')) {
            return 'HTTP 404 from Odoo. Most likely the wrong protocol for your Odoo version (try JSON-RPC for Odoo 14-18).';
        }
        if (str_contains($r, 'access denied') || str_contains($r, 'authentication failed') || str_contains($r, 'rejected the credentials')) {
            return 'Odoo rejected the credentials. Check username, database name (case-sensitive), and API key.';
        }
        if (str_contains($r, 'database') && str_contains($r, 'does not exist')) {
            return 'Database not found on this Odoo server. The DB name is case-sensitive.';
        }
        return 'Odoo error: ' . $raw;
    }
}
