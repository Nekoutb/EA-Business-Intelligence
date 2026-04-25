<?php
declare(strict_types=1);

namespace EA\BI\Odoo;

/**
 * Odoo 19+ "JSON-2" external API client.
 *
 * Endpoint: POST {base}/json/2/<model>/<method>
 * Auth:     Authorization: bearer <api_key>
 * DB:       X-Odoo-Database: <db>   (required on multi-DB deployments)
 * Body:     {"ids":[...], "context":{...}, <named method args...>}
 *
 * Reference: https://www.odoo.com/documentation/19.0/developer/reference/external_api.html
 *
 * Differences vs the legacy XML-RPC / JSON-RPC clients:
 *   - No login round-trip: the API key IS the credential, carried on every call.
 *   - No positional args: every method parameter is named in the JSON body.
 *   - HTTP status codes are meaningful (401/403/404/500).
 *   - Errors come back as {name, message, arguments, context, debug}.
 */
final class Json2Client implements OdooClient
{
    private string $url;
    private string $db;
    private string $apiKey;
    /** Optional, only used by authenticate() to return a uid for UI display. */
    private string $username;
    private ?int $uid = null;

    public function __construct(string $url, string $db, string $username, string $apiKey)
    {
        $this->url = rtrim($url, '/');
        $this->db = $db;
        $this->username = $username;
        $this->apiKey = $apiKey;
    }

    public function authenticate(): int
    {
        if ($this->uid !== null) {
            return $this->uid;
        }
        // JSON-2 has no explicit login step: the bearer API key IS the identity
        // on every call, and the server associates it with its owning user.
        // To return a uid (useful for the "Test connection" UI and for currency
        // detection which reads res.users.company_id), we look up the user by
        // login. If the API key is invalid, this call throws with HTTP 401.
        $ids = $this->call('res.users', 'search', [
            'domain' => [['login', '=', $this->username]],
            'limit'  => 1,
        ]);
        if (is_array($ids) && isset($ids[0])) {
            return $this->uid = (int) $ids[0];
        }
        throw new OdooException(
            'API key is valid but no user with login "' . $this->username . '" was found. ' .
            'Make sure the username matches the account that owns this API key.'
        );
    }

    public function read(string $model, array $ids, array $fields): array
    {
        $res = $this->call($model, 'read', [
            'ids'    => array_values($ids),
            'fields' => array_values($fields),
        ]);
        return is_array($res) ? $res : [];
    }

    public function searchRead(string $model, array $domain, array $fields, array $options = []): array
    {
        $body = [
            'domain' => $domain,
            'fields' => array_values($fields),
        ];
        foreach (['offset', 'limit', 'order', 'context'] as $k) {
            if (isset($options[$k])) {
                $body[$k] = $options[$k];
            }
        }
        $res = $this->call($model, 'search_read', $body);
        return is_array($res) ? $res : [];
    }

    public function searchCount(string $model, array $domain): int
    {
        $res = $this->call($model, 'search_count', ['domain' => $domain]);
        return is_int($res) ? $res : (int) $res;
    }

    public function callModel(string $model, string $method, array $kwargs = [])
    {
        return $this->call($model, $method, $kwargs);
    }

    // ---------------------------------------------------------------------

    private function call(string $model, string $method, array $body)
    {
        $endpoint = $this->url . '/json/2/' . $model . '/' . $method;
        $payload  = json_encode($body === [] ? new \stdClass() : $body,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json; charset=utf-8',
                'Authorization: bearer ' . $this->apiKey,
                'X-Odoo-Database: ' . $this->db,
                'User-Agent: EA-BI/1.0 (+PHP)',
            ],
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_CAINFO         => dirname(__DIR__, 2) . '/php-runtime/cacert.pem',
        ]);
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new OdooException('HTTP error calling Odoo JSON-2: ' . $err);
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode((string) $resp, true);

        if ($code >= 400) {
            $msg = is_array($decoded) && isset($decoded['message'])
                ? (string) $decoded['message']
                : ('HTTP ' . $code);
            $name = is_array($decoded) && isset($decoded['name']) ? (string) $decoded['name'] : '';
            throw new OdooException('Odoo JSON-2 ' . $code . ($name !== '' ? ' [' . $name . ']' : '') . ': ' . $msg);
        }

        return $decoded;
    }
}
