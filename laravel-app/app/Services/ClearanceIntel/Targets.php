<?php
declare(strict_types=1);

namespace App\Services\ClearanceIntel;

/**
 * KPI targets, alert triggers and file-trigger days: the library's
 * defaults (config/clearance_intel.php) overlaid with what management saved
 * (tenant setting CLRI_TARGETS, JSON {"kpi": {"target": n, "trigger": n},
 * "t:<trigger>": days}).
 */
final class Targets
{
    /** Raw setting, read once per tenant per process (config_env hits the database each call). */
    private static array $raw = [];

    private static function raw(): string
    {
        $tid = optional(tenant())->id ?? 0;
        return self::$raw[$tid] ??= (string) (config_env('CLRI_TARGETS', '') ?? '');
    }

    /** After a save in this process. */
    public static function forget(): void
    {
        self::$raw = [];
    }

    public static function saved(): array
    {
        static $parsed = [];
        $raw = self::raw();
        if (!isset($parsed[$raw])) {
            $j = $raw !== '' ? json_decode($raw, true) : null;
            $parsed[$raw] = is_array($j) ? $j : [];
        }
        return $parsed[$raw];
    }

    public static function fingerprint(): string
    {
        return substr(md5(self::raw()), 0, 8);
    }

    /** @return array{target:?float, trigger:?float} */
    public static function forKpi(string $key): array
    {
        $def = config("clearance_intel.kpis.{$key}");
        $s = self::saved()[$key] ?? [];
        $num = fn ($v) => ($v === null || $v === '') ? null : (float) $v;
        return [
            'target'  => array_key_exists('target', $s) ? $num($s['target']) : $num($def[6] ?? null),
            'trigger' => array_key_exists('trigger', $s) ? $num($s['trigger']) : $num($def[7] ?? null),
        ];
    }

    public static function triggerDays(string $name): int
    {
        $s = self::saved()['t:' . $name] ?? null;
        return $s !== null && $s !== '' ? (int) $s : (int) (config("clearance_intel.triggers.{$name}")[2] ?? 0);
    }

    /** 'green' | 'amber' | 'red' | null (no target, or no value). */
    public static function rag(string $key, ?float $value): ?string
    {
        $def = config("clearance_intel.kpis.{$key}");
        $better = $def[3] ?? null;
        $t = self::forKpi($key);
        if ($value === null || $better === null || $t['target'] === null) return null;
        $trigger = $t['trigger'] ?? $t['target'];
        if ($better === 'up') return $value >= $t['target'] ? 'green' : ($value >= $trigger ? 'amber' : 'red');
        return $value <= $t['target'] ? 'green' : ($value <= $trigger ? 'amber' : 'red');
    }
}
