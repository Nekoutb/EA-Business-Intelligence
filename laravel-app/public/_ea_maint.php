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
try {
    switch ($op) {
        case 'metrics':
            ln('== warm-metrics.jsonl (tail 80) ==');
            ln(tailFile(storage_path('logs/warm-metrics.jsonl'), 80));
            break;

        case 'warmlog':
            ln('== /var/log/bms-warm.log (tail 80) ==');
            ln(tailFile('/var/log/bms-warm.log', 80));
            break;

        case 'deploylog':
            ln('== /var/log/bms-deploy.log (tail 40) ==');
            ln(tailFile('/var/log/bms-deploy.log', 40));
            break;

        case 'laravellog':
            ln('== storage/logs/laravel.log (tail 60) ==');
            ln(tailFile(storage_path('logs/laravel.log'), 60));
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
            $mf = storage_path('logs/warm-metrics.jsonl');
            if (is_file($mf)) {
                $cnt = 0; $fh = fopen($mf, 'r');
                while (fgets($fh) !== false) $cnt++;
                fclose($fh);
                ln("  metrics: {$cnt} lines · last: " . tailFile($mf, 1));
            } else {
                ln('  metrics: NO FILE — the worker has never completed (or written) a page');
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
