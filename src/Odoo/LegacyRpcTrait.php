<?php
declare(strict_types=1);

namespace EA\BI\Odoo;

/**
 * Shared implementation of the high-level helpers for XML-RPC and JSON-RPC
 * clients. Both legacy protocols expose the same execute_kw function, so the
 * helpers can be implemented once and reused by both transports.
 */
trait LegacyRpcTrait
{
    /** Implemented by the including class. */
    abstract public function executeKw(string $model, string $method, array $args, array $kwargs = []);

    public function read(string $model, array $ids, array $fields): array
    {
        $res = $this->executeKw($model, 'read', [array_values($ids)], ['fields' => array_values($fields)]);
        return is_array($res) ? $res : [];
    }

    public function searchRead(string $model, array $domain, array $fields, array $options = []): array
    {
        $kwargs = ['fields' => array_values($fields)];
        foreach (['offset', 'limit', 'order', 'context'] as $k) {
            if (isset($options[$k])) {
                $kwargs[$k] = $options[$k];
            }
        }
        $res = $this->executeKw($model, 'search_read', [$domain], $kwargs);
        return is_array($res) ? $res : [];
    }

    public function searchCount(string $model, array $domain): int
    {
        $res = $this->executeKw($model, 'search_count', [$domain]);
        return is_int($res) ? $res : (int) $res;
    }

    public function callModel(string $model, string $method, array $kwargs = [])
    {
        return $this->executeKw($model, $method, [], $kwargs);
    }
}
