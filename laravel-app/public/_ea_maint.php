<?php
/**
 * ONE-TIME maintenance utility — clears stale compiled caches that survive
 * git auto-deploys on this host (the deploy pulls code but never runs
 * `php artisan optimize:clear`, so an old bootstrap/cache/routes-v7.php keeps
 * freezing the route table and every NEW route 404s).
 *
 * Runs as a PLAIN PHP file in public/ — Apache serves it directly (see
 * .htaccess `!-f`), so it bypasses Laravel's router and works even when the
 * route cache is stale. Token-guarded. REMOVE this file after use.
 */

header('Content-Type: application/json');

// Opaque guard so this can't be triggered by random traffic.
$TOKEN = 'k7Qz9_R2mVtX4pLs8wNcB3hJ6yD0fAeU';
if (!hash_equals($TOKEN, (string) ($_GET['t'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$base     = dirname(__DIR__);                 // resolves to the REAL served app dir
$cacheDir = $base . '/bootstrap/cache';

$out = [
    'served_public'    => __DIR__,
    'served_base'      => $base,
    'served_base_real' => realpath($base) ?: null,
    'cache_dir'        => $cacheDir,
    'cache_dir_real'   => realpath($cacheDir) ?: null,
    'php_version'      => PHP_VERSION,
    'before'           => [],
    'deleted'          => [],
    'after'            => [],
    'opcache'          => null,
];

// Snapshot what's actually in the live copy's bootstrap/cache.
foreach ((array) (@scandir($cacheDir) ?: []) as $f) {
    if ($f === '.' || $f === '..') continue;
    $out['before'][$f] = @filesize($cacheDir . '/' . $f);
}

// Delete every compiled *.php cache (route/config/services/packages/events).
// All of these regenerate automatically on the next request — no data loss.
foreach ((array) (glob($cacheDir . '/*.php') ?: []) as $file) {
    if (@unlink($file)) {
        $out['deleted'][] = basename($file);
    }
}

// Reset the in-memory opcode cache from inside the ACTUAL serving process —
// this clears any bytecode/stat for a routes-v7.php that was already deleted
// on disk but still held in memory.
if (function_exists('opcache_reset')) {
    $out['opcache'] = @opcache_reset() ? 'reset' : 'reset_failed_or_disabled';
} else {
    $out['opcache'] = 'not_available';
}

foreach ((array) (@scandir($cacheDir) ?: []) as $f) {
    if ($f === '.' || $f === '..') continue;
    $out['after'][] = $f;
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
