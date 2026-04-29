<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Force-reset PHP OPcache from inside the app.
 *
 * Without this, modifications to existing PHP files (e.g. bootstrap/app.php,
 * service providers, exception handlers) can stay invisible until PHP-FPM
 * is reloaded — a recurring source of "deploy looks shipped but old code
 * still running" bugs.
 *
 * Hook this into the deploy pipeline:
 *
 *     php artisan opcache:reset
 *
 * It is safe to run when OPcache is disabled (no-op).
 */
class OpcacheReset extends Command
{
    protected $signature = 'opcache:reset';

    protected $description = 'Reset PHP OPcache so newly-deployed code takes effect immediately.';

    public function handle(): int
    {
        if (!function_exists('opcache_reset')) {
            $this->info('OPcache extension not loaded — nothing to reset.');
            return self::SUCCESS;
        }

        $status = function_exists('opcache_get_status') ? @opcache_get_status(false) : null;
        $enabled = is_array($status) && !empty($status['opcache_enabled']);

        if (!$enabled) {
            $this->info('OPcache is not enabled — nothing to reset.');
            return self::SUCCESS;
        }

        $ok = @opcache_reset();
        if ($ok) {
            $this->info('OPcache reset successfully.');
            return self::SUCCESS;
        }

        $this->warn('OPcache reset returned false — usually a permissions issue (CLI vs FPM may run as different users).');
        return self::SUCCESS; // never fail the deploy
    }
}
