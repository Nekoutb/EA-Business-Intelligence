<?php
declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Manual monthly productivity figures (HR › Productivity settings).
 *
 * When the tenant setting PRODUCTIVITY_MANUAL is on, a month that carries a
 * manual value replaces the system count for that month, everywhere a
 * number of employees or files is used: HR Productivity, dashboard
 * Revenue/Cost per file, Profitability per file, Revenue streams per file.
 * Files for a month = the entered total, or the sum of the per-nature
 * counts when only natures were entered. Blank months keep the system count.
 */
final class ProductivityOverrides
{
    private static array $memo = [];

    public static function enabled(): bool
    {
        return in_array(strtolower((string) config_env('PRODUCTIVITY_MANUAL', '0')), ['1', 'true', 'on', 'yes'], true);
    }

    /** Nature labels configured for the tenant (ordered). */
    public static function natures(): array
    {
        $raw = (string) config_env('PRODUCTIVITY_FILE_NATURES', '');
        return array_values(array_unique(array_filter(array_map('trim', explode(',', $raw)), fn ($s) => $s !== '')));
    }

    /**
     * Every stored month for the tenant/year, regardless of the toggle.
     * @return array<string, array{employees:?int, files:?int, natures:array<string,int>, files_effective:?int}>  'Y-m' =>
     */
    public static function rows(int $tenantId, int $year): array
    {
        $k = $tenantId . ':' . $year;
        if (isset(self::$memo[$k])) return self::$memo[$k];
        $out = [];
        try {
            foreach (DB::table('tenant_productivity_months')->where('tenant_id', $tenantId)->where('year', $year)->get() as $r) {
                $nat = array_map('intval', array_filter((array) json_decode((string) $r->natures, true), fn ($v) => $v !== null && $v !== ''));
                $files = $r->files !== null ? (int) $r->files : ($nat ? array_sum($nat) : null);
                $out[sprintf('%04d-%02d', $year, $r->month)] = [
                    'employees' => $r->employees !== null ? (int) $r->employees : null,
                    'files' => $r->files !== null ? (int) $r->files : null,
                    'natures' => $nat, 'files_effective' => $files,
                ];
            }
        } catch (\Throwable $e) { /* table missing → no overrides */ }
        return self::$memo[$k] = $out;
    }

    private static function tenantId(): int
    {
        return (int) (optional(tenant())->id ?? 0);
    }

    /** Active overrides for files: 'Y-m' => int (empty when the toggle is off). */
    public static function files(int $year): array
    {
        if (!self::enabled() || !($t = self::tenantId())) return [];
        $out = [];
        foreach (self::rows($t, $year) as $ym => $r) if ($r['files_effective'] !== null) $out[$ym] = $r['files_effective'];
        return $out;
    }

    /** Active overrides for employees: 'Y-m' => int. */
    public static function employees(int $year): array
    {
        if (!self::enabled() || !($t = self::tenantId())) return [];
        $out = [];
        foreach (self::rows($t, $year) as $ym => $r) if ($r['employees'] !== null) $out[$ym] = $r['employees'];
        return $out;
    }

    /** Active per-nature counts: 'Y-m' => [nature => n]. */
    public static function naturesByMonth(int $year): array
    {
        if (!self::enabled() || !($t = self::tenantId())) return [];
        $out = [];
        foreach (self::rows($t, $year) as $ym => $r) if ($r['natures']) $out[$ym] = $r['natures'];
        return $out;
    }

    /** Cache-key fingerprint: changes whenever the toggle or any value changes. */
    public static function fingerprint(): string
    {
        if (!self::enabled() || !($t = self::tenantId())) return 'm0';
        try {
            $last = (string) DB::table('tenant_productivity_months')->where('tenant_id', $t)->max('updated_at');
            $n = (int) DB::table('tenant_productivity_months')->where('tenant_id', $t)->count();
        } catch (\Throwable $e) { return 'm0'; }
        return 'm' . substr(md5($last . '|' . $n . '|' . implode(',', self::natures())), 0, 8);
    }
}
