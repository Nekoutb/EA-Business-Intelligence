<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Client for ERP data exposed through a REST gateway (ERP_TYPE=rest) —
 * the shape TS Consulting / t-a-cam serve in front of Sage 100: an HTTPS
 * base URL, an API key sent in a configurable header (X-API-Key by
 * default), and read-only JSON endpoints (/test-db, /ecritures).
 *
 * Reads its credentials from the tenant settings of the given DataSource
 * (ERP_API_URL / ERP_API_KEY_HEADER / ERP_API_KEY, or the ERP2_ variants
 * for a second source).
 */
class SageRestClient
{
    private string $base;
    private string $header;
    private string $key;

    public function __construct(?DataSource $src = null)
    {
        $src ??= DataSource::primary();
        $k = fn (string $b) => $src->key($b);
        $this->base   = rtrim(trim((string) config_env($k('ERP_API_URL'), '')), '/');
        $this->header = trim((string) config_env($k('ERP_API_KEY_HEADER'), '')) ?: 'X-API-Key';
        $this->key    = trim((string) config_env($k('ERP_API_KEY'), ''));
    }

    public function configured(): bool
    {
        return $this->base !== '' && $this->key !== '';
    }

    /** GET a JSON endpoint; throws with the HTTP status + body on failure. */
    public function get(string $path, array $query = []): array
    {
        $resp = Http::withHeaders([$this->header => $this->key])
            ->acceptJson()
            ->timeout(60)
            ->withOptions(['verify' => true])
            ->get($this->base . '/' . ltrim($path, '/'), $query);

        if (!$resp->ok()) {
            throw new \RuntimeException(
                'API ' . $path . ': HTTP ' . $resp->status() . ' — ' . mb_substr((string) $resp->body(), 0, 300)
            );
        }
        $json = $resp->json();
        if (!is_array($json)) {
            throw new \RuntimeException('API ' . $path . ': response is not JSON.');
        }
        return $json;
    }

    /**
     * Connection test for the settings pages — same payload shape as the
     * Odoo / direct-DB testers so the shared "Test connection" button
     * renders it unchanged.
     */
    public static function testCurrent(?DataSource $src = null): array
    {
        $src ??= DataSource::primary();
        $k = fn (string $b) => $src->key($b);
        $result = [
            'ok' => false, 'latency_ms' => 0, 'message' => '',
            'server_version' => null, 'protocol' => 'rest',
        ];

        $missing = [];
        foreach (['ERP_API_URL' => 'API URL', 'ERP_API_KEY' => 'API key'] as $key => $label) {
            if (trim((string) config_env($k($key), '')) === '') $missing[] = $label;
        }
        if ($missing !== []) {
            $result['message'] = 'Missing: ' . implode(', ', $missing) . '. Save credentials before testing.';
            return $result;
        }

        $client  = new self($src);
        $path    = trim((string) config_env($k('ERP_API_TEST_PATH'), '')) ?: '/test-db';
        $started = hrtime(true);
        try {
            $json = $client->get($path);
            $result['latency_ms'] = (int) round((hrtime(true) - $started) / 1e6);
            $db     = (string) ($json['database'] ?? '');
            $status = (string) ($json['status'] ?? '');
            $result['ok'] = $status === '' || strtoupper($status) === 'OK';
            $result['server_version'] = $db !== '' ? $db : null;
            $result['message'] = $result['ok']
                ? 'Connected.' . ($db !== '' ? " Gateway reports database \"{$db}\"." : '')
                    . ((string) ($json['message'] ?? '') !== '' ? ' ' . $json['message'] : '')
                : 'Gateway answered but reported status "' . $status . '".';
        } catch (\Throwable $e) {
            $result['latency_ms'] = (int) round((hrtime(true) - $started) / 1e6);
            $result['message'] = $e->getMessage();
        }
        return $result;
    }
}
