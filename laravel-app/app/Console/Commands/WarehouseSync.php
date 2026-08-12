<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Odoo\OdooFactory;
use App\Services\WarehouseSyncService;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * P2 — warehouse sync.
 *
 * Incremental by default (only records changed since the last run); pass
 * --full for a complete backfill. Safe to re-run either way: rows are
 * upserted on (tenant, odoo id), so nothing duplicates.
 *
 * Manual: php artisan bms:warehouse-sync [--tenant=slug] [--full]
 */
class WarehouseSync extends Command
{
    protected $signature = 'bms:warehouse-sync
                            {--tenant= : Only this tenant slug}
                            {--full    : Ignore watermarks and re-read everything}';

    protected $description = 'Copy Odoo facts into the local warehouse tables (P2)';

    public function handle(): int
    {
        $lock = Cache::lock('bms:warehouse-sync:lock', 7200);
        if (!$lock->get()) {
            $this->warn('A warehouse sync is already running — skipped.');
            return self::SUCCESS;
        }

        @ini_set('memory_limit', '1536M');
        $full = (bool) $this->option('full');
        $only = trim((string) $this->option('tenant'));

        $query = Tenant::real();
        if (defined(Tenant::class . '::STATUS_SUSPENDED')) {
            $query->where('status', '!=', Tenant::STATUS_SUSPENDED);
        }
        if ($only !== '') $query->where('slug', $only);

        $failed = 0;
        try {
            foreach ($query->orderBy('id')->get() as $tenant) {
                $this->line("→ {$tenant->slug}" . ($full ? ' (full backfill)' : ''));
                try {
                    app(TenantContext::class)->set($tenant);
                    $svc = new WarehouseSyncService(OdooFactory::fromConfig(), $tenant);
                    $res = $svc->syncAll($full, function (string $model, array $r) {
                        $this->line(sprintf('   %-20s %8s rows %7.1fs %s',
                            $model, number_format($r['rows']), $r['seconds'], $r['error'] ?? ''));
                    });
                    $fixed = $svc->reconcileLineDates();
                    $this->line("   line dates back-filled: " . number_format($fixed));
                    foreach ($res as $r) if ($r['error']) $failed++;
                } catch (\Throwable $e) {
                    $this->error("   failed: " . $e->getMessage());
                    $failed++;
                }
            }
        } finally {
            $lock->release();
        }

        $this->info($failed === 0 ? 'Warehouse sync complete.' : "Warehouse sync finished with {$failed} error(s).");
        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
