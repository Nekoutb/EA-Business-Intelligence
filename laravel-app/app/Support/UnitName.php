<?php

namespace App\Support;

/**
 * The tenant's unit of activity (setting UNIT_NAME): what "Revenue per …",
 * "… per day" and "Cost per …" are counted in. Outfits for retail, files
 * for clearing agents, scans for scanning operators.
 */
final class UnitName
{
    public const OPTIONS = [
        'outfit'    => 'Outfit',
        'file'      => 'File',
        'scan'      => 'Scan',
        'order'     => 'Order',
        'container' => 'Container',
        'unit'      => 'Unit',
    ];

    public static function get(): string
    {
        $v = strtolower(trim((string) config_env('UNIT_NAME', 'outfit')));
        return isset(self::OPTIONS[$v]) ? $v : 'outfit';
    }

    public static function plural(): string
    {
        return self::get() . 's';
    }

    /** Abbreviations: OpD → FpD, RpO → RpF, CpO → CpF … */
    public static function abbr(): array
    {
        $l = strtoupper(substr(self::get(), 0, 1));
        return ['opd' => $l . 'pD', 'rpo' => 'Rp' . $l, 'cpo' => 'Cp' . $l];
    }
}
