<?php
declare(strict_types=1);

namespace App\Odoo;

use App\Services\DataSource;

final class OdooFactory
{
    public static function fromConfig(?string $protocolOverride = null): OdooClient
    {
        return self::fromSource(DataSource::primary(), $protocolOverride);
    }

    /**
     * Client for one of the tenant's data sources. Source 1 reads the
     * historical ODOO_* keys; source 2 reads ERP2_ODOO_* (see DataSource).
     */
    public static function fromSource(DataSource $src, ?string $protocolOverride = null): OdooClient
    {
        $k = fn (string $base) => $src->key($base);
        // Non-Odoo workspaces (direct ERP database) get a null client so
        // controller-level construction never fails; warehouse-backed pages
        // work normally and only a live RPC call reports the situation.
        $erpType = strtolower((string) config_env($k('ERP_TYPE'), 'odoo'));
        if ($erpType !== '' && $erpType !== 'odoo') {
            $name = trim((string) config_env($k('ERP_NAME'), '')) ?: strtoupper($erpType);
            return new NullOdooClient($name);
        }

        $url  = (string) config_env($k('ODOO_URL'), '');
        $db   = (string) config_env($k('ODOO_DB'), '');
        $user = (string) config_env($k('ODOO_USERNAME'), '');
        $key  = (string) config_env($k('ODOO_API_KEY'), '');
        $protocol = $protocolOverride ?? (config_env($k('ODOO_PROTOCOL'), 'jsonrpc') ?: 'jsonrpc');

        if ($url === '' || $db === '' || $user === '' || $key === '') {
            throw new OdooException('Odoo credentials are not configured. Fill in the .env file (ODOO_URL, ODOO_DB, ODOO_USERNAME, ODOO_API_KEY).');
        }

        return match (strtolower($protocol)) {
            'xmlrpc', 'xml-rpc'         => new XmlRpcClient($url, $db, $user, $key),
            'json2', 'jsonrpc2', 'json-2' => new Json2Client($url, $db, $user, $key),
            default                     => new JsonRpcClient($url, $db, $user, $key),
        };
    }
}
