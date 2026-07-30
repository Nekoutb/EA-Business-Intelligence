<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Support\CacheFreshness;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * Async-data worker: sweeps every Odoo-heavy page (config/warm.php) for every
 * real tenant, recomputing each page's ea_cached() datasets from the CLI —
 * where Cloudflare's ~100s ceiling does not apply — so web requests only ever
 * serve pre-computed data and render fast, with an honest "Data as of HH:MM"
 * stamp in the topbar.
 *
 * Scheduled every 3 minutes (routes/console.php). Guarded by a cache lock so
 * a long sweep just makes the next tick skip — freshness degrades gracefully
 * instead of runs stacking up on a small server.
 *
 * Every page compute is timed and appended to storage/logs/warm-metrics.jsonl;
 * `bms:warm --report` turns that log into per-tenant/per-page averages (the
 * before/after evidence: the worker's compute time is what a visitor USED to
 * wait; the page now serves from cache).
 *
 * Manual: php artisan bms:warm [--tenant=slug] [--only=label] [--report]
 */
class WarmPages extends Command
{
    protected $signature = 'bms:warm
                            {--tenant= : Only this tenant slug}
                            {--only=   : Only pages whose label contains this string}
                            {--report  : Print per-tenant/page timing averages from the metrics log}';

    protected $description = 'Pre-compute every heavy page per tenant so web requests serve cached data (async-data worker)';

    public function handle(): int
    {
        if ($this->option('report')) {
            return $this->report();
        }

        if (!config('warm.enabled', true)) {
            $this->warn('warm.enabled=false — skipped.');
            return self::SUCCESS;
        }

        // Heavy tenants pull big row sets; don't let the CLI default limit
        // kill the sweep mid-page (a dead sweep used to wedge the lock).
        @ini_set('memory_limit', '1536M');

        // If PHP dies fatally (OOM, timeout), leave a fingerprint of what it
        // was doing — otherwise a crashed sweep is indistinguishable from a
        // hung one.
        register_shutdown_function(function () {
            $e = error_get_last();
            if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                @file_put_contents(self::metricsPath(), json_encode([
                    'ts' => date('c'), 'tenant' => '-', 'page' => 'FATAL', 'ms' => 0, 'ok' => false,
                    'note' => mb_substr($e['message'], 0, 150) . ' @ ' . basename($e['file']) . ':' . $e['line'],
                ]) . PHP_EOL, FILE_APPEND | LOCK_EX);
            }
        });

        // One sweep at a time, across scheduler AND crontab invocations.
        // 20-min TTL: long enough for a slow sweep tick-to-tick, short enough
        // that a crashed sweep self-heals instead of wedging the worker.
        $lock = Cache::lock('bms:warm:lock', 1200);
        if (!$lock->get()) {
            $this->warn('Previous warm sweep still running — skipped this tick.');
            return self::SUCCESS;
        }

        $t0 = microtime(true);
        $ok = 0; $err = 0; $skipped = 0;

        try {
            $query = Tenant::real();
            if (defined(Tenant::class . '::STATUS_SUSPENDED')) {
                $query->where('status', '!=', Tenant::STATUS_SUSPENDED);
            }
            if (($only = trim((string) $this->option('tenant'))) !== '') {
                $query->where('slug', $only);
            }
            $tenants = $query->orderBy('id')->get();
            $pages   = (array) config('warm.pages', []);
            $filter  = trim((string) $this->option('only'));

            foreach ($tenants as $tenant) {
                app(TenantContext::class)->set($tenant);
                $user = User::query()
                    ->where('tenant_id', $tenant->id)
                    ->when(defined(User::class . '::STATUS_ACTIVE'), fn ($q) => $q->where('status', User::STATUS_ACTIVE))
                    ->orderBy('id')->first();

                foreach ($pages as $page) {
                    $label = (string) ($page['label'] ?? '?');
                    if ($filter !== '' && !str_contains($label, $filter)) continue;

                    $module = $page['module'] ?? null;
                    if ($module !== null && method_exists($tenant, 'moduleEnabled') && !$tenant->moduleEnabled($module)) {
                        $skipped++;
                        continue;
                    }

                    // Begin-marker BEFORE the page: if the process dies mid-
                    // page, the log shows exactly where. (--report skips these.)
                    $this->metric($tenant->slug, $label, 0, true, 'begin');

                    [$good, $ms, $note] = $this->warmPage($tenant, $user, $page);
                    $good ? $ok++ : $err++;
                    $this->metric($tenant->slug, $label, $ms, $good, $note);

                    // A sweep on the heavy tenant outlives the lock TTL —
                    // push the expiry out after every page so a second sweep
                    // never piles on mid-run.
                    $this->renewLock();
                    $this->line(sprintf('  %s %-22s %-18s %7.1fs %s',
                        $good ? '✓' : '✗', $tenant->slug, $label, $ms / 1000, $note ?? ''));
                }
            }
        } finally {
            CacheFreshness::force(false);
            $lock->release();
        }

        $this->info(sprintf('Sweep done in %.1fs · ok=%d · err=%d · skipped(module off)=%d',
            microtime(true) - $t0, $ok, $err, $skipped));
        return self::SUCCESS;
    }

    /**
     * Recompute one page exactly the way a web hit would: bind a request,
     * start a session, authenticate a tenant user, run the controller, and
     * render the view — with the force switch on so every ea_cached() in the
     * path recomputes and restamps.
     *
     * @return array{0: bool, 1: float, 2: ?string} [ok, milliseconds, note]
     */
    private function warmPage(Tenant $tenant, ?User $user, array $page): array
    {
        $t0 = microtime(true);
        CacheFreshness::reset();

        try {
            $request = Request::create('/t/' . $tenant->slug . '/' . ($page['label'] ?? ''), 'GET', (array) ($page['query'] ?? []));
            app()->instance('request', $request);
            try {
                $session = app('session')->driver();
                $session->start();
                $request->setLaravelSession($session);
            } catch (\Throwable $e) { /* session is a nicety here, not a need */ }
            try {
                Auth::forgetGuards();
                if ($user) Auth::login($user);
            } catch (\Throwable $e) { /* some pages render fine unauthenticated */ }

            CacheFreshness::force(true);
            $controller = app((string) $page['controller']);
            $response   = app()->call([$controller, 'index']);

            // Render best-effort: the datasets are cached by the controller
            // run itself; a render hiccup must not fail the warm.
            $note = null;
            try {
                if ($response instanceof Renderable) {
                    $response->render();
                } elseif ($response instanceof \Illuminate\Http\RedirectResponse) {
                    $note = 'redirected';
                }
            } catch (\Throwable $e) {
                $note = 'data ok, render: ' . mb_substr($e->getMessage(), 0, 80);
            }

            return [true, (microtime(true) - $t0) * 1000, $note];
        } catch (\Throwable $e) {
            return [false, (microtime(true) - $t0) * 1000, mb_substr($e->getMessage(), 0, 120)];
        } finally {
            CacheFreshness::force(false);
        }
    }

    /**
     * Metrics JSONL path. storage/logs on this host is owned by the deploy
     * user — www-data can APPEND to existing files but cannot CREATE one, so
     * the original path failed silently. Fall back to the system temp dir,
     * which the worker user can always write.
     */
    public static function metricsPath(): string
    {
        $primary = storage_path('logs/warm-metrics.jsonl');
        if (is_file($primary) ? is_writable($primary) : is_writable(dirname($primary))) {
            return $primary;
        }
        return rtrim(sys_get_temp_dir(), '/\\') . '/bms-warm-metrics.jsonl';
    }

    /**
     * Keep the sweep lock alive: the heavy tenant makes a sweep outlast any
     * reasonable TTL, so push the expiry out after each page (database cache
     * driver; harmless no-op elsewhere).
     */
    private function renewLock(): void
    {
        try {
            \Illuminate\Support\Facades\DB::table('cache_locks')
                ->where('key', 'like', '%bms:warm:lock%')
                ->update(['expiration' => time() + 1200]);
        } catch (\Throwable $e) { /* non-database cache store — TTL governs */ }
    }

    /** Append one JSONL metric line (the raw material for --report). */
    private function metric(string $tenant, string $page, float $ms, bool $ok, ?string $note): void
    {
        $line = json_encode([
            'ts'     => date('c'),
            'tenant' => $tenant,
            'page'   => $page,
            'ms'     => (int) round($ms),
            'ok'     => $ok,
            'note'   => $note,
        ], JSON_UNESCAPED_SLASHES);
        @file_put_contents(self::metricsPath(), $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /** Per-tenant/per-page compute-time averages from the metrics log. */
    private function report(): int
    {
        $path = self::metricsPath();
        if (!is_file($path)) {
            $this->warn('No metrics yet (' . $path . '). Run a sweep first: php artisan bms:warm');
            return self::SUCCESS;
        }

        $agg = [];
        $fh = fopen($path, 'r');
        while (($line = fgets($fh)) !== false) {
            $row = json_decode($line, true);
            if (!is_array($row) || empty($row['tenant']) || empty($row['page'])) continue;
            if (($row['note'] ?? null) === 'begin' || $row['page'] === 'FATAL') continue; // markers, not timings
            $k = $row['tenant'] . '|' . $row['page'];
            $agg[$k] ??= ['tenant' => $row['tenant'], 'page' => $row['page'], 'n' => 0, 'sum' => 0, 'max' => 0, 'err' => 0, 'last' => ''];
            $agg[$k]['n']++;
            $agg[$k]['sum'] += (int) ($row['ms'] ?? 0);
            $agg[$k]['max'] = max($agg[$k]['max'], (int) ($row['ms'] ?? 0));
            if (empty($row['ok'])) $agg[$k]['err']++;
            $agg[$k]['last'] = (string) ($row['ts'] ?? '');
        }
        fclose($fh);

        usort($agg, fn ($a, $b) => [$a['tenant'], -($a['sum'] / max(1, $a['n']))] <=> [$b['tenant'], -($b['sum'] / max(1, $b['n']))]);
        $this->table(
            ['Tenant', 'Page', 'Runs', 'Avg compute', 'Max', 'Errors', 'Last run'],
            array_map(fn ($r) => [
                $r['tenant'], $r['page'], $r['n'],
                sprintf('%.1fs', $r['sum'] / max(1, $r['n']) / 1000),
                sprintf('%.1fs', $r['max'] / 1000),
                $r['err'], substr($r['last'], 0, 16),
            ], $agg)
        );
        $this->line('Avg compute = what a visitor used to wait on a cold load; pages now serve this from cache in ~1–3s.');
        return self::SUCCESS;
    }
}
