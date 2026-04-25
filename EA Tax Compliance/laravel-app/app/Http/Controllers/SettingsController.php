<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Settings edit page.  We persist changes to .env so they survive restarts,
 * and also write them to the session so they apply immediately (via
 * config_env() override lookup) without needing a `php artisan config:clear`.
 */
class SettingsController extends Controller
{
    /** Keys exposed in the UI.  Everything else in .env is left untouched. */
    private const FIELDS = [
        'ODOO_URL', 'ODOO_DB', 'ODOO_USERNAME', 'ODOO_API_KEY', 'ODOO_PROTOCOL',
        'REVENUE_SOURCE', 'APP_BRAND',
        'AI_PROVIDER', 'AI_MODEL', 'AI_API_KEY',
        'BUDGET_MONTHLY_REVENUE', 'BUDGET_MONTHLY_OUTFITS', 'BUDGET_MONTHLY_COGS',
        'BUDGET_MONTHLY_DISCOUNTS', 'BUDGET_MONTHLY_CUSTOMERS',
    ];

    public function show()
    {
        $values = [];
        foreach (self::FIELDS as $k) {
            $values[$k] = (string) config_env($k, '');
        }
        return view('settings', ['values' => $values, 'active' => null]);
    }

    public function save(Request $request)
    {
        $overrides = session('bi_overrides', []);
        $envUpdate = [];

        foreach (self::FIELDS as $k) {
            $val = trim((string) $request->input($k, ''));

            // Keep existing secrets if the user left the input blank.
            if (($k === 'ODOO_API_KEY' || $k === 'AI_API_KEY') && $val === '') {
                continue;
            }

            $overrides[$k] = $val;
            $envUpdate[$k] = $val;
        }

        session(['bi_overrides' => $overrides]);
        $this->writeEnv($envUpdate);
        ea_cache_clear();

        return redirect('/settings')->with('flash', [
            'type' => 'success',
            'msg'  => 'Settings saved. Live cache flushed.',
        ]);
    }

    /** Idempotently update keys in the repo .env file. */
    private function writeEnv(array $updates): void
    {
        if (empty($updates)) return;
        $path = base_path('.env');
        if (!is_file($path) || !is_writable($path)) return;

        $content = file_get_contents($path);
        foreach ($updates as $k => $v) {
            $quoted = str_contains($v, ' ') ? '"' . addslashes($v) . '"' : $v;
            $line   = $k . '=' . $quoted;
            if (preg_match("/^{$k}=.*/m", $content)) {
                $content = preg_replace("/^{$k}=.*/m", $line, $content);
            } else {
                $content .= PHP_EOL . $line;
            }
        }
        file_put_contents($path, $content);
    }
}
