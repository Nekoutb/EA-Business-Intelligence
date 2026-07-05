<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\StrategicAnalysisService;
use Illuminate\Console\Command;

/**
 * Client Strategic Analysis generator (replaces the old daily Business
 * Insights). Iterates every active client and writes one strategic-audit
 * row per client — the service itself is idempotent per calendar month, so
 * re-runs within a month are no-ops unless --force.
 *
 * Scheduled via routes/console.php to run monthly. Manual usage:
 *
 *   php artisan insights:generate-daily            # idempotent (this month)
 *   php artisan insights:generate-daily --force    # regenerate now
 *   php artisan insights:generate-daily --tenant=kwr
 */
class GenerateDailyInsights extends Command
{
    protected $signature = 'insights:generate-daily
                            {--force         : Regenerate even if this month\'s analysis already exists}
                            {--tenant=       : Only run for this tenant slug}';

    protected $description = 'Generate the monthly Client Strategic Analysis for each active client';

    public function handle(StrategicAnalysisService $svc): int
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
