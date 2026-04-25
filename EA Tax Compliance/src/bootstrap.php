<?php
declare(strict_types=1);

use EA\BI\Config;

// Autoloader (PSR-4 for EA\BI\*)
spl_autoload_register(function (string $class): void {
    $prefix = 'EA\\BI\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

Config::load(dirname(__DIR__));

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('eabi_sess');
    session_start();
}

function ea_base_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}

function ea_is_logged_in(): bool
{
    return !empty($_SESSION['user']);
}

function ea_require_login(): void
{
    if (!ea_is_logged_in()) {
        header('Location: /login');
        exit;
    }
}

function ea_render(string $view, array $data = []): void
{
    extract($data, EXTR_SKIP);
    $viewFile = dirname(__DIR__) . '/views/' . $view . '.php';
    $layoutFile = dirname(__DIR__) . '/views/layout.php';
    ob_start();
    require $viewFile;
    $content = ob_get_clean();
    require $layoutFile;
}

function ea_e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function ea_money(float $v, string $currency = 'USD'): string
{
    $sign = $v < 0 ? '-' : '';
    return $sign . $currency . ' ' . number_format(abs($v), 2, '.', ',');
}

/**
 * Compact money: 1.2k / 3.4M / 5.6B so KPI cards never overflow.
 */
function ea_money_short(float $v, string $currency = 'USD'): string
{
    $abs = abs($v);
    $sign = $v < 0 ? '-' : '';
    if ($abs >= 1e9) return $sign . $currency . ' ' . number_format($abs / 1e9, 2) . 'B';
    if ($abs >= 1e6) return $sign . $currency . ' ' . number_format($abs / 1e6, 2) . 'M';
    if ($abs >= 1e4) return $sign . $currency . ' ' . number_format($abs / 1e3, 1) . 'k';
    return $sign . $currency . ' ' . number_format($abs, 2, '.', ',');
}

/**
 * Render a view partial from /views/_partials/<name>.php with the given data.
 */
function ea_render_partial(string $name, array $data = []): void
{
    $file = dirname(__DIR__) . '/views/_partials/' . $name . '.php';
    if (!is_file($file)) {
        echo '<!-- missing partial: ' . htmlspecialchars($name) . ' -->';
        return;
    }
    extract($data, EXTR_SKIP);
    include $file;
}

/**
 * Lightweight in-session cache for Odoo analysis blobs. Users can force a
 * refresh ("Sync now") by POSTing to /sync, which clears all cache keys.
 * Each entry is invalidated after EA_CACHE_TTL seconds as well.
 */
const EA_CACHE_TTL = 300; // 5 minutes

function ea_cached(string $key, callable $producer)
{
    $bucket = $_SESSION['ea_cache'] ?? [];
    $entry  = $bucket[$key] ?? null;
    if ($entry && (time() - (int) $entry['at']) < EA_CACHE_TTL) {
        return $entry['data'];
    }
    $data = $producer();
    $bucket[$key] = ['at' => time(), 'data' => $data];
    $_SESSION['ea_cache'] = $bucket;
    $_SESSION['ea_last_sync'] = time();
    return $data;
}

function ea_cache_clear(): void
{
    unset($_SESSION['ea_cache']);
    $_SESSION['ea_last_sync'] = time();
}

function ea_last_sync_label(): string
{
    $t = $_SESSION['ea_last_sync'] ?? null;
    if (!$t) return 'never synced';
    $diff = time() - (int) $t;
    $rel  = ($diff < 60)    ? 'just now'
          : (($diff < 3600) ? (intdiv($diff, 60)   . ' min ago')
          : (($diff < 86400)? (intdiv($diff, 3600) . ' h ago')
          :                   date('Y-m-d H:i', (int) $t)));
    // Always include the absolute timestamp alongside the relative label.
    return $rel . ' (' . date('Y-m-d H:i:s', (int) $t) . ')';
}

/**
 * Resolve a reporting window from a preset.
 *
 * Supported presets via $period['type']:
 *   - 'ytd'   Year-to-date: Jan 1 → today (default)
 *   - 'mtd'   Month-to-date: 1st of current month → today
 *   - 'month' Full calendar month (year/month params)
 *   - 'yoy'   Alias for YTD (CY vs PY comparison is always applied upstream)
 *
 * @return array{0:\DateTimeImmutable,1:\DateTimeImmutable,2:string,3:string}
 *         [from, to, humanLabel, typeSlug]
 */
function ea_resolve_period(\DateTimeImmutable $today, array $period): array
{
    $type = strtolower((string) ($period['type'] ?? 'ytd'));
    switch ($type) {
        case 'mtd':
            $from = $today->modify('first day of this month')->setTime(0, 0, 0);
            $to   = $today->setTime(23, 59, 59);
            return [$from, $to, 'Month-to-date (' . $from->format('F Y') . ')', 'mtd'];
        case 'month':
            $y = (int) ($period['year']  ?? (int) $today->format('Y'));
            $m = max(1, min(12, (int) ($period['month'] ?? (int) $today->format('m'))));
            $start = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $y, $m));
            $end   = $start->modify('last day of this month')->setTime(23, 59, 59);
            if ($end > $today) $end = $today->setTime(23, 59, 59);
            return [$start, $end, $start->format('F Y'), 'month'];
        case 'yoy':
        case 'ytd':
        default:
            $from = $today->modify('first day of January')->setTime(0, 0, 0);
            $to   = $today->setTime(23, 59, 59);
            return [$from, $to, 'Year-to-date ' . $today->format('Y'), 'ytd'];
    }
}

/**
 * Resolve the uploaded company logo, if any. Returns a web path relative to
 * document root (/uploads/logo.xxx) or null.
 */
function ea_logo_url(): ?string
{
    $name = \EA\BI\Config::get('APP_LOGO', '');
    if (!$name) return null;
    $fs = dirname(__DIR__) . '/public/uploads/' . $name;
    if (!is_file($fs)) return null;
    return '/uploads/' . $name . '?v=' . (int) @filemtime($fs);
}
