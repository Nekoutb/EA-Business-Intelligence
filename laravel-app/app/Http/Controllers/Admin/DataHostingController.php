<?php

namespace App\Http\Controllers\Admin;

use App\Console\Commands\TenantDb;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Tenancy\TenantDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * Admin → client → Data hosting. A browser front end for bms:tenant-db:
 * choose where a client's data lives (EA server, own database on the EA
 * server, or the client's own MySQL/MariaDB server), test it, create the
 * tables, copy the data and switch.
 *
 * Quick steps (save, test, switch back to shared) run in the request.
 * Provision, copy and switch-to-dedicated can take minutes on a large
 * client, so they run as a background artisan process whose output goes
 * to storage/logs/tenant-db-{slug}.log; the page shows that log.
 */
class DataHostingController extends Controller
{
    public function show(Tenant $tenant)
    {
        $c = (array) ($tenant->db_config ?? []);
        unset($c['password']);
        return view('admin.tenants.data-hosting', [
            'tenant'     => $tenant,
            'location'   => TenantDatabase::location($tenant),
            'cfg'        => $c,
            'hasPassword'=> array_key_exists('password', (array) ($tenant->db_config ?? [])),
            'defaultDb'  => TenantDatabase::defaultDatabaseName($tenant),
            'running'    => $this->running($tenant),
            'log'        => $this->logTail($tenant),
            'canLaunch'  => $this->canLaunch(),
            'appDbUser'  => (string) config('database.connections.' . config('database.default') . '.username'),
            'driver'     => (string) config('database.connections.' . config('database.default') . '.driver'),
            'active'     => 'tenants',
        ]);
    }

    /** Polled by the page while a background step runs. */
    public function log(Tenant $tenant)
    {
        return response()->json(['running' => $this->running($tenant), 'log' => $this->logTail($tenant)]);
    }

    public function save(Request $request, Tenant $tenant)
    {
        $v = $request->validate([
            'target'   => 'required|in:ea,client',
            'database' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'host'     => 'nullable|required_if:target,client|string|max:255',
            'port'     => 'nullable|integer|min:1|max:65535',
            'username' => 'nullable|required_if:target,client|string|max:128',
            'password' => 'nullable|string|max:255',
            'ssl_ca'   => 'nullable|string|max:255',
        ]);
        if ($v['target'] === 'client' && empty($v['database'])) {
            return back()->withErrors(['database' => 'Give the database name on the client server.'])->withInput();
        }
        if ($this->running($tenant)) return $this->flash('error', 'A data-hosting step is running; wait for it to finish.');
        // A target that is already live can't be repointed under the client's feet.
        if (TenantDatabase::location($tenant) !== 'shared') {
            return $this->flash('error', 'Switch the client back to the EA shared database before changing where its own database is.');
        }
        $v += ['database' => null, 'host' => null, 'port' => null, 'username' => null, 'password' => null, 'ssl_ca' => null];

        $c = (array) ($tenant->db_config ?? []);
        $c['target'] = $v['target'];
        if ($v['target'] === 'ea') {
            // Own database on the EA server: same server and login as the platform.
            foreach (['host', 'port', 'username', 'password', 'ssl_ca', 'driver'] as $k) unset($c[$k]);
            if (!empty($v['database'])) $c['database'] = $v['database']; else unset($c['database']);
        } else {
            $c['driver']   = 'mysql';
            $c['host']     = trim($v['host']);
            $c['port']     = (int) ($v['port'] ?: 3306);
            $c['database'] = $v['database'];
            $c['username'] = $v['username'];
            if (($v['password'] ?? '') !== '') $c['password'] = $v['password'];
            if (!empty($v['ssl_ca'])) $c['ssl_ca'] = $v['ssl_ca']; else unset($c['ssl_ca']);
        }
        $tenant->db_config = $c;
        $tenant->save();
        AuditLog::record('tenant.data_hosting.configure', ['tenant_id' => $tenant->id, 'target' => $tenant->slug,
            'payload' => ['target' => $c['target'], 'host' => $c['host'] ?? 'EA server', 'database' => $c['database'] ?? TenantDatabase::defaultDatabaseName($tenant)]]);
        return $this->flash('success', 'Saved (connection details encrypted). Next: Test connection.');
    }

    public function check(Tenant $tenant)
    {
        if (empty(((array) $tenant->db_config)['target'])) return $this->flash('error', 'Save a target first.');
        $code = Artisan::call('bms:tenant-db', ['action' => 'check', 'tenant' => $tenant->slug]);
        $this->appendLog($tenant, "== Test connection\n" . Artisan::output());
        return $this->flash($code === 0 ? 'success' : 'error', $code === 0 ? 'Connection OK — details in the log below.' : 'Connection failed — see the log below.');
    }

    public function run(Request $request, Tenant $tenant, string $step)
    {
        $loc = TenantDatabase::location($tenant);
        $target = ((array) $tenant->db_config)['target'] ?? null;
        if (!$target) return $this->flash('error', 'Save a target first.');
        if ($this->running($tenant)) return $this->flash('error', 'A data-hosting step is already running.');

        if ($step === 'switch-back') {
            if ($loc === 'shared') return $this->flash('error', 'The client already uses the EA shared database.');
            if ($request->input('confirm_slug') !== $tenant->slug) return $this->flash('error', 'Type the client slug to confirm.');
            $code = Artisan::call('bms:tenant-db', ['action' => 'switch', 'tenant' => $tenant->slug, '--location' => 'shared', '--force' => true, '--no-interaction' => true]);
            $this->appendLog($tenant, "== Switch back to shared\n" . Artisan::output());
            return $this->flash($code === 0 ? 'success' : 'error', $code === 0 ? "{$tenant->slug} reads the EA shared database again." : 'Switch back failed — see the log.');
        }

        $args = match ($step) {
            'provision' => ['provision'],
            'copy'      => ['copy'],
            'switch'    => ['switch', '--location=' . $target],
            default     => null,
        };
        if (!$args) abort(404);
        if ($step === 'switch') {
            if ($loc !== 'shared') return $this->flash('error', 'Already on a dedicated database.');
            if ($request->input('confirm_slug') !== $tenant->slug) return $this->flash('error', 'Type the client slug to confirm the switch.');
        }
        if (!$this->canLaunch()) {
            return $this->flash('error', 'This server does not allow background processes from the web. Run on the server: php artisan bms:tenant-db ' . implode(' ', $args) . ' ' . $tenant->slug);
        }

        $this->launch($tenant, array_merge([$args[0], $tenant->slug], array_slice($args, 1)));
        AuditLog::record('tenant.data_hosting.start', ['tenant_id' => $tenant->id, 'target' => $tenant->slug, 'payload' => ['step' => $step]]);
        return $this->flash('success', ucfirst($step) . ' started in the background — progress shows in the log below.');
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function running(Tenant $t): bool
    {
        $l = Cache::lock(TenantDb::runLock($t), 5);
        if ($l->get()) { $l->release(); return false; }
        return true;
    }

    private function logPath(Tenant $t): string
    {
        return storage_path('logs/tenant-db-' . $t->slug . '.log');
    }

    private function logTail(Tenant $t, int $lines = 80): string
    {
        $f = $this->logPath($t);
        if (!is_file($f)) return '';
        $all = file($f, FILE_IGNORE_NEW_LINES) ?: [];
        return implode("\n", array_slice($all, -$lines));
    }

    private function appendLog(Tenant $t, string $text): void
    {
        @file_put_contents($this->logPath($t), '[' . now()->format('Y-m-d H:i:s') . "] {$text}\n", FILE_APPEND);
    }

    private function canLaunch(): bool
    {
        if (!function_exists('proc_open')) return false;
        $off = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return !in_array('proc_open', $off, true) && $this->php() !== null;
    }

    private function php(): ?string
    {
        $bin = (string) env('BMS_PHP_BIN', '');
        if ($bin !== '' && is_executable($bin)) return $bin;
        // Under Apache, PHP_BINARY is the web server; look for the CLI.
        foreach ([(new PhpExecutableFinder())->find(false), '/usr/bin/php', '/usr/local/bin/php'] as $p) {
            if ($p && is_executable($p) && !str_contains(basename($p), 'apache') && !str_contains(basename($p), 'httpd') && !str_contains(basename($p), 'fpm')) return $p;
        }
        return null;
    }

    /** Detached artisan run; output appended to the client's log. */
    private function launch(Tenant $t, array $args): void
    {
        $this->appendLog($t, '== ' . implode(' ', $args) . ' (started from the admin page)');
        $cmd = 'cd ' . escapeshellarg(base_path()) . ' && nohup ' . escapeshellarg($this->php()) . ' artisan bms:tenant-db '
             . implode(' ', array_map('escapeshellarg', $args)) . ' --no-interaction >> ' . escapeshellarg($this->logPath($t)) . ' 2>&1 &';
        $p = proc_open(['/bin/sh', '-c', $cmd], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (is_resource($p)) proc_close($p);
    }

    private function flash(string $type, string $msg)
    {
        return back()->with('flash', ['type' => $type, 'msg' => $msg]);
    }
}
