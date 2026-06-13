<?php
/**
 * ONE-TIME maintenance utility — clears the stale compiled route cache that
 * survives git auto-deploys on this host. Plain PHP in public/ so Apache
 * serves it directly (.htaccess `!-f`), bypassing Laravel's stale router.
 * Token-guarded. REMOVE this file after use.
 */

header('Content-Type: application/json');

$TOKEN = 'k7Qz9_R2mVtX4pLs8wNcB3hJ6yD0fAeU';
if (!hash_equals($TOKEN, (string) ($_GET['t'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$base     = dirname(__DIR__);
$cacheDir = $base . '/bootstrap/cache';

function describe(string $path): array
{
    if (!file_exists($path)) return ['exists' => false];
    $s = @stat($path);
    return [
        'exists'     => true,
        'is_dir'     => is_dir($path),
        'writable'   => is_writable($path),
        'perms'      => $s ? substr(sprintf('%o', $s['mode']), -4) : null,
        'owner_uid'  => $s['uid'] ?? null,
        'owner_name' => (function_exists('posix_getpwuid') && isset($s['uid']))
                        ? (posix_getpwuid($s['uid'])['name'] ?? null) : null,
        'size'       => $s['size'] ?? null,
    ];
}

$out = [
    'served_base'    => $base,
    'cache_dir'      => $cacheDir,
    'php_version'    => PHP_VERSION,
    'process_user'   => function_exists('posix_geteuid')
                        ? (posix_getpwuid(posix_geteuid())['name'] ?? posix_geteuid())
                        : (getenv('USER') ?: 'unknown'),
    'cache_dir_info' => describe($cacheDir),
    'attempts'       => [],
    'deleted'        => [],
    'opcache'        => null,
];

// Is the cache dir itself writable by the web process? (unlink needs dir-write)
$probe = $cacheDir . '/.__wtest';
$out['cache_dir_writable_test'] = (@file_put_contents($probe, 'x') !== false) ? 'writable' : 'not_writable';
@unlink($probe);

// Try to remove each compiled cache. Report exactly why if it fails.
foreach (['routes-v7.php', 'config.php', 'packages.php', 'services.php', 'events.scanned.php'] as $name) {
    $path = $cacheDir . '/' . $name;
    if (!file_exists($path)) continue;
    $rec = ['file' => $name, 'info' => describe($path)];
    error_clear_last();
    if (@unlink($path)) {
        $rec['unlink'] = 'ok';
        $out['deleted'][] = $name;
    } else {
        $e = error_get_last();
        $rec['unlink'] = 'FAILED: ' . ($e['message'] ?? 'unknown');
    }
    $out['attempts'][] = $rec;
}

if (function_exists('opcache_reset')) {
    $out['opcache'] = @opcache_reset() ? 'reset' : 'reset_failed_or_disabled';
} else {
    $out['opcache'] = 'not_available';
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
