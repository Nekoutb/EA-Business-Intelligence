<?php

namespace App\Services\Clearance;

/**
 * Evaluates an Odoo domain against one record (snapshot mode and tests).
 * Supports prefix '&' '|' '!' and the leaf operators the pack uses:
 * = != in "not in" < <= > >= ilike "not ilike". many2one values compare by id.
 */
class Domain
{
    public static function matches(array $row, array $domain): bool
    {
        if (!$domain) return true;
        $pos = 0;
        $results = [];
        $n = count($domain);
        while ($pos < $n) {
            $results[] = self::evalAt($row, $domain, $pos);
        }
        return !in_array(false, $results, true); // implicit AND between top-level terms
    }

    private static function evalAt(array $row, array $d, int &$pos): bool
    {
        $t = $d[$pos++];
        if ($t === '&') { $a = self::evalAt($row, $d, $pos); $b = self::evalAt($row, $d, $pos); return $a && $b; }
        if ($t === '|') { $a = self::evalAt($row, $d, $pos); $b = self::evalAt($row, $d, $pos); return $a || $b; }
        if ($t === '!') return !self::evalAt($row, $d, $pos);
        [$f, $op, $v] = $t;
        $x = $row[$f] ?? false;
        if (is_array($x) && count($x) === 2 && is_int($x[0]) && is_string($x[1])) $x = $x[0]; // many2one
        return match ($op) {
            '='         => $v === false ? ($x === false || $x === null || $x === '' || $x === []) : $x == $v,
            '!='        => $v === false ? !($x === false || $x === null || $x === '' || $x === []) : $x != $v,
            'in'        => is_array($x) ? (bool) array_intersect($x, (array) $v) : in_array($x, (array) $v, false),
            'not in'    => is_array($x) ? !array_intersect($x, (array) $v) : !in_array($x, (array) $v, false),
            '<'         => $x !== false && $x < $v,
            '<='        => $x !== false && $x <= $v,
            '>'         => $x !== false && $x > $v,
            '>='        => $x !== false && $x >= $v,
            'ilike'     => is_string($x) && stripos($x, (string) $v) !== false,
            'not ilike' => !is_string($x) || stripos($x, (string) $v) === false,
            '=like'     => is_string($x) && preg_match('/^' . str_replace(['%', '_'], ['.*', '.'], preg_quote((string) $v, '/')) . '$/s', $x) === 1,
            default     => throw new \InvalidArgumentException("unsupported domain operator {$op}"),
        };
    }
}
