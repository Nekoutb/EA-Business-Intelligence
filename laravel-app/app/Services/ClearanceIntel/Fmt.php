<?php

namespace App\Services\ClearanceIntel;

/** Display formatting for Clearance Intelligence pages. */
final class Fmt
{
    public static function v($v, string $unit): string
    {
        if ($v === null || $v === '') return '—';
        $v = (float) $v;
        return match ($unit) {
            'int'   => number_format($v, 0, '.', ' '),
            'xaf'   => self::xaf($v),
            'pct'   => number_format($v, 1, '.', ' ') . ' %',
            'days'  => number_format($v, 1, '.', ' ') . ' d',
            default => number_format($v, 1, '.', ' '),
        };
    }

    public static function xaf(?float $v): string
    {
        if ($v === null) return '—';
        $a = abs($v); $s = $v < 0 ? '−' : '';
        if ($a >= 1e9) return $s . number_format($a / 1e9, 2, '.', ' ') . ' bn';
        if ($a >= 1e6) return $s . number_format($a / 1e6, 1, '.', ' ') . ' M';
        if ($a >= 1e4) return $s . number_format($a / 1e3, 0, '.', ' ') . ' K';
        return $s . number_format($a, 0, '.', ' ');
    }

    public static function full(?float $v): string
    {
        return $v === null ? '—' : number_format($v, 0, '.', ' ');
    }

    /** "▲ 12 %" / "▼ 3.0 d" vs previous, coloured by whether it is better. */
    public static function delta($cur, $prev, string $unit, ?string $better): array
    {
        if ($cur === null || $prev === null) return ['text' => '', 'class' => ''];
        $d = (float) $cur - (float) $prev;
        if (abs($d) < 1e-9) return ['text' => '= prev', 'class' => 'text-[#8a8578]'];
        $txt = in_array($unit, ['pct'], true) ? number_format(abs($d), 1) . ' pts'
            : ((float) $prev != 0.0 && in_array($unit, ['int', 'xaf'], true) ? number_format(abs($d) / abs((float) $prev) * 100, 0) . ' %'
            : self::v(abs($d), $unit));
        $good = $better === null ? null : (($better === 'up') === ($d > 0));
        return ['text' => ($d > 0 ? '▲ ' : '▼ ') . $txt, 'class' => $good === null ? 'text-[#7c7466]' : ($good ? 'text-emerald-700' : 'text-rose-700')];
    }

    public static function ragStyle(?string $rag): array
    {
        return match ($rag) {
            'green' => ['bar' => 'bg-emerald-500', 'chip' => 'bg-emerald-50 text-emerald-800', 'word' => 'On target'],
            'amber' => ['bar' => 'bg-amber-500', 'chip' => 'bg-amber-50 text-amber-800', 'word' => 'Watch'],
            'red'   => ['bar' => 'bg-rose-600', 'chip' => 'bg-rose-50 text-rose-800', 'word' => 'Act'],
            default => ['bar' => 'bg-slate-200', 'chip' => 'bg-slate-50 text-slate-600', 'word' => ''],
        };
    }

    public static function d(?string $date): string
    {
        return $date ? (new \DateTimeImmutable(substr($date, 0, 10)))->format('d/m/Y') : '—';
    }
}
