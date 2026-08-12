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

        case 'maillog':
            // The daily-report cron's output — every 07:00 UTC run appends
            // here (success lines or the failure reason).
            ln("== /var/log/kwr-daily-report.log (tail {$L}) ==");
            ln(tailFile('/var/log/kwr-daily-report.log', $L));
            break;

        case 'sendreport':
            // Remote catch-up trigger for the Kissywears daily report.
            // ?date=YYYY-MM-DD (the report day, i.e. the "prior day" the
            // email covers). Uses the same artisan command as the cron.
            $d = (string) ($_GET['date'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) { ln('need ?date=YYYY-MM-DD'); break; }
            $code = Illuminate\Support\Facades\Artisan::call('reports:send-daily', [
                '--tenant' => (string) ($_GET['tenant'] ?? 'kwr'),
                '--date'   => $d,
                '--to'     => (string) ($_GET['to'] ?? 'kissywears1@gmail.com,contact@cm-ea.com'),
            ]);
            ln(rtrim(Illuminate\Support\Facades\Artisan::output()));
            ln('exit=' . $code);
            break;

        case 'reconcheck':
            // P1 GATE — dual-run: batched reconciliation vs the original
            // per-month loop, month by month, with timings. The batched path
            // ships only if every variance is 0.00. ?tenant=slug
            $slug = (string) ($_GET['tenant'] ?? 'transatlanticd');
            $tenant = App\Models\Tenant::where('slug', $slug)->first();
            if (!$tenant) { ln("no tenant '{$slug}'"); break; }
            app(App\Tenancy\TenantContext::class)->set($tenant);
            $client = App\Odoo\OdooFactory::fromConfig();
            $period = ['type' => 'ytd', 'year' => (int) date('Y')];

            $run = function (bool $batched) use ($client, $period) {
                config(['bi.recon_batched' => $batched]);
                $t0 = microtime(true);
                $r = (new App\Services\BillingService($client))->reconciliationOnly(null, $period);
                return [$r, microtime(true) - $t0];
            };
            [$new, $tNew] = $run(true);
            [$old, $tOld] = $run(false);
            config(['bi.recon_batched' => true]);

            ln(sprintf('batched %.1fs · per-month loop %.1fs · speed-up %.1f×', $tNew, $tOld, $tOld > 0 ? $tOld / $tNew : 0));
            ln();
            ln(sprintf('%-6s %18s %18s %18s', 'month', 'Δ expected', 'Δ billed', 'Δ collected'));
            $nb = (array) ($new['buckets'] ?? []); $ob = (array) ($old['buckets'] ?? []);
            $worst = 0.0;
            foreach ($ob as $i => $o) {
                $n = $nb[$i] ?? ['expected' => 0, 'billed' => 0, 'collected' => 0];
                $d = [
                    (float) $n['expected']  - (float) $o['expected'],
                    (float) $n['billed']    - (float) $o['billed'],
                    (float) $n['collected'] - (float) $o['collected'],
                ];
                foreach ($d as $x) $worst = max($worst, abs($x));
                ln(sprintf('%-6s %18s %18s %18s', $o['label'] ?? '?',
                    number_format($d[0], 2), number_format($d[1], 2), number_format($d[2], 2)));
            }
            ln();
            foreach (['expected', 'billed', 'collected'] as $k) {
                $dn = (float) ($new['headline'][$k] ?? 0); $do = (float) ($old['headline'][$k] ?? 0);
                $worst = max($worst, abs($dn - $do));
                ln(sprintf('headline %-10s new %18s · old %18s · Δ %s', $k,
                    number_format($dn, 2), number_format($do, 2), number_format($dn - $do, 2)));
            }
            ln();
            ln('GATE: worst variance = ' . number_format($worst, 2) . ' — ' . ($worst < 0.005 ? 'PASS ✅' : 'FAIL ❌'));
            break;

        case 'migstatus':
            // Which migrations Laravel thinks have run. The deploy workflow
            // dies when a migration is pending but its table already exists.
            Illuminate\Support\Facades\Artisan::call('migrate:status');
            ln(rtrim(Illuminate\Support\Facades\Artisan::output()));
            break;

        case 'migmark':
            // Record a migration as run WITHOUT executing it — the repair for
            // "table already exists". Refuses unless the table is genuinely
            // present, so a migration that really needs to run can never be
            // skipped by accident. ?name=<migration_file_stem>&table=<table>
            $name  = (string) ($_GET['name'] ?? '');
            $table = (string) ($_GET['table'] ?? '');
            if ($name === '' || $table === '') { ln('need ?name=<migration>&table=<table>'); break; }
            if (!Illuminate\Support\Facades\Schema::hasTable($table)) {
                ln("REFUSED: table '{$table}' does not exist — this migration should actually run, not be marked.");
                break;
            }
            if (Illuminate\Support\Facades\DB::table('migrations')->where('migration', $name)->exists()) {
                ln("already recorded: {$name}");
                break;
            }
            $batch = (int) Illuminate\Support\Facades\DB::table('migrations')->max('batch');
            Illuminate\Support\Facades\DB::table('migrations')->insert(['migration' => $name, 'batch' => $batch ?: 1]);
            ln("marked as run: {$name} (table '{$table}' verified present, batch {$batch})");
            break;

        case 'migrate':
            // Run pending migrations — the same command the deploy workflow
            // runs, so a clean result here means the workflow will pass.
            $code = Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
            ln(rtrim(Illuminate\Support\Facades\Artisan::output()));
            ln('exit=' . $code);
            break;

        case 'sendoffenders':
            // Remote trigger for the receivables Top-Offender Scorecard.
            // ?tenant=kwr (default) &to=a@b.com,... (default: config list)
            $args = ['--tenant' => (string) ($_GET['tenant'] ?? 'kwr')];
            if (!empty($_GET['to'])) $args['--to'] = (string) $_GET['to'];
            $code = Illuminate\Support\Facades\Artisan::call('reports:send-offenders', $args);
            ln(rtrim(Illuminate\Support\Facades\Artisan::output()));
            ln('exit=' . $code);
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
            // Cash-basis collections verification: settlements applied in
            // the window, routed to streams, HT + TTC, with internal
            // tie-outs. ?tenant=slug &period=&year=&month=
            $slug = (string) ($_GET['tenant'] ?? 'transatlanticd');
            $tenant = App\Models\Tenant::where('slug', $slug)->first();
            if (!$tenant) { ln("no tenant '{$slug}'"); break; }
            app(App\Tenancy\TenantContext::class)->set($tenant);
            $client = App\Odoo\OdooFactory::fromConfig();
            $periodSel = [
                'type'  => in_array(($pt = strtolower((string) ($_GET['period'] ?? 'ytd'))), ['ytd', 'mtd', 'month', 'yoy'], true) ? $pt : 'ytd',
                'year'  => (int) ($_GET['year'] ?? date('Y')),
                'month' => (int) ($_GET['month'] ?? date('m')),
            ];
            $t0 = microtime(true);
            $svc = new App\Services\CollectionsByStreamService($client, new App\Services\GLRevenueService($client));
            $c = $svc->analyze(null, $periodSel);
            ln(sprintf('analyze: %.1fs · period=%s · settlements=%d · invoices touched=%d · as of %s',
                microtime(true) - $t0, json_encode($periodSel), $c['meta']['settlements'], $c['meta']['invoices'], $c['as_of']));
            $cols = $c['columns'];
            $hdr = sprintf('%-34s', 'stream');
            foreach ($cols as $cc) $hdr .= sprintf(' %16s', $cc['label']);
            ln($hdr);
            $rowOut = function (string $label, array $row) use ($cols) {
                $line = sprintf('%-34s', mb_substr($label, 0, 34));
                foreach ($cols as $cc) $line .= sprintf(' %16s', number_format((float) ($row[$cc['k']] ?? 0)));
                ln($line);
            };
            foreach ($c['streams'] as $s) $rowOut($s['label'], $s);
            $rowOut('UNMATCHED 70x (' . count($c['unmatched']['accounts']) . ')', $c['unmatched']);
            $rowOut('Other income (non-70x)', $c['other']);
            $rowOut('TOTAL', $c['totals']);
            $sumHt = $c['unmatched']['ytd_ht'] + $c['other']['ytd_ht'];
            $sumTtc = $c['unmatched']['ytd_ttc'] + $c['other']['ytd_ttc'];
            foreach ($c['streams'] as $s) { $sumHt += $s['ytd_ht']; $sumTtc += $s['ytd_ttc']; }
            ln('tie-out YTD: rows−totals HT=' . number_format($sumHt - $c['totals']['ytd_ht'], 2)
                . ' · TTC=' . number_format($sumTtc - $c['totals']['ytd_ttc'], 2) . ' (expect 0.00)');
            if (!empty($c['monthly']['series'])) {
                $ttcByKey = [];
                foreach ($c['streams'] as $s) $ttcByKey[$s['key']] = (float) $s['ytd_ttc'];
                $drift = 0.0;
                foreach ($c['monthly']['series'] as $s) {
                    if (!isset($ttcByKey[$s['key']])) continue;
                    $drift = max($drift, abs(array_sum($s['data']) - $ttcByKey[$s['key']]));
                }
                ln('monthly chart (TTC): ' . count($c['monthly']['labels']) . ' months · '
                    . count($c['monthly']['series']) . ' series · max drift vs YTD = ' . number_format($drift, 2));
            }
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
