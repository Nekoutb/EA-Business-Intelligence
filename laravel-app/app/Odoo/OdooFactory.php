<?php
declare(strict_types=1);

namespace App\Odoo;



final class OdooFactory
{
    public static function fromConfig(?string $protocolOverride = null): OdooClient
    {
        $url  = (string) config_env('ODOO_URL', '');
        $db   = (string) config_env('ODOO_DB', '');
        $user = (string) config_env('ODOO_USERNAME', '');
        $key  = (string) config_env('ODOO_API_KEY', '');
        $protocol = $protocolOverride ?? (config_env('ODOO_PROTOCOL', 'jsonrpc') ?: 'jsonrpc');

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
