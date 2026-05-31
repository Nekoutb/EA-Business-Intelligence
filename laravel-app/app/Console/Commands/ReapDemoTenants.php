<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Demo sandbox reaper.
 *
 * Deletes visitor demo tenants whose demo_expires_at has passed.
 * Designed to run from cron daily (or hourly) so the per-visitor
 * sandboxes don't accumulate forever.
 *
 * Usage:
 *
 *   php artisan demo:reap                # delete expired demo tenants
 *   php artisan demo:reap --all          # delete ALL demo tenants (live + expired)
 *   php artisan demo:reap --dry-run      # show what would be deleted, don't actually delete
 *   php artisan demo:reap --keep=2       # always keep the N most recent demo sessions
 *                                          for sales demo purposes
 *
 * Cascading deletes via SoftDeletes on the tenants table means
 * related rows (modules, services, settings, audit logs scoped to
 * the tenant) come along for the ride.
 *
 * NB: This is the command the operator runs after PR #169 to wipe
 * the demo backlog from Business Insights. Use --all the first time
 * to clear everything, then schedule the bare command to run daily.
 */
class ReapDemoTenants extends Command
{
    protected $signature = 'demo:reap
                            {--all       : Delete all demo tenants regardless of expiry}
                            {--keep=0    : Keep this many most-recent demo tenants}
                            {--dry-run   : Show what would be deleted, do not actually delete}';

    protected $description = 'Delete expired (or all) visitor demo tenants and their data';

    public function handle(): int
    {
        $all     = (bool) $this->option('all');
        $keep    = max(0, (int) $this->option('keep'));
        $dry     = (bool) $this->option('dry-run');

        // Guard if the is_demo column doesn't exist (very early
        // install before the demo feature was deployed).
        if (!\Illuminate\Support\Facades\Schema::hasColumn('tenants', 'is_demo')) {
            $this->warn('is_demo column does not exist on tenants table — nothing to reap.');
            return self::SUCCESS;
        }

        $query = Tenant::where('is_demo', true);
        if (!$all) {
            $query->whereNotNull('demo_expires_at')
                  ->where('demo_expires_at', '<', now());
        }

        // Sort newest-first so --keep preserves the most recent.
        $candidates = $query->orderByDesc('id')->get();

        if ($candidates->isEmpty()) {
            $this->info('No demo tenants to reap. ✓');
            return self::SUCCESS;
        }

        if ($keep > 0) {
            $preserved  = $candidates->slice(0, $keep);
            $candidates = $candidates->slice($keep);
            $this->info("Preserving the {$keep} most recent demo tenant(s):");
            foreach ($preserved as $t) {
                $this->line("  • {$t->slug}  ({$t->brand})");
            }
        }

        $this->info(sprintf(
            '%s %d demo tenant(s)%s:',
            $dry ? 'Would delete' : 'Deleting',
            $candidates->count(),
            $all ? ' (ALL — live + expired)' : ' (expired only)'
        ));

        $deleted = 0;
        foreach ($candidates as $t) {
            $line = sprintf(
                '  • %-30s  expires_at=%s',
                $t->slug,
                $t->demo_expires_at?->format('Y-m-d H:i') ?? 'NULL'
            );
            $this->line($line);
            if (!$dry) {
                $t->delete();      // soft-deletes the tenant + cascades
                $deleted++;
            }
        }

        $this->newLine();
        if ($dry) {
            $this->comment('Dry run — nothing actually deleted. Re-run without --dry-run to commit.');
        } else {
            $this->info("Reaped {$deleted} demo tenant(s). ✓");
        }
        return self::SUCCESS;
    }
}
