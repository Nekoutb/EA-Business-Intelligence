<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\BusinessInsightsService;
use Illuminate\Console\Command;

/**
 * Daily Business Insights generator. Iterates every active tenant,
 * skips ones that already have a row dated today (unless --force),
 * and writes one BusinessInsight row per tenant.
 *
 * Scheduled via routes/console.php to run at 06:00 daily so insights
 * are ready for morning client meetings. Manual usage:
 *
 *   php artisan insights:generate-daily            # idempotent
 *   php artisan insights:generate-daily --force    # regenerate today
 *   php artisan insights:generate-daily --tenant=tadsa  # one tenant
 */
class GenerateDailyInsights extends Command
{
    protected $signature = 'insights:generate-daily
                            {--force         : Regenerate even if today\'s row already exists}
                            {--tenant=       : Only run for this tenant slug}';

    protected $description = 'Generate the daily Business Insights row for each active tenant';

    public function handle(BusinessInsightsService $svc): int
    {
        $force  = (bool) $this->option('force');
        $only   = (string) $this->option('tenant');

        // Real (non-demo) production tenants only. Daily insight
        // generation should never hit visitor demo sandboxes — they
        // expire in 2 hours, their data is synthetic, and burning
        // LLM tokens on them is just waste.
        $query = Tenant::real();
        if (defined(\App\Models\Tenant::class . '::STATUS_SUSPENDED')) {
            $query->where('status', '!=', Tenant::STATUS_SUSPENDED);
        }
        if ($only !== '') {
            $query->where('slug', $only);
        }

        $tenants = $query->orderBy('id')->get();

        if ($tenants->isEmpty()) {
            $this->warn('No tenants matched.');
            return self::SUCCESS;
        }

        $this->info(sprintf('Generating insights for %d tenant(s)...', $tenants->count()));

        $ok = 0; $failed = 0; $skipped = 0;
        foreach ($tenants as $t) {
            $this->line("  → {$t->slug} ({$t->brand}{$t->name})...");
            try {
                $row = $svc->generate($t, $force);
                $status = $row->status;
                $this->line("     status={$status} priority={$row->priority} tokens=in:{$row->input_tokens}/out:{$row->output_tokens}");
                if     ($status === 'ok')      $ok++;
                elseif ($status === 'skipped') $skipped++;
                else                           $failed++;
            } catch (\Throwable $e) {
                $this->error("     unexpected: " . $e->getMessage());
                $failed++;
            }
        }

        $this->newLine();
        $this->info("Done. ok={$ok} · skipped={$skipped} · failed={$failed}");
        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
