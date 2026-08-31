<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Sync for a REST-gateway ERP source (ERP_TYPE=rest): pulls Sage GL
 * entries (/ecritures) into wh_sage_ecriture, windowed by date.
 *
 * The t-a-cam gateway caps every response at 100 rows and ignores
 * limit/offset — only date_debut/date_fin (and compte) filter. So the
 * sync walks month windows and, when a month comes back at exactly the
 * cap, descends into day windows. A day that STILL hits the cap cannot
 * be fetched completely; its rows are stored and the day is recorded in
 * wh_sync_state.last_error so the gap is visible instead of silent.
 * Full fidelity needs pagination on the gateway side.
 *
 * Rows have no stable source id, so each window is replaced atomically
 * (delete + insert) rather than upserted.
 */
class SageRestSyncService
{
    private SageRestClient $client;
    private string $glPath;
    /** Consecutive failed windows — the run aborts at 3 so a hung gateway
     *  is never walked day-by-day into hundreds of timeouts. */
    private int $failStreak = 0;

    public function __construct(private Tenant $tenant, private DataSource $source)
    {
        $this->client = new SageRestClient($source);
        $this->glPath = trim((string) config_env($source->key('ERP_API_GL_PATH'), '')) ?: '/ecritures';
    }

    /** @param callable(string,array):void $progress */
    public function syncAll(?callable $progress = null, float $deadline = 0.0): array
    {
        $out = [];
        $started = microtime(true);
        $rows = 0; $truncated = [];
        $error = null;
        try {
            if (!$this->client->configured()) {
                throw new \RuntimeException('REST source not configured (ERP_API_URL / ERP_API_KEY).');
            }
            // Fiscal floor: a Sage company file holds one fiscal year (the
            // tsa gateway's is "TSA COMPTA 2026"), and out-of-range windows
            // make the gateway scan long enough to time out — so start at
            // January 1st of the current year.
            // Incremental: resume from the newest stored date minus a 7-day
            // re-fetch tail (late postings), so the initial backfill survives
            // the cron's time budget across runs and daily runs stay cheap.
            $floor = now()->startOfYear();
            $maxSeen = DB::table('wh_sage_ecriture')
                ->where('tenant_id', $this->tenant->id)->where('source', $this->source->n)
                ->max('ecr_date');
            $from = $maxSeen
                ? max($floor, \Illuminate\Support\Carbon::parse($maxSeen)->subDays(7)->startOfDay())
                : $floor;
            $to = now()->endOfDay();
            for ($m = $from->copy()->startOfMonth(); $m->lte($to); $m->addMonth()) {
                if ($deadline > 0 && microtime(true) > $deadline) break;
                $du = $m->lt($from) ? $from->format('Y-m-d') : $m->format('Y-m-01');
                $au = min($m->copy()->endOfMonth(), $to)->format('Y-m-d');
                try {
                    $rows += $this->syncWindow($du, $au, $truncated, $deadline);
                    $this->failStreak = 0;
                } catch (\Throwable $e) {
                    if (++$this->failStreak >= 3) throw new \RuntimeException('gateway unresponsive — run aborted after 3 consecutive failures (' . $e->getMessage() . ')');
                    // A slow month (gateway scan timeout) — retry day by day;
                    // days that still fail are recorded, not fatal.
                    $rows += $this->dayWalk($du, $au, $truncated, $deadline);
                }
            }
        } catch (\Throwable $e) {
            $error = mb_substr($e->getMessage(), 0, 500);
        }
        if ($truncated !== [] && $error === null) {
            $error = 'gateway 100-row cap truncated: ' . implode(', ', array_slice($truncated, 0, 20))
                . (count($truncated) > 20 ? ' +' . (count($truncated) - 20) . ' more' : '');
        }
        $result = ['rows' => $rows, 'seconds' => round(microtime(true) - $started, 1), 'error' => $error];
        DB::table('wh_sync_state')->updateOrInsert(
            ['tenant_id' => $this->tenant->id, 'model' => $this->source->stateModel('sage:ecritures')],
            ['last_run_at' => now(), 'rows_synced' => $rows, 'last_error' => $error,
             'updated_at' => now(), 'created_at' => now()]
        );
        if ($progress) $progress('sage:ecritures', $result);
        $out[] = $result;
        return $out;
    }

    /**
     * Fetch one window; descend into days when the month hits the cap.
     * Returns rows written. Truncated single days are appended to $truncated.
     */
    private function syncWindow(string $du, string $au, array &$truncated, float $deadline): int
    {
        // Pace requests — the gateway sits on a small SQL Server and has
        // been observed to hang under back-to-back queries.
        usleep(1_500_000);
        $json = $this->client->get($this->glPath, ['date_debut' => $du, 'date_fin' => $au]);
        $list = (array) ($json['ecritures'] ?? []);
        $count = (int) ($json['count'] ?? count($list));

        if ($count >= 100 && $du !== $au) {
            // Cap hit on a multi-day window — walk it day by day.
            return $this->dayWalk($du, $au, $truncated, $deadline);
        }

        if ($count >= 100 && $du === $au) $truncated[] = 'cap:' . $du;

        DB::transaction(function () use ($du, $au, $list) {
            DB::table('wh_sage_ecriture')
                ->where('tenant_id', $this->tenant->id)
                ->where('source', $this->source->n)
                ->whereBetween('ecr_date', [$du, $au])
                ->delete();
            foreach (array_chunk($list, 500) as $chunk) {
                DB::table('wh_sage_ecriture')->insert(array_map(fn ($r) => [
                    'tenant_id' => $this->tenant->id,
                    'source'    => $this->source->n,
                    'ecr_date'  => substr((string) ($r['EC_Date'] ?? ''), 0, 10) ?: null,
                    'journal'   => mb_substr((string) ($r['JO_Num'] ?? ''), 0, 32),
                    'account'   => mb_substr((string) ($r['CG_Num'] ?? ''), 0, 32),
                    'partner'   => ($r['CT_Num'] ?? null) !== null ? mb_substr((string) $r['CT_Num'], 0, 64) : null,
                    'piece'     => mb_substr((string) ($r['EC_Piece'] ?? ''), 0, 64),
                    'ref_piece' => ($r['EC_RefPiece'] ?? '') !== '' ? mb_substr((string) $r['EC_RefPiece'], 0, 64) : null,
                    'label'     => mb_substr((string) ($r['EC_Intitule'] ?? ''), 0, 191),
                    // Sage EC_Sens: 0 = débit, 1 = crédit.
                    'sens'      => (int) ($r['EC_Sens'] ?? 0),
                    'amount'    => (float) ($r['EC_Montant'] ?? 0),
                    'lettrage'  => ($r['EC_Lettrage'] ?? '') !== '' ? mb_substr((string) $r['EC_Lettrage'], 0, 16) : null,
                ], $chunk));
            }
        });
        return count($list);
    }

    /** Fetch a range one day at a time; a day that errors is recorded and skipped. */
    private function dayWalk(string $du, string $au, array &$truncated, float $deadline): int
    {
        $rows = 0;
        $d = new \DateTimeImmutable($du);
        $end = new \DateTimeImmutable($au);
        while ($d <= $end) {
            if ($deadline > 0 && microtime(true) > $deadline) break;
            $day = $d->format('Y-m-d');
            try {
                $rows += $this->syncWindow($day, $day, $truncated, $deadline);
                $this->failStreak = 0;
            } catch (\Throwable $e) {
                $truncated[] = 'failed:' . $day;
                if (++$this->failStreak >= 3) throw new \RuntimeException('gateway unresponsive — run aborted after 3 consecutive failures (' . $e->getMessage() . ')');
            }
            $d = $d->modify('+1 day');
        }
        return $rows;
    }
}
