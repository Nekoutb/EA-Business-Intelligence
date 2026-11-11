<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Tenancy\TenantDatabase;
use App\Tenancy\TenantSchema;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Data hosting for one client (multi-server). Steps, in order:
 *
 *   configure  store where the client's own database is (encrypted)
 *   check      reach it: latency, version, schema drift
 *   provision  create the client tables in it (structure from the platform)
 *   copy       copy the client's rows from the platform, verify counts
 *   switch     final copy under the sync/warm locks, then flip data_location
 *   status     where the client's data lives now and its last health check
 *
 * Switching back to 'shared' only flips the location: the platform copy is
 * never deleted by this command.
 */
class TenantDb extends Command
{
    protected $signature = 'bms:tenant-db
        {action : configure|check|provision|copy|switch|status}
        {tenant : client slug}
        {--location= : ea (own database on the EA server) | client (client server) | shared (switch only)}
        {--database= : database name}
        {--host=} {--port=} {--username=} {--password= : omit to be prompted}
        {--ssl-ca= : path to the CA certificate on this server}
        {--force : switch even if row counts differ}';

    protected $description = "Data hosting: move a client's data to its own database (EA server or client server)";

    public function handle(): int
    {
        $t = Tenant::where('slug', (string) $this->argument('tenant'))->first();
        if (!$t) { $this->error('No client with that slug.'); return self::FAILURE; }
        $action = (string) $this->argument('action');
        // One long-running data move per client at a time (the admin page
        // starts these in the background and reads this lock as "running").
        $run = in_array($action, ['provision', 'copy', 'switch'], true) ? Cache::lock(self::runLock($t), 7200) : null;
        if ($run && !$run->get()) { $this->error("Another data-hosting step is already running for {$t->slug}."); return self::FAILURE; }
        try {
            return match ($action) {
                'configure' => $this->configureHosting($t),
                'check'     => $this->check($t),
                'provision' => $this->provision($t),
                'copy'      => $this->copyRows($t),
                'switch'    => $this->switchTo($t),
                'status'    => $this->status($t),
                default     => $this->stop("Unknown action '{$action}'."),
            };
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            if (preg_match('/Unknown database|Access denied/i', $e->getMessage()) && TenantDatabase::location($t) !== 'client' && $this->option('location') !== 'client') {
                $db = (string) (((array) $t->db_config)['database'] ?? TenantDatabase::defaultDatabaseName($t));
                $user = (string) config('database.connections.' . config('database.default') . '.username');
                $this->line("Create the database once as MySQL root on the EA server, then re-run:");
                $this->line("  mysql -u root -e \"CREATE DATABASE IF NOT EXISTS \\`{$db}\\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON \\`{$db}\\`.* TO '{$user}'@'localhost'; FLUSH PRIVILEGES;\"");
            }
            return self::FAILURE;
        } finally {
            TenantDatabase::reset();
            $run?->release();
        }
    }

    public static function runLock(Tenant $t): string
    {
        return 'bms:tenant-db:run:' . $t->id;
    }

    /** Location the target-side actions work on: --location, else the stored target, else the current one. */
    private function targetLocation(Tenant $t): string
    {
        $loc = (string) ($this->option('location') ?: (((array) $t->db_config)['target'] ?? TenantDatabase::location($t)));
        if (!in_array($loc, ['ea', 'client'], true)) {
            throw new \RuntimeException("Give --location=ea or --location=client (no dedicated database configured for {$t->slug}).");
        }
        return $loc;
    }

    private function configureHosting(Tenant $t): int
    {
        $loc = (string) $this->option('location');
        if (!in_array($loc, ['ea', 'client'], true)) return $this->stop('configure needs --location=ea or --location=client.');
        $c = (array) ($t->db_config ?? []);
        $c['target'] = $loc;
        foreach (['database', 'host', 'port', 'username', 'ssl-ca'] as $k) {
            $v = $this->option($k);
            if ($v !== null && $v !== '') $c[$k === 'ssl-ca' ? 'ssl_ca' : $k] = $k === 'port' ? (int) $v : (string) $v;
        }
        if ($loc === 'client') {
            $pw = $this->option('password');
            if ($pw === null && !array_key_exists('password', $c)) $pw = (string) $this->secret('Database password');
            if ($pw !== null) $c['password'] = (string) $pw;
            $c['driver'] = $c['driver'] ?? 'mysql';
        }
        $t->db_config = $c;
        $t->save();
        $this->audit($t, 'tenant.data_hosting.configure', ['target' => $loc, 'host' => $c['host'] ?? 'EA server', 'database' => $c['database'] ?? TenantDatabase::defaultDatabaseName($t)]);
        $this->info("Saved (encrypted). Target: {$loc}, database " . ($c['database'] ?? TenantDatabase::defaultDatabaseName($t)) . '. Next: check, then provision.');
        return self::SUCCESS;
    }

    private function check(Tenant $t): int
    {
        $loc = $this->targetLocation($t);
        $this->ensureSqliteFile($t, $loc);
        TenantDatabase::target($t, $loc);
        $t0 = microtime(true);
        $c = DB::connection(TenantDatabase::CONN);
        $c->getPdo();
        $ms = (int) round((microtime(true) - $t0) * 1000);
        $ver = $c->getDriverName() === 'sqlite' ? 'sqlite ' . $c->selectOne('select sqlite_version() v')->v : (string) $c->selectOne('select version() v')->v;
        $drift = TenantSchema::drift();
        $status = $drift ? 'behind' : 'ok';
        $this->line("Location:  {$loc}");
        $this->line("Database:  " . $c->getDatabaseName());
        $this->line("Connected: {$ms} ms · {$ver}");
        $this->line('Schema:    ' . ($drift ? count($drift) . ' table(s) missing or behind' : 'current (' . TenantSchema::fingerprint(TenantDatabase::CONN) . ')'));
        foreach (array_slice($drift, 0, 12, true) as $tb => $why) $this->line("           - {$tb}: {$why}");
        if ($loc === TenantDatabase::location($t)) {
            $t->forceFill(['db_status' => $status, 'db_checked_at' => now(), 'db_latency_ms' => $ms])->saveQuietly();
        }
        return self::SUCCESS;
    }

    private function provision(Tenant $t): int
    {
        $loc = $this->targetLocation($t);
        $this->ensureSqliteFile($t, $loc);
        TenantDatabase::target($t, $loc);
        $created = TenantSchema::provision();
        $drift = TenantSchema::drift();
        $this->info(count($created) . ' table(s) created' . ($created ? ': ' . implode(', ', $created) : '') . '.');
        if ($drift) {
            foreach ($drift as $tb => $why) $this->warn("{$tb}: {$why}");
            return $this->stop('Schema still differs from the platform.');
        }
        $this->audit($t, 'tenant.data_hosting.provision', ['target' => $loc, 'created' => count($created)]);
        $this->info('Schema current (' . TenantSchema::fingerprint(TenantDatabase::CONN) . '). Next: copy.');
        return self::SUCCESS;
    }

    private function copyRows(Tenant $t): int
    {
        $loc = $this->targetLocation($t);
        if ($loc === TenantDatabase::location($t)) return $this->stop("{$t->slug} already uses this database; copying would overwrite live data from the platform copy.");
        TenantDatabase::target($t, $loc);
        if ($d = TenantSchema::drift()) return $this->stop('Provision first: ' . implode(', ', array_keys($d)));
        $bad = $this->report(TenantSchema::copy($t, fn ($tb, $r) => $this->line(sprintf('  %-34s %10s → %10s', $tb, number_format($r[0]), number_format($r[1])))));
        $this->audit($t, 'tenant.data_hosting.copy', ['target' => $loc, 'mismatches' => $bad]);
        return $bad ? $this->stop("{$bad} table(s) differ.") : tap(self::SUCCESS, fn () => $this->info('All counts match. Next: switch.'));
    }

    private function switchTo(Tenant $t): int
    {
        $to = (string) $this->option('location');
        $from = TenantDatabase::location($t);
        if (!in_array($to, TenantDatabase::LOCATIONS, true)) return $this->stop('switch needs --location=ea|client|shared.');
        if ($to === $from) return $this->stop("{$t->slug} is already on '{$to}'.");
        if ($from !== 'shared' && $to !== 'shared') return $this->stop('Switch back to shared first, then to the other location.');

        if ($to === 'shared') {
            if (!$this->option('force') && !$this->confirm("Point {$t->slug} back to the platform copy? Data written to its own database since the switch is NOT copied back.")) return self::FAILURE;
            $t->forceFill(['data_location' => 'shared', 'db_status' => null])->save();
            $this->audit($t, 'tenant.data_hosting.switch', ['from' => $from, 'to' => 'shared']);
            $this->info("{$t->slug} now reads and writes the platform database.");
            return self::SUCCESS;
        }

        // Hold the sync and warm locks so nothing writes this client's
        // warehouse rows between the final copy and the flip.
        $sync = Cache::lock('bms:warehouse-sync:lock', 3600);
        $warm = Cache::lock('bms:warm:lock', 3600);
        if (!$sync->get()) return $this->stop('A warehouse sync is running; try again when it finishes.');
        try {
            if (!$warm->block(600)) return $this->stop('The warm worker did not finish within 10 minutes.');
            try {
                TenantDatabase::target($t, $to);
                if ($d = TenantSchema::drift()) return $this->stop('Provision first: ' . implode(', ', array_keys($d)));
                $this->line('Final copy...');
                $bad = $this->report(TenantSchema::copy($t));
                if ($bad && !$this->option('force')) return $this->stop("{$bad} table(s) differ; nothing switched.");
                $c = (array) $t->db_config; $c['target'] = $to;
                $t->forceFill(['data_location' => $to, 'db_config' => $c, 'db_status' => 'ok', 'db_checked_at' => now(),
                               'db_schema_version' => TenantSchema::fingerprint(TenantDatabase::CONN)])->save();
            } finally {
                $warm->release();
            }
        } finally {
            $sync->release();
        }
        $this->audit($t, 'tenant.data_hosting.switch', ['from' => $from, 'to' => $to]);
        $this->info("{$t->slug} now reads and writes its own database ({$to}). The platform copy is kept untouched; the warm worker rebuilds the reports.");
        return self::SUCCESS;
    }

    private function status(Tenant $t): int
    {
        $c = (array) ($t->db_config ?? []);
        $this->line('Data location:   ' . TenantDatabase::location($t));
        $this->line('Configured target: ' . ($c['target'] ?? '—') . (isset($c['host']) ? " ({$c['host']}:" . ($c['port'] ?? '') . '/' . ($c['database'] ?? '') . ')' : ''));
        $this->line('Health:          ' . ($t->db_status ?? '—') . ($t->db_checked_at ? ' · checked ' . $t->db_checked_at->diffForHumans() : '') . ($t->db_latency_ms !== null ? " · {$t->db_latency_ms} ms" : ''));
        $this->line('Schema version:  ' . ($t->db_schema_version ?? '—'));
        return self::SUCCESS;
    }

    /** Print mismatches; returns how many tables differ. */
    private function report(array $r): int
    {
        $bad = 0;
        foreach ($r as $tb => [$a, $b]) {
            if (is_string($a)) continue;
            if ($a !== $b) { $bad++; $this->warn(sprintf('  %-34s platform %s, copied %s', $tb, number_format($a), number_format((int) $b))); }
        }
        return $bad;
    }

    private function ensureSqliteFile(Tenant $t, string $loc): void
    {
        if ($loc !== 'ea' || config('database.connections.' . config('database.default') . '.driver') !== 'sqlite') return;
        $cfg = TenantDatabase::configFor($t, (array) config('database.connections.' . config('database.default')), 'ea');
        if (!is_file($cfg['database'])) touch($cfg['database']);
    }

    private function stop(string $msg): int
    {
        $this->error($msg);
        return self::FAILURE;
    }

    private function audit(Tenant $t, string $action, array $meta): void
    {
        try {
            AuditLog::create(['tenant_id' => $t->id, 'user_id' => null, 'action' => $action,
                              'target' => 'tenant:' . $t->slug, 'payload' => $meta, 'user_agent' => 'cli bms:tenant-db']);
        } catch (\Throwable $e) {
            // audit schema differs across environments; never block the operation on it
        }
    }
}
