<?php
declare(strict_types=1);

namespace EA\BI\Controllers;

/**
 * On-demand data refresh: clears all cached analyses and redirects back.
 * Invoked from the "Sync now" button in the header bar.
 */
final class SyncController
{
    public function sync(): void
    {
        ea_require_login();
        ea_cache_clear();
        $to = $_POST['return'] ?? ($_SERVER['HTTP_REFERER'] ?? '/');
        // Only allow same-origin relative URLs.
        if (!is_string($to) || !str_starts_with($to, '/')) $to = '/';
        header('Location: ' . $to);
        exit;
    }
}
