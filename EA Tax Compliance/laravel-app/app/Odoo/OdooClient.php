<?php
declare(strict_types=1);

namespace App\Odoo;

/**
 * Odoo RPC client abstraction.
 *
 * Three transports implement this interface:
 *   - XmlRpcClient   (/xmlrpc/2)   - Odoo 13 -> 19 (deprecated in 19, removed in 22)
 *   - JsonRpcClient  (/jsonrpc)    - Odoo 13 -> 19 (deprecated in 19, removed in 22)
 *   - Json2Client    (/json/2)     - Odoo 19+ (recommended)
 *
 * The high-level helpers (searchRead / searchCount / read / executeKwModel) are
 * protocol-agnostic: callers should prefer them over executeKw() which does not
 * exist on the JSON-2 transport (JSON-2 has no positional-args concept).
 */
interface OdooClient
{
    /**
     * Return the acting user id (performs authentication on legacy protocols;
     * on JSON-2 it fetches /json/2/res.users/search using the bearer token).
     */
    public function authenticate(): int;

    /**
     * Read records by id.
     *
     * @param int[]    $ids
     * @param string[] $fields
     * @return array<int,array<string,mixed>>
     */
    public function read(string $model, array $ids, array $fields): array;

    /**
     * search_read with a domain.
     *
     * @param array    $domain   Odoo domain, e.g. [['state','=','posted']]
     * @param string[] $fields
     * @param array{offset?:int,limit?:int,order?:string,context?:array} $options
     * @return array<int,array<string,mixed>>
     */
    public function searchRead(string $model, array $domain, array $fields, array $options = []): array;

    /**
     * search_count with a domain. Returns the number of matching records.
     */
    public function searchCount(string $model, array $domain): int;

    /**
     * Call an arbitrary @api.model method by passing only keyword arguments.
     * Works on all three protocols (legacy protocols wrap it into execute_kw
     * with an empty positional args list).
     *
     * @return mixed
     */
    public function callModel(string $model, string $method, array $kwargs = []);
}
