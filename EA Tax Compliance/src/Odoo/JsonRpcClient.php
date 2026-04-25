<?php
declare(strict_types=1);

namespace EA\BI\Odoo;

final class JsonRpcClient implements OdooClient
{
    use LegacyRpcTrait;

    private string $url;
    private string $db;
    private string $username;
    private string $apiKey;
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
        $resp = $this->call('/jsonrpc', [
            'service' => 'common',
            'method'  => 'authenticate',
            'args'    => [$this->db, $this->username, $this->apiKey, new \stdClass()],
        ]);
        if (!is_int($resp) || $resp <= 0) {
            throw new OdooException('Odoo authentication failed. Check URL, DB, username and API key.');
        }
        return $this->uid = $resp;
    }

    public function executeKw(string $model, string $method, array $args, array $kwargs = [])
    {
        $uid = $this->authenticate();
        return $this->call('/jsonrpc', [
            'service' => 'object',
            'method'  => 'execute_kw',
            'args'    => [$this->db, $uid, $this->apiKey, $model, $method, $args, (object) $kwargs],
        ]);
    }

    private function call(string $path, array $params)
    {
        $payload = json_encode([
            'jsonrpc' => '2.0',
            'method'  => 'call',
            'params'  => $params,
            'id'      => random_int(1, PHP_INT_MAX),
        ], JSON_UNESCAPED_SLASHES);

        $ch = curl_init($this->url . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new OdooException('HTTP error calling Odoo: ' . $err);
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code >= 400) {
            throw new OdooException('Odoo HTTP ' . $code . ': ' . substr((string) $body, 0, 500));
        }
        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new OdooException('Invalid JSON response from Odoo.');
        }
        if (isset($decoded['error'])) {
            $msg = $decoded['error']['data']['message'] ?? $decoded['error']['message'] ?? 'Unknown Odoo error';
            throw new OdooException('Odoo error: ' . $msg);
        }
        return $decoded['result'] ?? null;
    }
}
