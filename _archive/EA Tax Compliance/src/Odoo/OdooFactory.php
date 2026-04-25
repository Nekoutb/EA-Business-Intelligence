<?php
declare(strict_types=1);

namespace EA\BI\Odoo;

use EA\BI\Config;

final class OdooFactory
{
    public static function fromConfig(?string $protocolOverride = null): OdooClient
    {
        $url  = (string) Config::get('ODOO_URL', '');
        $db   = (string) Config::get('ODOO_DB', '');
        $user = (string) Config::get('ODOO_USERNAME', '');
        $key  = (string) Config::get('ODOO_API_KEY', '');
        $protocol = $protocolOverride ?? (Config::get('ODOO_PROTOCOL', 'jsonrpc') ?: 'jsonrpc');

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
