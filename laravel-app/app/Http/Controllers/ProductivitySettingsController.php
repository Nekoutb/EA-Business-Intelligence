<?php

namespace App\Http\Controllers;

use App\Services\ProductivityOverrides;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Human Resources › Productivity settings — manual monthly employee count
 * and number of files (optionally by file nature). Everyone with the
 * module sees the figures; only the platform administrator (super admin)
 * can change them — the form is read-only for everyone else and the save
 * endpoint refuses them.
 */
class ProductivitySettingsController extends Controller
{
    private function canEdit(): bool
    {
        return (bool) optional(auth()->user())->isSuperAdmin();
    }

    public function show(Request $request)
    {
        $tenant = tenant();
        $thisYear = (int) date('Y');
        $year = max(2018, min($thisYear + 1, (int) $request->query('year', $thisYear)));
        return view('hr-productivity-settings', [
            'year'    => $year,
            'rows'    => ProductivityOverrides::rows((int) $tenant->id, $year),
            'natures' => ProductivityOverrides::natures(),
            'manual'  => ProductivityOverrides::enabled(),
            'canEdit' => $this->canEdit(),
            'active'  => 'hr_productivity_settings',
        ]);
    }

    public function save(Request $request)
    {
        abort_unless($this->canEdit(), 403, 'Only the administrator can change productivity settings.');
        $tenant = tenant();
        $year = (int) $request->input('year', date('Y'));
        abort_if($year < 2018 || $year > (int) date('Y') + 1, 422);

        $natures = array_values(array_unique(array_filter(array_map(
            fn ($s) => mb_substr(trim(str_replace(',', ' ', (string) $s)), 0, 40),
            explode(',', (string) $request->input('natures', ''))
        ), fn ($s) => $s !== '')));

        $setting = fn (string $key, string $value) => DB::table('tenant_settings')->updateOrInsert(
            ['tenant_id' => $tenant->id, 'key' => $key], ['value' => $value, 'updated_at' => now(), 'created_at' => now()]);
        $setting('PRODUCTIVITY_MANUAL', $request->boolean('manual') ? '1' : '0');
        $setting('PRODUCTIVITY_FILE_NATURES', implode(', ', $natures));

        $int = fn ($v) => ($v === null || trim((string) $v) === '') ? null : max(0, min(1_000_000, (int) $v));
        foreach (range(1, 12) as $m) {
            $in  = (array) $request->input("m.{$m}", []);
            $emp = $int($in['employees'] ?? null);
            $fil = $int($in['files'] ?? null);
            $nat = [];
            foreach ($natures as $i => $name) {
                $v = $int($in['nat'][$i] ?? null);
                if ($v !== null) $nat[$name] = $v;
            }
            $key = ['tenant_id' => $tenant->id, 'year' => $year, 'month' => $m];
            if ($emp === null && $fil === null && !$nat) {
                DB::table('tenant_productivity_months')->where($key)->delete();
                continue;
            }
            DB::table('tenant_productivity_months')->updateOrInsert($key, [
                'employees' => $emp, 'files' => $fil, 'natures' => $nat ? json_encode($nat, JSON_UNESCAPED_UNICODE) : null,
                'updated_by' => auth()->id(), 'updated_at' => now(), 'created_at' => now(),
            ]);
        }

        session()->flash('flash', ['type' => 'success', 'msg' => 'Productivity settings saved for ' . $year . '.']);
        return redirect(tenant_url('/hr/productivity-settings?year=' . $year));
    }
}
