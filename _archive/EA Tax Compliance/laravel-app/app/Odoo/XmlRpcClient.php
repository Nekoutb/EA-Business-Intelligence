<?php
declare(strict_types=1);

namespace App\Odoo;

/**
 * Minimal XML-RPC client for Odoo (no PHP extension required).
 * Endpoints:
 *   /xmlrpc/2/common  -> authenticate
 *   /xmlrpc/2/object  -> execute_kw
 */
final class XmlRpcClient implements OdooClient
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
        $result = $this->call('/xmlrpc/2/common', 'authenticate', [
            $this->db, $this->username, $this->apiKey, [],
        ]);
        if (!is_int($result) || $result <= 0) {
            throw new OdooException('Odoo authentication failed. Check URL, DB, username and API key.');
        }
        return $this->uid = $result;
    }

    public function executeKw(string $model, string $method, array $args, array $kwargs = [])
    {
        $uid = $this->authenticate();
        return $this->call('/xmlrpc/2/object', 'execute_kw', [
            $this->db, $uid, $this->apiKey, $model, $method, $args, $kwargs,
        ]);
    }

    private function call(string $path, string $method, array $params)
    {
        $body = $this->buildRequest($method, $params);
        $ch = curl_init($this->url . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: text/xml'],
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new OdooException('HTTP error calling Odoo: ' . $err);
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 400) {
            throw new OdooException('Odoo HTTP ' . $code . ': ' . substr((string) $resp, 0, 500));
        }
        return $this->parseResponse((string) $resp);
    }

    // ---- XML-RPC encoding ------------------------------------------------

    private function buildRequest(string $method, array $params): string
    {
        $xml = '<?xml version="1.0"?><methodCall><methodName>' . htmlspecialchars($method, ENT_XML1) . '</methodName><params>';
        foreach ($params as $p) {
            $xml .= '<param>' . $this->encodeValue($p) . '</param>';
        }
        $xml .= '</params></methodCall>';
        return $xml;
    }

    private function encodeValue($v): string
    {
        if (is_bool($v)) {
            return '<value><boolean>' . ($v ? '1' : '0') . '</boolean></value>';
        }
        if (is_int($v)) {
            return '<value><int>' . $v . '</int></value>';
        }
        if (is_float($v)) {
            return '<value><double>' . $v . '</double></value>';
        }
        if (is_string($v)) {
            return '<value><string>' . htmlspecialchars($v, ENT_XML1) . '</string></value>';
        }
        if ($v === null) {
            return '<value><nil/></value>';
        }
        if (is_array($v)) {
            if ($this->isAssoc($v)) {
                $xml = '<value><struct>';
                foreach ($v as $k => $val) {
                    $xml .= '<member><name>' . htmlspecialchars((string) $k, ENT_XML1) . '</name>'
                         . $this->encodeValue($val) . '</member>';
                }
                return $xml . '</struct></value>';
            }
            $xml = '<value><array><data>';
            foreach ($v as $val) {
                $xml .= $this->encodeValue($val);
            }
            return $xml . '</data></array></value>';
        }
        throw new OdooException('Unsupported XML-RPC value type: ' . gettype($v));
    }

    private function isAssoc(array $a): bool
    {
        if ($a === []) return false;
        return array_keys($a) !== range(0, count($a) - 1);
    }

    // ---- XML-RPC decoding ------------------------------------------------

    private function parseResponse(string $xml)
    {
        libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        if (!$doc->loadXML($xml)) {
            throw new OdooException('Invalid XML response from Odoo.');
        }
        $fault = $doc->getElementsByTagName('fault')->item(0);
        if ($fault instanceof \DOMElement) {
            $val = $this->decodeValue($this->firstChildElement($fault->getElementsByTagName('value')->item(0)));
            $msg = is_array($val) ? ($val['faultString'] ?? json_encode($val)) : (string) $val;
            throw new OdooException('Odoo fault: ' . $msg);
        }
        $params = $doc->getElementsByTagName('param');
        if ($params->length === 0) {
            return null;
        }
        $valueNode = $params->item(0)->getElementsByTagName('value')->item(0);
        return $this->decodeValue($valueNode);
    }

    private function firstChildElement(?\DOMNode $node): ?\DOMElement
    {
        if (!$node) return null;
        foreach ($node->childNodes as $c) {
            if ($c instanceof \DOMElement) return $c;
        }
        return null;
    }

    private function decodeValue(?\DOMNode $valueNode)
    {
        if (!$valueNode) return null;
        $child = $this->firstChildElement($valueNode);
        if (!$child) {
            // <value>text</value> -> string
            return $valueNode->textContent;
        }
        switch ($child->nodeName) {
            case 'int':
            case 'i4':
            case 'i8':
                return (int) $child->textContent;
            case 'boolean':
                return $child->textContent === '1';
            case 'double':
                return (float) $child->textContent;
            case 'string':
                return $child->textContent;
            case 'dateTime.iso8601':
                return $child->textContent;
            case 'base64':
                return base64_decode($child->textContent, true) ?: '';
            case 'nil':
                return null;
            case 'array':
                $out = [];
                $data = $child->getElementsByTagName('data')->item(0);
                if ($data) {
                    foreach ($data->childNodes as $v) {
                        if ($v instanceof \DOMElement && $v->nodeName === 'value') {
                            $out[] = $this->decodeValue($v);
                        }
                    }
                }
                return $out;
            case 'struct':
                $out = [];
                foreach ($child->getElementsByTagName('member') as $m) {
                    $name = $m->getElementsByTagName('name')->item(0)->textContent ?? '';
                    $val  = $m->getElementsByTagName('value')->item(0);
                    $out[$name] = $this->decodeValue($val);
                }
                return $out;
        }
        return $child->textContent;
    }
}
