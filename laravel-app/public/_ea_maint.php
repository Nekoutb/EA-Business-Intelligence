<?php
/**
 * TEMP ops window — token-guarded, read-mostly. Lets the assistant observe the
 * async worker (bms:warm) without server shell access: metrics, logs, the
 * sweep lock, and web opcache state. Two recovery switches (unlock, opcache
 * reset) — both safe and idempotent. Remove/stub when the worker is stable.
 *
 * Ops: ?op=status (default) | metrics | warmlog | deploylog | laravellog
 *      | unlock | opcachereset
 */
header('Content-Type: text/plain; charset=utf-8');
$TOKEN = 'k7Qz9_R2mVtX4pLs8wNcB3hJ6yD0fAeU';
if (!hash_equals($TOKEN, (string) ($_GET['t'] ?? ''))) { http_response_code(403); echo 'forbidden'; exit; }

// Diagnostics window: surface fatals instead of a blank 500 body, rule
// memory limits in/out, and never die mid-op on the web time limit.
ini_set('display_errors', '1');
error_reporting(E_ALL);
@ini_set('memory_limit', '1024M');
@set_time_limit(280);
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        echo "\nFATAL: {$e['message']} @ {$e['file']}:{$e['line']}\n";
    }
});

$base = dirname(__DIR__);
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();

function ln($s = '') { echo $s . "\n"; }
function tailFile(string $path, int $lines = 60): string
{
    if (!is_file($path)) return "(missing: {$path})";
    if (!is_readable($path)) return "(unreadable: {$path})";
    $size = filesize($path);
    $chunk = min($size, 64 * 1024);
    $fh = fopen($path, 'r');
    fseek($fh, -$chunk, SEEK_END);
    $data = (string) fread($fh, $chunk);
    fclose($fh);
    $rows = explode("\n", rtrim($data, "\n"));
    return implode("\n", array_slice($rows, -$lines));
}

$op = (string) ($_GET['op'] ?? 'status');
$L = max(10, min(2000, (int) ($_GET['lines'] ?? 60)));
try {
    switch ($op) {
        case 'metrics':
            foreach ([storage_path('logs/warm-metrics.jsonl'), rtrim(sys_get_temp_dir(), '/\\') . '/bms-warm-metrics.jsonl'] as $mp) {
                ln("== {$mp} (tail {$L}) ==");
                ln(tailFile($mp, $L));
                ln();
            }
            break;

        case 'warmlog':
            ln("== /var/log/bms-warm.log (tail {$L}) ==");
            ln(tailFile('/var/log/bms-warm.log', $L));
            break;

        case 'deploylog':
            ln("== /var/log/bms-deploy.log (tail {$L}) ==");
            ln(tailFile('/var/log/bms-deploy.log', $L));
            break;

        case 'laravellog':
            ln("== storage/logs/laravel.log (tail {$L}) ==");
            ln(tailFile(storage_path('logs/laravel.log'), $L));
            break;

        case 'recompile':
            // Force-recompile EVERY Blade template in place. Overwrite is
            // permitted (compiled files are web-user-owned); this repairs a
            // half-written/corrupted compiled view after a compile race.
            $compiler = app('blade.compiler');
            $ok = 0; $bad = [];
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base . '/resources/views', FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (!str_ends_with($f->getFilename(), '.blade.php')) continue;
                try { $compiler->compile($f->getPathname()); $ok++; }
                catch (\Throwable $e) { $bad[] = $f->getFilename() . ': ' . mb_substr($e->getMessage(), 0, 90); }
            }
            if (function_exists('opcache_reset')) @opcache_reset();
            ln("recompiled {$ok} templates · failures: " . count($bad));
            foreach (array_slice($bad, 0, 40) as $b) ln('  ✗ ' . $b);
            break;

        case 'viewfile':
            // Read a compiled view (hash-constrained, read-only) or a source
            // view by relative path — the ground truth when a compiled file
            // is suspected stale or corrupted. ?hash=<md5> or ?src=<rel>
            // with optional &from=&to= line range.
            $from = max(1, (int) ($_GET['from'] ?? 1));
            $to   = max($from, min($from + 400, (int) ($_GET['to'] ?? ($from + 60))));
            if (($h = preg_replace('/[^a-f0-9]/', '', (string) ($_GET['hash'] ?? ''))) !== '') {
                $p = $base . '/storage/framework/views/' . $h . '.php';
            } else {
                $rel = str_replace(['..', '\\'], '', (string) ($_GET['src'] ?? 'layouts/app.blade.php'));
                $p = $base . '/resources/views/' . ltrim($rel, '/');
            }
            if (!is_file($p)) { ln("missing: {$p}"); break; }
            ln("== {$p} (lines {$from}-{$to}) · mtime " . date('c', filemtime($p)) . ' · ' . filesize($p) . " bytes ==");
            $i = 0;
            $fh = fopen($p, 'r');
            while (($row = fgets($fh)) !== false) {
                $i++;
                if ($i < $from) continue;
                if ($i > $to) break;
                ln(sprintf('%5d| %s', $i, rtrim($row, "\n")));
            }
            fclose($fh);
            break;

        case 'render':
            // Reproduce a page render exactly like a logged-in visitor and
            // print the true exception if it fails. ?tenant=slug&page=label
            $slug = (string) ($_GET['tenant'] ?? 'kwr');
            $want = (string) ($_GET['page'] ?? 'dashboard');
            $tenant = App\Models\Tenant::where('slug', $slug)->first();
            if (!$tenant) { ln("no tenant '{$slug}'"); break; }
            app(App\Tenancy\TenantContext::class)->set($tenant);
            $def = null;
            foreach ((array) config('warm.pages', []) as $p) {
                if (($p['label'] ?? '') === $want) { $def = $p; break; }
            }
            if (!$def) { ln("no page '{$want}' in config/warm.php"); break; }
            $req = Illuminate\Http\Request::create('/t/' . $slug, 'GET', (array) ($def['query'] ?? []));
            app()->instance('request', $req);
            try { $s = app('session')->driver(); $s->start(); $req->setLaravelSession($s); } catch (\Throwable $e) {}
            $u = App\Models\User::where('tenant_id', $tenant->id)->orderBy('id')->first();
            if ($u) { try { Illuminate\Support\Facades\Auth::login($u); } catch (\Throwable $e) {} }
            // Put a flash in the session to exercise the toast path too
            // (skip with &noflash=1 to isolate flash-dependent failures).
            if (!isset($_GET['noflash'])) {
                session()->flash('flash', ['type' => 'success', 'msg' => 'render-test']);
            }
            try {
                $resp = app()->call([app((string) $def['controller']), 'index']);
                $html = ($resp instanceof Illuminate\Contracts\Support\Renderable) ? $resp->render() : '(non-view response: ' . get_class($resp) . ')';
                ln("RENDER OK — {$slug}/{$want} — " . strlen((string) $html) . ' bytes');
            } catch (\Throwable $e) {
                ln("RENDER FAILED — {$slug}/{$want}");
                ln('  ' . get_class($e) . ': ' . $e->getMessage());
                ln('  @ ' . $e->getFile() . ':' . $e->getLine());
                foreach (array_slice(explode("\n", $e->getTraceAsString()), 0, 4) as $tline) ln('  ' . $tline);
            }
            break;

        case 'collcheck':
            // Collections-by-stream verification: run the service fresh and
            // print every stream + the tie-out against the recon-style
            // Collected (HT) total, plus unmatched accounts for pattern
            // tuning. Read-only. ?tenant=slug (default transatlanticd)
            $slug = (string) ($_GET['tenant'] ?? 'transatlanticd');
            $tenant = App\Models\Tenant::where('slug', $slug)->first();
            if (!$tenant) { ln("no tenant '{$slug}'"); break; }
            app(App\Tenancy\TenantContext::class)->set($tenant);
            $client = App\Odoo\OdooFactory::fromConfig();
            $t0 = microtime(true);
            $svc = new App\Services\CollectionsByStreamService($client, new App\Services\GLRevenueService($client));
            $c = $svc->analyze();
            ln(sprintf('analyze: %.1fs · invoices YTD=%d · partial YTD=%d · as of %s',
                microtime(true) - $t0, $c['meta']['invoices_ytd'], $c['meta']['partials_ytd'], $c['as_of']));
            ln(sprintf('%-40s %14s %16s', 'stream', 'MTD', 'YTD'));
            foreach ($c['streams'] as $s) {
                ln(sprintf('%-40s %14s %16s', mb_substr($s['label'], 0, 40), number_format($s['mtd']), number_format($s['ytd'])));
            }
            ln(sprintf('%-40s %14s %16s', 'UNMATCHED 70x (' . count($c['unmatched']['accounts']) . ' accts)',
                number_format($c['unmatched']['mtd']), number_format($c['unmatched']['ytd'])));
            foreach (array_slice($c['unmatched']['accounts'], 0, 10) as $ua) ln('    · ' . $ua);
            ln(sprintf('%-40s %14s %16s', 'Other income (non-70x)',
                number_format($c['other_income']['mtd']), number_format($c['other_income']['ytd'])));
            ln(sprintf('%-40s %14s %16s', 'TOTAL = recon Collected (HT)',
                number_format($c['recon']['mtd']), number_format($c['recon']['ytd'])));
            ln('tie-out: streams+unmatched+other − recon = '
                . number_format(($c['totals']['mtd'] + $c['other_income']['mtd']) - $c['recon']['mtd'], 2) . ' MTD · '
                . number_format(($c['totals']['ytd'] + $c['other_income']['ytd']) - $c['recon']['ytd'], 2) . ' YTD (expect 0.00)');
            break;

        case 'unlock':
            $n = Illuminate\Support\Facades\DB::table('cache_locks')
                ->where('key', 'like', '%bms:warm:lock%')->delete();
            ln("released sweep lock rows: {$n}");
            break;

        case 'opcachereset':
            if (function_exists('opcache_reset')) {
                ln('opcache_reset: ' . json_encode(opcache_reset()));
            } else {
                ln('opcache not available in web SAPI');
            }
            break;

        default: // status
            ln('== ops status @ ' . date('c') . ' ==');
            ln('php (web sapi): ' . PHP_VERSION . ' / ' . PHP_SAPI);
            foreach (['app/Support/helpers.php', 'config/warm.php', 'app/Console/Commands/WarmPages.php', 'config/bi.php'] as $f) {
                $p = $base . '/' . $f;
                ln(sprintf('  %-38s mtime %s', $f, is_file($p) ? date('c', filemtime($p)) : 'MISSING'));
            }
            ln('  bi.cache_ttl (web sees) = ' . var_export(config('bi.cache_ttl'), true)
                . ' · ea_data_timestamp() exists = ' . json_encode(function_exists('ea_data_timestamp')));
            if (function_exists('opcache_get_status') && ($st = @opcache_get_status(false))) {
                $cfg = function_exists('opcache_get_configuration') ? (array) @opcache_get_configuration() : [];
                $dirs = (array) ($cfg['directives'] ?? []);
                ln('  opcache: enabled=' . json_encode($st['opcache_enabled'] ?? null)
                    . ' validate_timestamps=' . json_encode($dirs['opcache.validate_timestamps'] ?? null)
                    . ' revalidate_freq=' . json_encode($dirs['opcache.revalidate_freq'] ?? null));
            } else {
                ln('  opcache: not active');
            }
            ln('  storage/logs writable by web user: ' . json_encode(is_writable(storage_path('logs')))
                . ' · tmp: ' . json_encode(is_writable(sys_get_temp_dir())));
            $found = false;
            foreach ([storage_path('logs/warm-metrics.jsonl'), rtrim(sys_get_temp_dir(), '/\\') . '/bms-warm-metrics.jsonl'] as $mf) {
                if (!is_file($mf)) continue;
                $found = true;
                $cnt = 0; $fh = fopen($mf, 'r');
                while (fgets($fh) !== false) $cnt++;
                fclose($fh);
                ln("  metrics {$mf}: {$cnt} lines · last: " . tailFile($mf, 1));
            }
            if (!$found) ln('  metrics: NO FILE in either location — no page has completed since the metrics fix deployed');
            // Is a sweep process holding the cron-level flock right now?
            $fh = @fopen('/tmp/bms-warm.lock', 'c');
            if ($fh) {
                $free = flock($fh, LOCK_EX | LOCK_NB);
                if ($free) flock($fh, LOCK_UN);
                fclose($fh);
                ln('  cron flock /tmp/bms-warm.lock: ' . ($free ? 'free' : 'HELD — a sweep process is alive right now'));
            }
            try {
                $rows = Illuminate\Support\Facades\DB::table('cache_locks')
                    ->where('key', 'like', '%bms:warm:lock%')->get();
                if ($rows->isEmpty()) {
                    ln('  sweep lock: free');
                } else {
                    foreach ($rows as $r) {
                        $left = (int) $r->expiration - time();
                        ln("  sweep lock: HELD (owner {$r->owner}, expires in {$left}s)"
                            . ($left > 0 ? ' — a live or crashed sweep' : ' — EXPIRED ROW'));
                    }
                }
            } catch (\Throwable $e) {
                ln('  sweep lock: check failed — ' . $e->getMessage());
            }
            ln('  warm log tail: ' . str_replace("\n", ' | ', tailFile('/var/log/bms-warm.log', 3)));
    }
} catch (\Throwable $e) {
    ln('EXC: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}
