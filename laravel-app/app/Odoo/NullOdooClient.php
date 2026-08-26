<?php
declare(strict_types=1);

namespace App\Odoo;

/**
 * Stand-in client for workspaces that connect to a direct ERP database
 * instead of Odoo (ERP_TYPE != odoo).
 *
 * Construction must never fail — dozens of controllers build a client
 * up-front and then read from the local warehouse without ever touching
 * Odoo. Only an actual RPC call throws, with a message that tells the
 * reader WHY this page has no live source rather than "credentials not
 * configured".
 */
final class NullOdooClient implements OdooClient
{
    public function __construct(private string $erpName = 'a non-Odoo ERP')
    {
    }

    public function authenticate(): int
    {
        throw $this->unavailable();
    }

    public function read(string $model, array $ids, array $fields): array
    {
        throw $this->unavailable();
    }

    public function searchRead(string $model, array $domain, array $fields, array $options = []): array
    {
        throw $this->unavailable();
    }

    public function searchCount(string $model, array $domain): int
    {
        throw $this->unavailable();
    }

    public function callModel(string $model, string $method, array $kwargs = [])
    {
        throw $this->unavailable();
    }

    private function unavailable(): OdooException
    {
        return new OdooException(
            "This workspace is connected to {$this->erpName} through a direct database, " .
            'so live Odoo queries are not available. This report needs data the ERP sync ' .
            'does not provide yet.'
        );
    }
}
