<?php
/**
 * TEMP ops window — token-guarded, read-mostly. Lets the assistant observe the
 * async worker (bms:warm) without server shell access: metrics, logs, the
 * sweep lock, and web opcache state. Two recovery switches (unlock, opcache
 * reset) — both safe and idempotent. Remove/stub when the worker is stable.
 *
 * Ops: ?op=status (default) | metrics | warmlog | deploylog | laravellog
 *      | unlock | opcachereset
 */
header('Content-Type: text/plain; charset=utf-8');
$TOKEN = 'k7Qz9_R2mVtX4pLs8wNcB3hJ6yD0fAeU';
if (!hash_equals($TOKEN, (string) ($_GET['t'] ?? ''))) { http_response_code(403); echo 'forbidden'; exit; }

// Diagnostics window: surface fatals instead of a blank 500 body, rule
// memory limits in/out, and never die mid-op on the web time limit.
ini_set('display_errors', '1');
error_reporting(E_ALL);
@ini_set('memory_limit', '1024M');
@set_time_limit(280);
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        echo "\nFATAL: {$e['message']} @ {$e['file']}:{$e['line']}\n";
    }
});

$base = dirname(__DIR__);
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();

function ln($s = '') { echo $s . "\n"; }
function tailFile(string $path, int $lines = 60): string
{
    if (!is_file($path)) return "(missing: {$path})";
    if (!is_readable($path)) return "(unreadable: {$path})";
    $size = filesize($path);
    $chunk = min($size, 64 * 1024);
    $fh = fopen($path, 'r');
    fseek($fh, -$chunk, SEEK_END);
    $data = (string) fread($fh, $chunk);
    fclose($fh);
    $rows = explode("\n", rtrim($data, "\n"));
    return implode("\n", array_slice($rows, -$lines));
}

$op = (string) ($_GET['op'] ?? 'status');
$L = max(10, min(2000, (int) ($_GET['lines'] ?? 60)));
try {
    switch ($op) {
        case 'metrics':
            foreach ([storage_path('logs/warm-metrics.jsonl'), rtrim(sys_get_temp_dir(), '/\\') . '/bms-warm-metrics.jsonl'] as $mp) {
                ln("== {$mp} (tail {$L}) ==");
                ln(tailFile($mp, $L));
                ln();
            }
            break;

        case 'warmlog':
            ln("== /var/log/bms-warm.log (tail {$L}) ==");
            ln(tailFile('/var/log/bms-warm.log', $L));
            break;

        case 'deploylog':
            ln("== /var/log/bms-deploy.log (tail {$L}) ==");
            ln(tailFile('/var/log/bms-deploy.log', $L));
            break;

        case 'laravellog':
            ln("== storage/logs/laravel.log (tail {$L}) ==");
            ln(tailFile(storage_path('logs/laravel.log'), $L));
            break;

        case 'maillog':
            // The daily-report cron's output — every 07:00 UTC run appends
            // here (success lines or the failure reason).
            ln("== /var/log/kwr-daily-report.log (tail {$L}) ==");
            ln(tailFile('/var/log/kwr-daily-report.log', $L));
            break;

        case 'sendreport':
            // Remote catch-up trigger for the Kissywears daily report.
            // ?date=YYYY-MM-DD (the report day, i.e. the "prior day" the
            // email covers). Uses the same artisan command as the cron.
            $d = (string) ($_GET['date'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) { ln('need ?date=YYYY-MM-DD'); break; }
            $code = Illuminate\Support\Facades\Artisan::call('reports:send-daily', [
                '--tenant' => (string) ($_GET['tenant'] ?? 'kwr'),
                '--date'   => $d,
                '--to'     => (string) ($_GET['to'] ?? 'kissywears1@gmail.com,contact@cm-ea.com'),
            ]);
            ln(rtrim(Illuminate\Support\Facades\Artisan::output()));
            ln('exit=' . $code);
            break;

        case 'whsync':
            // Run the warehouse sync for one tenant. &full=1 for a backfill.
            $args = ['--tenant' => (string) ($_GET['tenant'] ?? 'kwr')];
            if (!empty($_GET['full']))    $args['--full'] = true;
            if (!empty($_GET['model']))   $args['--model'] = (string) $_GET['model'];
            $args['--seconds'] = (string) ((int) ($_GET['seconds'] ?? 70));
            $t0 = microtime(true);
            $code = Illuminate\Support\Facades\Artisan::call('bms:warehouse-sync', $args);
            ln(rtrim(Illuminate\Support\Facades\Artisan::output()));
            ln(sprintf('exit=%d · %.1fs', $code, microtime(true) - $t0));
            break;

        case 'p3check':
            // P3 GATE — receivables from the warehouse vs from Odoo. Runs both
            // paths, compares every headline figure, the whole evolution
            // series and the offender scorecard, and times each.
            $slug = (string) ($_GET['tenant'] ?? 'kwr');
            $tenant = App\Models\Tenant::where('slug', $slug)->first();
            if (!$tenant) { ln("no tenant '{$slug}'"); break; }
            app(App\Tenancy\TenantContext::class)->set($tenant);
            $client = App\Odoo\OdooFactory::fromConfig();
            $rev = (new App\Services\RevenueService($client, (string) config_env('REVENUE_SOURCE', 'invoice')))
                ->analyze(null, ['type' => 'ytd', 'year' => (int) date('Y')]);
            $args = [(float) ($rev['current']['total'] ?? 0), (int) ($rev['period']['days'] ?? 1),
                     (string) ($rev['currency'] ?? 'XAF'), new DateTimeImmutable('today')];
            $run = function (bool $wh) use ($client, $args) {
                config(['bi.warehouse_receivables' => $wh]);
                $t0 = microtime(true);
                $r = (new App\Services\ReceivablesService($client))->analyze(...$args);
                return [$r, microtime(true) - $t0];
            };
            [$w, $tW] = $run(true);
            [$o, $tO] = $run(false);
            config(['bi.warehouse_receivables' => false]);
            ln(sprintf('warehouse %.2fs · odoo %.2fs · speed-up %.1f×', $tW, $tO, $tW > 0 ? $tO / $tW : 0));
            ln();
            $worst = 0.0;
            foreach (['total_ar', 'dso', 'over60_amount', 'over90_amount', 'pct_gt_90', 'open_invoice_count'] as $k) {
                $a1 = (float) ($w[$k] ?? 0); $b1 = (float) ($o[$k] ?? 0);
                $worst = max($worst, abs($a1 - $b1));
                ln(sprintf('%-20s wh %18s · odoo %18s · Δ %s', $k,
                    number_format($a1, 2), number_format($b1, 2), number_format($a1 - $b1, 2)));
            }
            $ew = (array) ($w['ar_evolution'] ?? []); $eo = (array) ($o['ar_evolution'] ?? []);
            $ed = 0.0;
            foreach ($eo as $i => $row) {
                foreach (['total', 'over_60', 'over_90'] as $k) {
                    $ed = max($ed, abs((float) ($row[$k] ?? 0) - (float) ($ew[$i][$k] ?? 0)));
                }
            }
            $worst = max($worst, $ed);
            ln(sprintf('%-20s %d months · worst Δ %s', 'evolution', count($eo), number_format($ed, 2)));
            $pw = (array) ($w['top_partners'] ?? []); $po = (array) ($o['top_partners'] ?? []);
            $pd = 0.0;
            foreach ($po as $i => $row) {
                $pd = max($pd, abs((float) ($row['ar'] ?? 0) - (float) ($pw[$i]['ar'] ?? 0)));
            }
            $worst = max($worst, $pd);
            ln(sprintf('%-20s %d customers · worst Δ %s', 'offenders', count($po), number_format($pd, 2)));
            ln();
            ln('GATE: worst variance = ' . number_format($worst, 2) . ' — ' . ($worst < 0.005 ? 'PASS ✅' : 'FAIL ❌'));
            break;

        case 'glbackfill':
            // Fill the accounting columns on rows that predate them. Safe to
            // call repeatedly: each pass takes the next slice of still-NULL
            // rows, so it resumes wherever the last one ran out of time.
            $slug = (string) ($_GET['tenant'] ?? 'kwr');
            $tenant = App\Models\Tenant::where('slug', $slug)->first();
            if (!$tenant) { ln("no tenant '{$slug}'"); break; }
            app(App\Tenancy\TenantContext::class)->set($tenant);
            $svc = new App\Services\WarehouseSyncService(App\Odoo\OdooFactory::fromConfig(), $tenant);
            $svc->setDeadline(microtime(true) + (float) ($_GET['seconds'] ?? 70));
            $t0 = microtime(true);
            $r = $svc->backfillGlColumns();
            $fixed = $svc->reconcileLineDates();
            ln(sprintf('filled %s · removed (gone from odoo) %s · still stale %s · line dates %s · %.1fs',
                number_format($r['filled']), number_format($r['removed']),
                number_format($r['remaining']), number_format($fixed), microtime(true) - $t0));
            ln($r['remaining'] === 0 ? 'BACKFILL COMPLETE ✅' : 'more passes needed — call again');
            break;

        case 'glstate':
            // Ground truth for the GL fact table. Written because inferring
            // sync progress from year-scoped counts kept producing plausible
            // but unfalsifiable theories: the table total, the cursor and the
            // Odoo total are what actually decide whether a run advanced.
            $slug = (string) ($_GET['tenant'] ?? 'kwr');
            $tenant = App\Models\Tenant::where('slug', $slug)->first();
            if (!$tenant) { ln("no tenant '{$slug}'"); break; }
            app(App\Tenancy\TenantContext::class)->set($tenant);
            $client = App\Odoo\OdooFactory::fromConfig();
            $T = Illuminate\Support\Facades\DB::table('wh_fact_gl_line')->where('tenant_id', $tenant->id);
            $agg = (clone $T)->selectRaw(
                'COUNT(*) total,
                 SUM(gl_date IS NULL) nodate,
                 SUM(parent_state IS NULL OR parent_state = "") nostate,
                 SUM(parent_state = "posted") posted,
                 MIN(gl_date) mind, MAX(gl_date) maxd,
                 MIN(odoo_id) minid, MAX(odoo_id) maxid,
                 SUM(debit) dr, SUM(credit) cr'
            )->first();
            ln("== wh_fact_gl_line · {$slug} ==");
            ln(sprintf('rows in table      %s', number_format((int) $agg->total)));
            ln(sprintf('gl_date NULL       %s', number_format((int) $agg->nodate)));
            ln(sprintf('parent_state empty %s', number_format((int) $agg->nostate)));
            ln(sprintf('parent_state posted %s', number_format((int) $agg->posted)));
            ln(sprintf('gl_date range      %s .. %s', (string) ($agg->mind ?? '—'), (string) ($agg->maxd ?? '—')));
            ln(sprintf('odoo_id range      %s .. %s', number_format((int) $agg->minid), number_format((int) $agg->maxid)));
            ln(sprintf('debit / credit     %s / %s', number_format((float) $agg->dr, 2), number_format((float) $agg->cr, 2)));
            ln();
            $st = Illuminate\Support\Facades\DB::table('wh_sync_state')
                ->where('tenant_id', $tenant->id)->where('model', 'account.move.line')->first();
            ln('== cursor ==');
            ln(sprintf('last_write_date    %s', (string) ($st->last_write_date ?? '—')));
            ln(sprintf('last_id            %s', $st && $st->last_id !== null ? number_format((int) $st->last_id) : '— (NULL)'));
            ln(sprintf('rows_synced        %s', number_format((int) ($st->rows_synced ?? 0))));
            ln(sprintf('last_error         %s', (string) ($st->last_error ?? '—')));
            ln();
            ln('== odoo ==');
            $oTotal = $client->searchCount('account.move.line', []);
            ln(sprintf('account.move.line  %s total (no domain)', number_format($oTotal)));
            $oBlock = $client->searchCount('account.move.line', [['write_date', '=', (string) ($st->last_write_date ?? '')]]);
            ln(sprintf('sharing watermark  %s records at write_date %s',
                number_format($oBlock), (string) ($st->last_write_date ?? '—')));
            ln(sprintf('missing from table %s', number_format($oTotal - (int) $agg->total)));
            break;

        case 'glcheck':
            // P3 GATE — GL balances from the warehouse vs from Odoo, across
            // every prefix the app actually reports on, in each of the three
            // aggregation shapes GLAccountService offers. Passing this is
            // also what proves the GL backfill is complete: a partially
            // synced tenant sums to less than Odoo and cannot tie out.
            $slug = (string) ($_GET['tenant'] ?? 'kwr');
            $tenant = App\Models\Tenant::where('slug', $slug)->first();
            if (!$tenant) { ln("no tenant '{$slug}'"); break; }
            app(App\Tenancy\TenantContext::class)->set($tenant);
            $client = App\Odoo\OdooFactory::fromConfig();
            $today = new DateTimeImmutable('today');
            $win   = $today->modify('-90 days');

            // Each probe: label => callable(GLAccountService): ?float
            $probes = [
                'balance 41 (AR)'      => fn ($g) => $g->balanceByPrefix('41', null, $today),
                'credit 70 (revenue)'  => fn ($g) => $g->creditByPrefix('70', $win, $today),
                'balance 40 (AP)'      => fn ($g) => $g->balanceByPrefix('40', null, $today),
                'balance 6 (expenses)' => fn ($g) => $g->balanceByPrefix('6', null, $today),
                'cr+ 4 (ST debt)'      => fn ($g) => $g->sumPositiveCreditBalancesByPrefix('4', $today),
                'dr+ 52 (bank)'        => fn ($g) => $g->sumPositiveDebitBalancesByPrefix('52', $today),
                'dr+ 57 (cash)'        => fn ($g) => $g->sumPositiveDebitBalancesByPrefix('57', $today),
            ];

            $run = function (bool $wh) use ($client, $probes, $today) {
                config(['bi.warehouse_gl' => $wh]);
                $g = new App\Services\GLAccountService($client);
                $t0 = microtime(true);
                $out = [];
                foreach ($probes as $label => $fn) $out[$label] = $fn($g);
                $out['__accounts_52'] = $g->accountBalancesByPrefix('52', $today);
                return [$out, microtime(true) - $t0];
            };
            [$w, $tW] = $run(true);
            [$o, $tO] = $run(false);
            config(['bi.warehouse_gl' => false]);

            ln(sprintf('warehouse %.2fs · odoo %.2fs · speed-up %.1f×', $tW, $tO, $tW > 0 ? $tO / $tW : 0));
            ln();
            $worst = 0.0; $nullMismatch = 0;
            foreach ($probes as $label => $_) {
                $a1 = $w[$label]; $b1 = $o[$label];
                // null vs a number is a real difference, not a 0.00 tie.
                if (($a1 === null) !== ($b1 === null)) {
                    $nullMismatch++;
                    ln(sprintf('%-22s wh %18s · odoo %18s · NULL MISMATCH', $label,
                        $a1 === null ? 'null' : number_format((float) $a1, 2),
                        $b1 === null ? 'null' : number_format((float) $b1, 2)));
                    continue;
                }
                $d = abs((float) $a1 - (float) $b1);
                $worst = max($worst, $d);
                ln(sprintf('%-22s wh %18s · odoo %18s · Δ %s', $label,
                    number_format((float) $a1, 2), number_format((float) $b1, 2), number_format($d, 2)));
            }

            // Per-account shape: same accounts, same order, same balances.
            $aw = (array) ($w['__accounts_52'] ?? []); $ao = (array) ($o['__accounts_52'] ?? []);
            $ad = 0.0;
            if (count($aw) !== count($ao)) {
                $nullMismatch++;
                ln(sprintf('%-22s wh %d accounts · odoo %d accounts · COUNT MISMATCH', 'accounts 52', count($aw), count($ao)));
            } else {
                foreach ($ao as $i => $row) {
                    if ((string) ($row['code'] ?? '') !== (string) ($aw[$i]['code'] ?? '')) { $nullMismatch++; break; }
                    $ad = max($ad, abs((float) ($row['balance'] ?? 0) - (float) ($aw[$i]['balance'] ?? 0)));
                }
                $worst = max($worst, $ad);
                ln(sprintf('%-22s %d accounts · worst Δ %s', 'accounts 52', count($ao), number_format($ad, 2)));
            }

            ln();
            $pass = $worst < 0.005 && $nullMismatch === 0;
            ln('GATE: worst variance = ' . number_format($worst, 2)
                . ($nullMismatch ? " · {$nullMismatch} shape mismatch(es)" : '')
                . ' — ' . ($pass ? 'PASS ✅' : 'FAIL ❌'));
            break;

        case 'whcheck':
            // P2 GATE — do warehouse totals tie to Odoo? Compares row counts
            // and money for the selected year, straight from each source.
            $slug = (string) ($_GET['tenant'] ?? 'kwr');
            $year = (int) ($_GET['year'] ?? date('Y'));
            $tenant = App\Models\Tenant::where('slug', $slug)->first();
            if (!$tenant) { ln("no tenant '{$slug}'"); break; }
            app(App\Tenancy\TenantContext::class)->set($tenant);
            $client = App\Odoo\OdooFactory::fromConfig();
            $from = sprintf('%04d-01-01', $year); $to = sprintf('%04d-12-31', $year);

            ln("== warehouse vs Odoo · {$slug} · {$year} ==");
            $worst = 0.0; $rowsOff = 0;
            foreach ([['out_invoice', 'customer invoices'], ['in_invoice', 'supplier bills']] as [$mt, $label]) {
                $oG = $client->callModel('account.move', 'read_group', [
                    'domain' => [['move_type', '=', $mt], ['state', '=', 'posted'],
                                 ['invoice_date', '>=', $from], ['invoice_date', '<=', $to]],
                    'fields' => ['amount_untaxed_signed:sum', 'amount_residual_signed:sum'],
                    'groupby' => [], 'lazy' => false,
                ]);
                $oCount = (int) ($oG[0]['__count'] ?? 0);
                $oHt    = (float) ($oG[0]['amount_untaxed_signed'] ?? 0);
                $oRes   = (float) ($oG[0]['amount_residual_signed'] ?? 0);

                $w = Illuminate\Support\Facades\DB::table('wh_fact_invoice')
                    ->where('tenant_id', $tenant->id)->where('move_type', $mt)->where('state', 'posted')
                    ->whereBetween('invoice_date', [$from, $to])
                    ->selectRaw('COUNT(*) c, COALESCE(SUM(amount_untaxed),0) ht, COALESCE(SUM(amount_residual),0) res')
                    ->first();
                $wCount = (int) ($w->c ?? 0); $wHt = (float) ($w->ht ?? 0); $wRes = (float) ($w->res ?? 0);

                $rowsOff += abs($oCount - $wCount);
                $worst = max($worst, abs($oHt - $wHt), abs($oRes - $wRes));
                ln(sprintf('%-20s rows %7s/%-7s Δ%-5d · HT %18s/%-18s Δ %s', $label,
                    number_format($wCount), number_format($oCount), $wCount - $oCount,
                    number_format($wHt, 2), number_format($oHt, 2), number_format($wHt - $oHt, 2)));
                ln(sprintf('%-20s residual %s / %s · Δ %s', '',
                    number_format($wRes, 2), number_format($oRes, 2), number_format($wRes - $oRes, 2)));
            }

            // Like-for-like on BOTH sides. This comparison used to read as a
            // 26k-row discrepancy purely because the sides counted different
            // things: the warehouse table holds every GL line (the sync has
            // no domain filter), while Odoo was being asked for invoice lines
            // only. Match the document filter, and date on gl_date — the date
            // as booked — since invoice_date is rewritten to the parent's.
            $docTypes = ['out_invoice', 'out_refund', 'in_invoice', 'in_refund'];
            $lc = Illuminate\Support\Facades\DB::table('wh_fact_gl_line')
                ->where('tenant_id', $tenant->id)
                ->whereIn('move_type', $docTypes)
                ->whereBetween('gl_date', [$from, $to])->count();
            $oLc = $client->searchCount('account.move.line', [
                ['move_id.move_type', 'in', $docTypes],
                ['date', '>=', $from], ['date', '<=', $to],
            ]);
            ln(sprintf('%-20s rows %7s/%-7s Δ%d', 'invoice lines', number_format($lc), number_format($oLc), $lc - $oLc));

            $glc = Illuminate\Support\Facades\DB::table('wh_fact_gl_line')
                ->where('tenant_id', $tenant->id)->whereBetween('gl_date', [$from, $to])->count();
            $oGlc = $client->searchCount('account.move.line', [['date', '>=', $from], ['date', '<=', $to]]);
            ln(sprintf('%-20s rows %7s/%-7s Δ%d', 'all GL lines', number_format($glc), number_format($oGlc), $glc - $oGlc));

            foreach (Illuminate\Support\Facades\DB::table('wh_sync_state')->where('tenant_id', $tenant->id)->get() as $st) {
                ln(sprintf('  state %-20s watermark %-20s rows %8s %s',
                    $st->model, (string) ($st->last_write_date ?? '—'),
                    number_format((int) $st->rows_synced), $st->last_error ? ('ERR: ' . mb_substr($st->last_error, 0, 60)) : ''));
            }
            ln();
            ln('GATE: worst money Δ = ' . number_format($worst, 2) . ' · invoice row Δ = ' . $rowsOff
                . ' — ' . ($worst < 0.005 && $rowsOff === 0 ? 'PASS ✅' : 'FAIL ❌'));
            break;

        case 'archeck':
            // P1 GATE — receivables. The evolution rewrite is pure CPU (no
            // Odoo change), so this measures the page and prints the whole
            // evolution series plus headline figures for eyeball comparison
            // against the values recorded before the change.
            $slug = (string) ($_GET['tenant'] ?? 'transatlanticd');
            $tenant = App\Models\Tenant::where('slug', $slug)->first();
            if (!$tenant) { ln("no tenant '{$slug}'"); break; }
            app(App\Tenancy\TenantContext::class)->set($tenant);
            $client = App\Odoo\OdooFactory::fromConfig();
            $src = (string) config_env('REVENUE_SOURCE', 'invoice');
            $t0 = microtime(true);
            $rev = (new App\Services\RevenueService($client, $src))->analyze(null, ['type' => 'ytd', 'year' => (int) date('Y')]);
            $tRev = microtime(true) - $t0;
            $t1 = microtime(true);
            $ar = (new App\Services\ReceivablesService($client))->analyze(
                (float) ($rev['current']['total'] ?? 0),
                (int) ($rev['period']['days'] ?? 1),
                (string) ($rev['currency'] ?? 'XAF'),
                new DateTimeImmutable('today')
            );
            $tAr = microtime(true) - $t1;
            ln(sprintf('revenue %.1fs · receivables %.1fs · open invoices %d',
                $tRev, $tAr, (int) ($ar['open_invoice_count'] ?? 0)));
            ln(sprintf('total_ar %s · dso %s · over60 %s · over90 %s',
                number_format((float) ($ar['total_ar'] ?? 0), 2), number_format((float) ($ar['dso'] ?? 0), 1),
                number_format((float) ($ar['over60_amount'] ?? 0), 2), number_format((float) ($ar['over90_amount'] ?? 0), 2)));
            ln();
            ln(sprintf('%-6s %18s %18s %18s', 'month', 'total', 'over60', 'over90'));
            foreach ((array) ($ar['ar_evolution'] ?? []) as $e) {
                ln(sprintf('%-6s %18s %18s %18s', $e['label'] ?? '?',
                    number_format((float) ($e['total'] ?? 0), 2),
                    number_format((float) ($e['over_60'] ?? 0), 2),
                    number_format((float) ($e['over_90'] ?? 0), 2)));
            }
            break;

        case 'revcheck':
            // P1 GATE — revenue: batched line metrics vs the row fetch.
            $slug = (string) ($_GET['tenant'] ?? 'transatlanticd');
            $tenant = App\Models\Tenant::where('slug', $slug)->first();
            if (!$tenant) { ln("no tenant '{$slug}'"); break; }
            app(App\Tenancy\TenantContext::class)->set($tenant);
            $client = App\Odoo\OdooFactory::fromConfig();
            $src = (string) config_env('REVENUE_SOURCE', 'invoice');
            $period = ['type' => 'ytd', 'year' => (int) date('Y')];
            $run = function (bool $b) use ($client, $src, $period) {
                config(['bi.revenue_batched' => $b]);
                $t0 = microtime(true);
                $r = (new App\Services\RevenueService($client, $src))->analyze(null, $period);
                return [$r, microtime(true) - $t0];
            };
            // ONE path per request (&path=batched|row), figures stashed in
            // cache; &path=compare diffs them. Running both back-to-back
            // exceeded Cloudflare's 100s ceiling — the OLD path alone is ~60s.
            $which = (string) ($_GET['path'] ?? 'compare');
            $ckey  = 'revcheck:' . $slug . ':';
            $shape = fn (array $r) => [
                'total'              => (float) ($r['current']['total'] ?? 0),
                'outfits'            => (float) ($r['current']['outfits'] ?? 0),
                'customers'          => (float) ($r['current']['customers'] ?? 0),
                'total_discount'     => (float) ($r['current']['total_discount'] ?? 0),
                'discounted_outfits' => (float) ($r['current']['discounted_outfits'] ?? 0),
                'prior_total'        => (float) ($r['prior']['total'] ?? 0),
                'prior_outfits'      => (float) ($r['prior']['outfits'] ?? 0),
                'series'             => array_map('floatval', (array) ($r['current']['series'] ?? [])),
            ];
            if ($which === 'batched' || $which === 'row') {
                [$res, $t] = $run($which === 'batched');
                config(['bi.revenue_batched' => true]);
                Illuminate\Support\Facades\Cache::put($ckey . $which, $shape($res), 3600);
                ln(sprintf('%s path: %.1fs · total %s · outfits %s · discount %s', $which, $t,
                    number_format((float) ($res['current']['total'] ?? 0), 2),
                    number_format((float) ($res['current']['outfits'] ?? 0)),
                    number_format((float) ($res['current']['total_discount'] ?? 0), 2)));
                ln('stashed — run the other path, then &path=compare');
                break;
            }
            $newS = Illuminate\Support\Facades\Cache::get($ckey . 'batched');
            $oldS = Illuminate\Support\Facades\Cache::get($ckey . 'row');
            if (!$newS || !$oldS) { ln('need both runs first: &path=batched then &path=row'); break; }
            $worstS = 0.0;
            foreach (['total', 'outfits', 'customers', 'total_discount', 'discounted_outfits', 'prior_total', 'prior_outfits'] as $k) {
                $d = (float) $newS[$k] - (float) $oldS[$k];
                $worstS = max($worstS, abs($d));
                ln(sprintf('%-20s batched %18s · row %18s · Δ %s', $k,
                    number_format((float) $newS[$k], 2), number_format((float) $oldS[$k], 2), number_format($d, 2)));
            }
            $sdS = 0.0;
            foreach ((array) $oldS['series'] as $d => $v) $sdS = max($sdS, abs((float) $v - (float) ($newS['series'][$d] ?? 0)));
            $worstS = max($worstS, $sdS);
            ln(sprintf('%-20s %d days · worst daily Δ %s', 'series', count((array) $oldS['series']), number_format($sdS, 2)));
            ln();
            ln('GATE: worst variance = ' . number_format($worstS, 2) . ' — ' . ($worstS < 0.005 ? 'PASS ✅' : 'FAIL ❌'));
            break;

        case 'pocheck':
            // Which field actually carries the negative, and what excluding it
            // does to PO compliance. amount_total_signed is negative for EVERY
            // vendor bill by Odoo's sign convention, so filtering on the wrong
            // one either changes nothing or removes the entire population.
            $slug = (string) ($_GET['tenant'] ?? 'kwr');
            $tenant = App\Models\Tenant::where('slug', $slug)->first();
            if (!$tenant) { ln("no tenant '{$slug}'"); break; }
            app(App\Tenancy\TenantContext::class)->set($tenant);
            $client = App\Odoo\OdooFactory::fromConfig();
            $ys = date('Y') . '-01-01'; $asAt = date('Y-m-d');
            $baseDom = [['move_type', '=', 'in_invoice'], ['state', '=', 'posted'],
                        ['invoice_date', '>=', $ys], ['invoice_date', '<=', $asAt]];

            $all = $client->searchRead('account.move', $baseDom,
                ['name', 'invoice_date', 'amount_total', 'amount_total_signed', 'amount_untaxed'],
                ['limit' => 20000]);
            $negTotal = 0; $negSigned = 0; $posTotal = 0; $zero = 0;
            foreach ($all as $r) {
                $t = (float) ($r['amount_total'] ?? 0);
                $s = (float) ($r['amount_total_signed'] ?? 0);
                if ($t < 0) $negTotal++; elseif ($t > 0) $posTotal++; else $zero++;
                if ($s < 0) $negSigned++;
            }
            ln("== posted in_invoice YTD · {$slug} ==");
            ln(sprintf('bills fetched          %s', number_format(count($all))));
            ln(sprintf('amount_total  < 0      %s', number_format($negTotal)));
            ln(sprintf('amount_total  = 0      %s', number_format($zero)));
            ln(sprintf('amount_total  > 0      %s', number_format($posTotal)));
            ln(sprintf('amount_total_signed <0 %s   <- negative for ALL bills means wrong field',
                number_format($negSigned)));
            ln();
            $samples = array_slice(array_values(array_filter($all,
                fn ($r) => (float) ($r['amount_total'] ?? 0) < 0)), 0, 6);
            if ($samples) {
                ln('sample negative-amount bills:');
                foreach ($samples as $r) {
                    ln(sprintf('  %-18s %-12s total %16s · signed %16s',
                        mb_substr((string) ($r['name'] ?? ''), 0, 18),
                        (string) ($r['invoice_date'] ?? ''),
                        number_format((float) ($r['amount_total'] ?? 0), 2),
                        number_format((float) ($r['amount_total_signed'] ?? 0), 2)));
                }
            } else {
                ln('no bills with amount_total < 0 in this window');
            }
            ln();
            // Impact on the ratio, both ways.
            $g = $client->callModel('account.move.line', 'read_group', [
                'domain' => array_merge(
                    [['move_id.move_type', '=', 'in_invoice'], ['parent_state', '=', 'posted'],
                     ['move_id.invoice_date', '>=', $ys], ['move_id.invoice_date', '<=', $asAt],
                     ['purchase_line_id', '!=', false]], []),
                'fields' => [], 'groupby' => ['move_id'], 'lazy' => false,
            ]);
            $linkedIds = [];
            foreach ((array) $g as $r) { $mv = $r['move_id'] ?? false; if (is_array($mv)) $linkedIds[(int) $mv[0]] = true; }
            $binAll = count($all); $linkAll = 0; $binPos = 0; $linkPos = 0;
            foreach ($all as $r) {
                $isLinked = isset($linkedIds[(int) ($r['id'] ?? 0)]);
                if ($isLinked) $linkAll++;
                if ((float) ($r['amount_total'] ?? 0) >= 0) { $binPos++; if ($isLinked) $linkPos++; }
            }
            ln(sprintf('compliance incl. negatives  %6.2f%%  (%s of %s)',
                $binAll > 0 ? $linkAll / $binAll * 100 : 0, number_format($linkAll), number_format($binAll)));
            ln(sprintf('compliance excl. negatives  %6.2f%%  (%s of %s)',
                $binPos > 0 ? $linkPos / $binPos * 100 : 0, number_format($linkPos), number_format($binPos)));
            break;

        case 'revwhcheck':
            // GATE — revenue document aggregates from the warehouse vs Odoo.
            // Compares the headline totals, EVERY day of the series and EVERY
            // customer aggregate, because the dashboard shows a chart and a
            // top-customer table, and either could drift while the headline
            // stays right.
            $slug = (string) ($_GET['tenant'] ?? 'kwr');
            $tenant = App\Models\Tenant::where('slug', $slug)->first();
            if (!$tenant) { ln("no tenant '{$slug}'"); break; }
            app(App\Tenancy\TenantContext::class)->set($tenant);
            $client = App\Odoo\OdooFactory::fromConfig();
            $src = (string) config_env('REVENUE_SOURCE', 'invoice');
            $period = ['type' => (string) ($_GET['period'] ?? 'ytd'), 'year' => (int) date('Y')];
            $run = function (bool $wh) use ($client, $src, $period) {
                config(['bi.warehouse_revenue' => $wh]);
                $t0 = microtime(true);
                $r = (new App\Services\RevenueService($client, $src))->analyze(null, $period);
                return [$r, microtime(true) - $t0];
            };
            [$w, $tW] = $run(true);
            [$o, $tO] = $run(false);
            config(['bi.warehouse_revenue' => false]);

            ln(sprintf('warehouse %.2fs · odoo %.2fs · speed-up %.1f×', $tW, $tO, $tW > 0 ? $tO / $tW : 0));
            ln();
            $worst = 0.0; $worstKey = '—'; $compared = 0; $shape = 0;
            foreach ([['current', 'total'], ['current', 'outfits'], ['current', 'customers'],
                      ['previous', 'total'], ['current', 'discount_total']] as [$sec, $k]) {
                $a1 = (float) ($w[$sec][$k] ?? 0); $b1 = (float) ($o[$sec][$k] ?? 0);
                $compared++;
                $d = abs($a1 - $b1);
                if ($d > $worst) { $worst = $d; $worstKey = "{$sec}.{$k}"; }
                ln(sprintf('%-24s wh %18s · odoo %18s · Δ %s', "{$sec}.{$k}",
                    number_format($a1, 2), number_format($b1, 2), number_format($d, 2)));
            }
            // Daily series — every day, not a sample.
            $dw = (array) ($w['series'] ?? $w['current']['series'] ?? []);
            $do = (array) ($o['series'] ?? $o['current']['series'] ?? []);
            if (count($dw) !== count($do)) { $shape++; ln(sprintf('series days wh %d · odoo %d — COUNT MISMATCH', count($dw), count($do))); }
            $sd = 0.0;
            foreach ($do as $day => $v) {
                if (!array_key_exists($day, $dw)) { $shape++; continue; }
                $compared++;
                $d = abs((float) $v - (float) $dw[$day]);
                if ($d > $sd) $sd = $d;
                if ($d > $worst) { $worst = $d; $worstKey = "series[{$day}]"; }
            }
            ln(sprintf('%-24s %d days · worst Δ %s', 'daily series', count($do), number_format($sd, 2)));
            // Top customers — name and amount both. analyze() does not carry
            // this key; it is derived by topCustomers(), so it has to be built
            // here or the per-customer aggregates go untested. They come from
            // the new wh_dim_partner join and feed the dashboard's customer
            // table, which makes them the riskiest part of this change.
            $svc = new App\Services\RevenueService($client, $src);
            $cw = (array) $svc->topCustomers($w, 25);
            $co = (array) $svc->topCustomers($o, 25);
            $cd = 0.0;
            if (count($cw) !== count($co)) { $shape++; }
            foreach ($co as $i => $row) {
                $compared++;
                $d = abs((float) ($row['revenue'] ?? $row['total'] ?? 0)
                       - (float) ($cw[$i]['revenue'] ?? $cw[$i]['total'] ?? 0));
                if ((string) ($row['name'] ?? '') !== (string) ($cw[$i]['name'] ?? '')) $shape++;
                if ($d > $cd) $cd = $d;
                if ($d > $worst) { $worst = $d; $worstKey = "customer[{$i}]"; }
            }
            ln(sprintf('%-24s %d customers · worst Δ %s', 'top customers', count($co), number_format($cd, 2)));
            ln();
            ln(sprintf('%d value comparisons made', $compared));
            $pass = $compared > 0 && $worst < 0.005 && $shape === 0;
            ln('GATE: worst variance = ' . number_format($worst, 2) . " ({$worstKey})"
                . ($shape ? " · {$shape} shape mismatch(es)" : '')
                . ($compared === 0 ? ' · NOTHING COMPARED' : '')
                . ' — ' . ($pass ? 'PASS ✅' : 'FAIL ❌'));
            break;

        case 'wccheck':
            // GATE — liquidity trend from the warehouse vs from Odoo. Walks
            // every numeric field of every month rather than a chosen few,
            // because this series feeds the CCC and DSO/DPO strips and a
            // single drifting sub-figure would be invisible in a headline.
            $slug = (string) ($_GET['tenant'] ?? 'kwr');
            $tenant = App\Models\Tenant::where('slug', $slug)->first();
            if (!$tenant) { ln("no tenant '{$slug}'"); break; }
            app(App\Tenancy\TenantContext::class)->set($tenant);
            $client = App\Odoo\OdooFactory::fromConfig();
            $run = function (bool $wh) use ($client) {
                config(['bi.warehouse_gl' => $wh]);
                $t0 = microtime(true);
                $r = (new App\Services\WorkingCapitalService($client))->liquidityTrend(12);
                return [$r, microtime(true) - $t0];
            };
            [$w, $tW] = $run(true);
            [$o, $tO] = $run(false);
            config(['bi.warehouse_gl' => true]);

            ln(sprintf('warehouse %.2fs · odoo %.2fs · speed-up %.1f×', $tW, $tO, $tW > 0 ? $tO / $tW : 0));
            ln();
            $sw = (array) ($w['series'] ?? []); $so = (array) ($o['series'] ?? []);
            $worst = 0.0; $worstKey = '—'; $shape = 0; $compared = 0;
            if (count($sw) !== count($so)) {
                $shape++;
                ln(sprintf('series length wh %d · odoo %d — COUNT MISMATCH', count($sw), count($so)));
            }
            $diffs = [];
            foreach ($so as $i => $pt) {
                $mk = (string) ($pt['month'] ?? $pt['label'] ?? $i);
                foreach ((array) $pt as $k => $v) {
                    if (!is_numeric($v)) continue;
                    if (!array_key_exists($k, (array) ($sw[$i] ?? []))) { $shape++; continue; }
                    $compared++;
                    $a1 = (float) $v; $b1 = (float) $sw[$i][$k];
                    $d  = abs($a1 - $b1);
                    if ($d >= 0.005) $diffs[] = [$mk, $k, $b1, $a1, $d];
                    if ($d > $worst) { $worst = $d; $worstKey = "{$k}@{$mk}"; }
                }
            }
            // Show which fields exist at all — a series of nulls compares
            // nothing and would otherwise read as a clean pass.
            $keysO = count($so) ? implode(',', array_keys(array_filter((array) end($so), 'is_numeric'))) : '';
            ln(sprintf('%d months · numeric fields on last point: %s', count($so), $keysO !== '' ? $keysO : '(none)'));
            ln(sprintf('%d value comparisons made', $compared));
            if ($diffs) {
                ln();
                ln(sprintf('%-10s %-14s %18s %18s %14s', 'month', 'field', 'warehouse', 'odoo', 'Δ'));
                foreach (array_slice($diffs, 0, 25) as [$mk, $k, $bw, $bo, $d]) {
                    ln(sprintf('%-10s %-14s %18s %18s %14s', $mk, $k,
                        number_format($bw, 4), number_format($bo, 4), number_format($d, 4)));
                }
                if (count($diffs) > 25) ln(sprintf('… and %d more', count($diffs) - 25));
            }
            ln();
            // A gate that compares nothing must not report success.
            $pass = $compared > 0 && $worst < 0.005 && $shape === 0;
            ln('GATE: worst variance = ' . number_format($worst, 4)
                . ($shape ? " · {$shape} shape mismatch(es)" : '')
                . ($compared === 0 ? ' · NOTHING COMPARED' : '')
                . ' — ' . ($pass ? 'PASS ✅' : 'FAIL ❌'));
            break;

        case 'dashprofile':
            // Where the cold dashboard's seconds actually go, service by
            // service. dashtime gives one total, which is enough to know the
            // page is slow and useless for deciding what to re-point next.
            $slug = (string) ($_GET['tenant'] ?? 'transatlanticd');
            $tenant = App\Models\Tenant::where('slug', $slug)->first();
            if (!$tenant) { ln("no tenant '{$slug}'"); break; }
            app(App\Tenancy\TenantContext::class)->set($tenant);
            if (function_exists('ea_cache_clear')) ea_cache_clear();
            $client = App\Odoo\OdooFactory::fromConfig();
            $year   = (int) date('Y');
            $period = ['type' => 'ytd', 'year' => $year];
            $src    = (string) config_env('REVENUE_SOURCE', 'invoice');

            $timings = [];
            $time = function (string $label, callable $fn) use (&$timings) {
                $t0 = microtime(true);
                try { $out = $fn(); $err = null; }
                catch (\Throwable $e) { $out = null; $err = get_class($e) . ': ' . $e->getMessage(); }
                $timings[] = ['label' => $label, 'secs' => microtime(true) - $t0, 'error' => $err];
                return $out;
            };

            $rev = $time('RevenueService::analyze',
                fn () => (new App\Services\RevenueService($client, $src))->analyze(null, $period));
            $pl = $time('ProfitLossService::analyze',
                fn () => (new App\Services\ProfitLossService($client))->analyze(null, $period));

            $totalRev = (float) ($rev['current']['total'] ?? 0);
            $days     = max(1, (int) ($rev['period']['days'] ?? 1));
            $ccy      = (string) ($rev['currency'] ?? 'XAF');

            $ar = $time('ReceivablesService::analyze',
                fn () => (new App\Services\ReceivablesService($client))->analyze($totalRev, $days, $ccy));
            $time('BillingService::reconciliationOnly',
                fn () => (new App\Services\BillingService($client))->reconciliationOnly(null, $period));
            $time('DashboardController::buildExtraKpis', function () use ($client, $year) {
                $m = new ReflectionMethod(App\Http\Controllers\DashboardController::class, 'buildExtraKpis');
                $m->setAccessible(true);
                return $m->invoke(app(App\Http\Controllers\DashboardController::class), $client, $year);
            });

            $totalExp = max(1.0, (float) ($pl['current']['cogs'] ?? 0)
                + (float) ($pl['current']['opex'] ?? 0) + (float) ($pl['current']['d_and_a'] ?? 0));
            $totalAr  = (float) ($ar['kpis']['total_ar'] ?? $ar['total_ar'] ?? 0);

            $ap = $time('PayablesService::analyze',
                fn () => (new App\Services\PayablesService($client))->analyze($totalExp, 365, $ccy));
            $totalAp = (float) ($ap['kpis']['total_ap'] ?? 0);

            $time('ReceivablesService::dsoRunningSeries',
                fn () => (new App\Services\ReceivablesService($client))->dsoRunningSeries($totalAr, $totalRev, 365, $year));
            $time('PayablesService::dpoRunningSeries',
                fn () => (new App\Services\PayablesService($client))->dpoRunningSeries($totalAp, $totalExp, 365, $year));
            $time('PLForecastService::analyze',
                fn () => (new App\Services\PLForecastService($client))->analyze($year));
            $time('WorkingCapitalService::liquidityTrend',
                fn () => (new App\Services\WorkingCapitalService($client))->liquidityTrend(12));

            usort($timings, fn ($a, $b) => $b['secs'] <=> $a['secs']);
            $sum = array_sum(array_column($timings, 'secs'));
            ln("== cold dashboard profile · {$slug} ==");
            ln();
            foreach ($timings as $r) {
                ln(sprintf('%7.2fs  %5.1f%%  %-38s %s',
                    $r['secs'], $sum > 0 ? $r['secs'] / $sum * 100 : 0, $r['label'],
                    $r['error'] ? ('ERR ' . mb_substr($r['error'], 0, 60)) : ''));
            }
            ln();
            ln(sprintf('%7.2fs  total across %d services', $sum, count($timings)));
            break;

        case 'dashtime':
            // P1 outcome measure — cold dashboard compute, cache cleared.
            $slug = (string) ($_GET['tenant'] ?? 'transatlanticd');
            $tenant = App\Models\Tenant::where('slug', $slug)->first();
            if (!$tenant) { ln("no tenant '{$slug}'"); break; }
            app(App\Tenancy\TenantContext::class)->set($tenant);
            if (function_exists('ea_cache_clear')) ea_cache_clear();
            $req = Illuminate\Http\Request::create('/t/' . $slug, 'GET', ['period' => (string) ($_GET['period'] ?? 'ytd')]);
            app()->instance('request', $req);
            try { $s = app('session')->driver(); $s->start(); $req->setLaravelSession($s); } catch (\Throwable $e) {}
            $u = App\Models\User::where('tenant_id', $tenant->id)->orderBy('id')->first();
            if ($u) { try { Illuminate\Support\Facades\Auth::login($u); } catch (\Throwable $e) {} }
            $t0 = microtime(true);
            $resp = app()->call([app(App\Http\Controllers\DashboardController::class), 'index']);
            $tData = microtime(true) - $t0;
            $t1 = microtime(true);
            $html = ($resp instanceof Illuminate\Contracts\Support\Renderable) ? $resp->render() : '';
            $tRender = microtime(true) - $t1;
            ln(sprintf('COLD dashboard (%s): data %.1fs · render %.1fs · TOTAL %.1fs · %d bytes',
                $slug, $tData, $tRender, $tData + $tRender, strlen((string) $html)));
            ln('baseline before P1: 266.0s');
            break;

        case 'revcheck_unused':
            $worst = 0.0;
            foreach ([['current', 'total'], ['current', 'outfits'], ['current', 'customers'],
                      ['current', 'total_discount'], ['current', 'discounted_outfits'],
                      ['prior', 'total'], ['prior', 'outfits']] as [$grp, $k]) {
                $n = (float) ($new[$grp][$k] ?? 0); $o = (float) ($old[$grp][$k] ?? 0);
                $worst = max($worst, abs($n - $o));
                ln(sprintf('%-8s %-20s new %18s · old %18s · Δ %s', $grp, $k,
                    number_format($n, 2), number_format($o, 2), number_format($n - $o, 2)));
            }
            $sN = (array) ($new['current']['series'] ?? []); $sO = (array) ($old['current']['series'] ?? []);
            $sd = 0.0;
            foreach ($sO as $d => $v) $sd = max($sd, abs((float) $v - (float) ($sN[$d] ?? 0)));
            $worst = max($worst, $sd);
            ln(sprintf('%-8s %-20s %d days · worst daily Δ %s', 'series', 'daily', count($sO), number_format($sd, 2)));
            ln();
            ln('GATE: worst variance = ' . number_format($worst, 2) . ' — ' . ($worst < 0.005 ? 'PASS ✅' : 'FAIL ❌'));
            break;

        case 'plmonth':
            // Isolate ONE month and prefix. parent_state is a parent-derived
            // field cached on the line: posting a move flips move.state and so
            // line.parent_state, but does not necessarily bump the LINE's
            // write_date - which is what the sync watermarks on. A move posted
            // after its lines were last read would keep a stale state in the
            // warehouse permanently, and syncing would never correct it.
            // Comparing with and without the posted filter says whether that
            // is what is happening.
            $slug = (string) ($_GET['tenant'] ?? 'kwr');
            $tenant = App\Models\Tenant::where('slug', $slug)->first();
            if (!$tenant) { ln("no tenant '{$slug}'"); break; }
            app(App\Tenancy\TenantContext::class)->set($tenant);
            $client = App\Odoo\OdooFactory::fromConfig();
            $year = (int) ($_GET['year'] ?? date('Y'));
            $mon  = (int) ($_GET['month'] ?? 7);
            $pref = (string) ($_GET['prefix'] ?? '7');
            $mStart = sprintf('%04d-%02d-01', $year, $mon);
            $mEnd   = date('Y-m-t', strtotime($mStart));

            $accts = $client->searchRead('account.account', [['code', '=like', $pref . '%']], ['id', 'code'], []);
            $ids = array_map(fn ($a) => (int) $a['id'], $accts);
            ln("== {$slug} · {$year}-{$mon} · prefix {$pref} · " . count($ids) . " accounts ==");
            if (!$ids) break;

            $og = $client->callModel('account.move.line', 'read_group', [
                'domain' => [['parent_state', '=', 'posted'], ['date', '>=', $mStart], ['date', '<=', $mEnd],
                             ['account_id', 'in', $ids]],
                'fields' => ['balance:sum'], 'groupby' => [], 'lazy' => false,
            ]);
            $oBal = (float) (($og[0]['balance'] ?? 0));

            $q = fn () => Illuminate\Support\Facades\DB::table('wh_fact_gl_line')
                ->where('tenant_id', $tenant->id)
                ->whereBetween('gl_date', [$mStart, $mEnd])
                ->whereIn('account_odoo_id', $ids);
            $wPosted = (float) ($q()->where('parent_state', 'posted')
                ->selectRaw('SUM(debit) - SUM(credit) AS b')->value('b') ?? 0);
            $wAny    = (float) ($q()->selectRaw('SUM(debit) - SUM(credit) AS b')->value('b') ?? 0);

            ln(sprintf('odoo   posted            %18s', number_format($oBal, 2)));
            ln(sprintf('wh     parent_state=posted %16s · Δ vs odoo %s',
                number_format($wPosted, 2), number_format($wPosted - $oBal, 2)));
            ln(sprintf('wh     ANY parent_state    %16s · Δ vs odoo %s',
                number_format($wAny, 2), number_format($wAny - $oBal, 2)));
            ln();
            ln('warehouse lines by parent_state:');
            foreach ($q()->selectRaw("COALESCE(parent_state,'(null)') AS ps, COUNT(*) n,
                                      SUM(debit) - SUM(credit) AS b")
                ->groupBy('parent_state')->get() as $r) {
                ln(sprintf('  %-12s %8s lines · balance %18s',
                    $r->ps, number_format((int) $r->n), number_format((float) $r->b, 2)));
            }
            ln();
            // Row-count sanity: is the warehouse simply missing lines here?
            $oCount = $client->searchCount('account.move.line',
                [['parent_state', '=', 'posted'], ['date', '>=', $mStart], ['date', '<=', $mEnd],
                 ['account_id', 'in', $ids]]);
            $wCount = (int) $q()->where('parent_state', 'posted')->count();
            ln(sprintf('posted line count  odoo %s · warehouse %s · Δ %d',
                number_format($oCount), number_format($wCount), $wCount - $oCount));
            break;

        case 'placcts':
            // Do the two paths even sum over the same accounts? fetchAccountsInScope
            // is warehouse-backed too, so a difference in the ACCOUNT SET shows up
            // as a balance variance and looks like a maths bug. Odoo's searchRead
            // hides archived records by default; the warehouse keeps whatever was
            // true when it synced.
            $slug = (string) ($_GET['tenant'] ?? 'kwr');
            $tenant = App\Models\Tenant::where('slug', $slug)->first();
            if (!$tenant) { ln("no tenant '{$slug}'"); break; }
            app(App\Tenancy\TenantContext::class)->set($tenant);
            $client = App\Odoo\OdooFactory::fromConfig();

            $odooAcc = $client->searchRead('account.account',
                ['|', '|', ['code', '=like', '6%'], ['code', '=like', '7%'], ['code', '=like', '89%']],
                ['id', 'code', 'name'], []);
            $whAcc = Illuminate\Support\Facades\DB::table('wh_dim_account')
                ->where('tenant_id', $tenant->id)
                ->where(function ($q) { foreach (['6', '7', '89'] as $p) $q->orWhere('code', 'like', $p . '%'); })
                ->get(['odoo_id', 'code', 'name']);

            $oMap = []; foreach ($odooAcc as $a) $oMap[(int) $a['id']] = (string) ($a['code'] ?? '');
            $wMap = []; foreach ($whAcc as $a) $wMap[(int) $a->odoo_id] = (string) ($a->code ?? '');

            ln("== accounts in P&L scope (6/7/89) · {$slug} ==");
            ln(sprintf('odoo      %s', number_format(count($oMap))));
            ln(sprintf('warehouse %s', number_format(count($wMap))));
            $onlyWh = array_diff_key($wMap, $oMap);
            $onlyOd = array_diff_key($oMap, $wMap);
            ln(sprintf('in warehouse only %s · in odoo only %s', number_format(count($onlyWh)), number_format(count($onlyOd))));
            ln();
            if ($onlyWh) {
                ln('IN WAREHOUSE ONLY (odoo hides archived accounts by default):');
                foreach (array_slice($onlyWh, 0, 15, true) as $id => $code) {
                    $bal = Illuminate\Support\Facades\DB::table('wh_fact_gl_line')
                        ->where('tenant_id', $tenant->id)->where('account_odoo_id', $id)
                        ->where('parent_state', 'posted')
                        ->whereBetween('gl_date', [date('Y') . '-01-01', date('Y') . '-12-31'])
                        ->selectRaw('SUM(debit) - SUM(credit) AS bal, COUNT(*) n')->first();
                    ln(sprintf('  id %-8s code %-12s ytd balance %16s over %s lines',
                        $id, $code, number_format((float) ($bal->bal ?? 0), 2), number_format((int) ($bal->n ?? 0))));
                }
            }
            if ($onlyOd) {
                ln('IN ODOO ONLY (warehouse account dimension is behind):');
                foreach (array_slice($onlyOd, 0, 15, true) as $id => $code) ln(sprintf('  id %-8s code %s', $id, $code));
            }
            break;

        case 'plcheck':
            // GATE — P&L forecast from the warehouse vs Odoo. Walks every
            // line's twelve monthly values, not just the totals: the KPI strip
            // reads monthly margins, so a month could drift while the annual
            // figure still ties.
            $slug = (string) ($_GET['tenant'] ?? 'kwr');
            $tenant = App\Models\Tenant::where('slug', $slug)->first();
            if (!$tenant) { ln("no tenant '{$slug}'"); break; }
            app(App\Tenancy\TenantContext::class)->set($tenant);
            $client = App\Odoo\OdooFactory::fromConfig();
            $year = (int) ($_GET['year'] ?? date('Y'));
            $run = function (bool $wh) use ($client, $year) {
                config(['bi.warehouse_gl' => $wh]);
                $t0 = microtime(true);
                $r = (new App\Services\PLForecastService($client))->analyze($year);
                return [$r, microtime(true) - $t0];
            };
            [$w, $o] = [null, null];
            [$w, $tW] = $run(true);
            [$o, $tO] = $run(false);
            config(['bi.warehouse_gl' => true]);

            ln(sprintf('warehouse %.2fs · odoo %.2fs · speed-up %.1f×', $tW, $tO, $tW > 0 ? $tO / $tW : 0));
            ln();
            $lw = (array) ($w['lines'] ?? []); $lo = (array) ($o['lines'] ?? []);
            $byKeyW = [];
            foreach ($lw as $L) $byKeyW[(string) ($L['key'] ?? '')] = $L;
            $worst = 0.0; $worstKey = '—'; $compared = 0; $shape = 0;
            if (count($lw) !== count($lo)) { $shape++; ln(sprintf('line count wh %d · odoo %d — MISMATCH', count($lw), count($lo))); }
            foreach ($lo as $L) {
                $k = (string) ($L['key'] ?? '');
                if (!isset($byKeyW[$k])) { $shape++; continue; }
                foreach ((array) ($L['monthly'] ?? []) as $m => $v) {
                    if (!is_numeric($v)) continue;
                    $b1 = $byKeyW[$k]['monthly'][$m] ?? null;
                    if ($b1 === null) { $shape++; continue; }
                    $compared++;
                    $d = abs((float) $v - (float) $b1);
                    if ($d > $worst) { $worst = $d; $worstKey = "{$k}[m{$m}]"; }
                }
            }
            ln(sprintf('%d lines · %d monthly values compared', count($lo), $compared));
            ln(sprintf('thru_month wh %s · odoo %s', (string) ($w['thru_month'] ?? '—'), (string) ($o['thru_month'] ?? '—')));
            if ((string) ($w['thru_month'] ?? '') !== (string) ($o['thru_month'] ?? '')) $shape++;
            ln();
            $pass = $compared > 0 && $worst < 0.005 && $shape === 0;
            ln('GATE: worst variance = ' . number_format($worst, 4) . " ({$worstKey})"
                . ($shape ? " · {$shape} shape mismatch(es)" : '')
                . ($compared === 0 ? ' · NOTHING COMPARED' : '')
                . ' — ' . ($pass ? 'PASS ✅' : 'FAIL ❌'));
            break;

        case 'reconwhcheck':
            // GATE — reconciliation buckets warehouse vs Odoo, holding the
            // batched path on BOTH sides. reconcheck toggles recon_batched
            // instead, which pits the warehouse against the original
            // per-month loop: ~13 Odoo calls a month, and on transatlanticd
            // that arm alone runs past the gateway's ~100s ceiling and
            // returns 524 before any comparison happens. Toggling only the
            // warehouse keeps both arms fast and isolates the actual change.
            $slug = (string) ($_GET['tenant'] ?? 'kwr');
            $tenant = App\Models\Tenant::where('slug', $slug)->first();
            if (!$tenant) { ln("no tenant '{$slug}'"); break; }
            app(App\Tenancy\TenantContext::class)->set($tenant);
            $client = App\Odoo\OdooFactory::fromConfig();
            $period = ['type' => 'ytd', 'year' => (int) date('Y')];
            $run = function (bool $wh) use ($client, $period) {
                config(['bi.recon_batched' => true, 'bi.warehouse_revenue' => $wh]);
                $t0 = microtime(true);
                $r = (new App\Services\BillingService($client))->reconciliationOnly(null, $period);
                return [$r, microtime(true) - $t0];
            };
            [$w, $tW] = $run(true);
            [$o, $tO] = $run(false);
            config(['bi.warehouse_revenue' => true]);

            ln(sprintf('warehouse %.1fs · odoo %.1fs · speed-up %.1f×', $tW, $tO, $tW > 0 ? $tO / $tW : 0));
            ln();
            ln(sprintf('%-6s %18s %18s %18s', 'month', 'Δ expected', 'Δ billed', 'Δ collected'));
            $nb = (array) ($w['buckets'] ?? []); $ob = (array) ($o['buckets'] ?? []);
            $worst = 0.0; $compared = 0;
            if (count($nb) !== count($ob)) ln(sprintf('bucket count wh %d · odoo %d — MISMATCH', count($nb), count($ob)));
            foreach ($ob as $i => $ov) {
                $nv = $nb[$i] ?? ['expected' => 0, 'billed' => 0, 'collected' => 0];
                $d = [
                    (float) $nv['expected']  - (float) $ov['expected'],
                    (float) $nv['billed']    - (float) $ov['billed'],
                    (float) $nv['collected'] - (float) $ov['collected'],
                ];
                foreach ($d as $x) { $worst = max($worst, abs($x)); $compared++; }
                ln(sprintf('%-6s %18s %18s %18s', $ov['label'] ?? '?',
                    number_format($d[0], 2), number_format($d[1], 2), number_format($d[2], 2)));
            }
            ln();
            foreach (['expected', 'billed', 'collected'] as $k) {
                $dn = (float) ($w['headline'][$k] ?? 0); $dd = (float) ($o['headline'][$k] ?? 0);
                $worst = max($worst, abs($dn - $dd)); $compared++;
                ln(sprintf('headline %-10s wh %18s · odoo %18s · Δ %s', $k,
                    number_format($dn, 2), number_format($dd, 2), number_format($dn - $dd, 2)));
            }
            ln();
            ln(sprintf('%d value comparisons made', $compared));
            $pass = $compared > 0 && $worst < 0.005;
            ln('GATE: worst variance = ' . number_format($worst, 2)
                . ($compared === 0 ? ' · NOTHING COMPARED' : '')
                . ' — ' . ($pass ? 'PASS ✅' : 'FAIL ❌'));
            break;

        case 'reconcheck':
            // P1 GATE — dual-run: batched reconciliation vs the original
            // per-month loop, month by month, with timings. The batched path
            // ships only if every variance is 0.00. ?tenant=slug
            $slug = (string) ($_GET['tenant'] ?? 'transatlanticd');
            $tenant = App\Models\Tenant::where('slug', $slug)->first();
            if (!$tenant) { ln("no tenant '{$slug}'"); break; }
            app(App\Tenancy\TenantContext::class)->set($tenant);
            $client = App\Odoo\OdooFactory::fromConfig();
            $period = ['type' => 'ytd', 'year' => (int) date('Y')];

            $run = function (bool $batched) use ($client, $period) {
                config(['bi.recon_batched' => $batched]);
                $t0 = microtime(true);
                $r = (new App\Services\BillingService($client))->reconciliationOnly(null, $period);
                return [$r, microtime(true) - $t0];
            };
            [$new, $tNew] = $run(true);
            [$old, $tOld] = $run(false);
            config(['bi.recon_batched' => true]);

            ln(sprintf('batched %.1fs · per-month loop %.1fs · speed-up %.1f×', $tNew, $tOld, $tOld > 0 ? $tOld / $tNew : 0));
            ln();
            ln(sprintf('%-6s %18s %18s %18s', 'month', 'Δ expected', 'Δ billed', 'Δ collected'));
            $nb = (array) ($new['buckets'] ?? []); $ob = (array) ($old['buckets'] ?? []);
            $worst = 0.0;
            foreach ($ob as $i => $o) {
                $n = $nb[$i] ?? ['expected' => 0, 'billed' => 0, 'collected' => 0];
                $d = [
                    (float) $n['expected']  - (float) $o['expected'],
                    (float) $n['billed']    - (float) $o['billed'],
                    (float) $n['collected'] - (float) $o['collected'],
                ];
                foreach ($d as $x) $worst = max($worst, abs($x));
                ln(sprintf('%-6s %18s %18s %18s', $o['label'] ?? '?',
                    number_format($d[0], 2), number_format($d[1], 2), number_format($d[2], 2)));
            }
            ln();
            foreach (['expected', 'billed', 'collected'] as $k) {
                $dn = (float) ($new['headline'][$k] ?? 0); $do = (float) ($old['headline'][$k] ?? 0);
                $worst = max($worst, abs($dn - $do));
                ln(sprintf('headline %-10s new %18s · old %18s · Δ %s', $k,
                    number_format($dn, 2), number_format($do, 2), number_format($dn - $do, 2)));
            }
            ln();
            ln('GATE: worst variance = ' . number_format($worst, 2) . ' — ' . ($worst < 0.005 ? 'PASS ✅' : 'FAIL ❌'));
            break;

        case 'migstatus':
            // Which migrations Laravel thinks have run. The deploy workflow
            // dies when a migration is pending but its table already exists.
            Illuminate\Support\Facades\Artisan::call('migrate:status');
            ln(rtrim(Illuminate\Support\Facades\Artisan::output()));
            break;

        case 'migmark':
            // Record a migration as run WITHOUT executing it — the repair for
            // "table already exists". Refuses unless the table is genuinely
            // present, so a migration that really needs to run can never be
            // skipped by accident. ?name=<migration_file_stem>&table=<table>
            $name  = (string) ($_GET['name'] ?? '');
            $table = (string) ($_GET['table'] ?? '');
            if ($name === '' || $table === '') { ln('need ?name=<migration>&table=<table>'); break; }
            if (!Illuminate\Support\Facades\Schema::hasTable($table)) {
                ln("REFUSED: table '{$table}' does not exist — this migration should actually run, not be marked.");
                break;
            }
            if (Illuminate\Support\Facades\DB::table('migrations')->where('migration', $name)->exists()) {
                ln("already recorded: {$name}");
                break;
            }
            $batch = (int) Illuminate\Support\Facades\DB::table('migrations')->max('batch');
            Illuminate\Support\Facades\DB::table('migrations')->insert(['migration' => $name, 'batch' => $batch ?: 1]);
            ln("marked as run: {$name} (table '{$table}' verified present, batch {$batch})");
            break;

        case 'migrate':
            // Run pending migrations — the same command the deploy workflow
            // runs, so a clean result here means the workflow will pass.
            $code = Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
            ln(rtrim(Illuminate\Support\Facades\Artisan::output()));
            ln('exit=' . $code);
            break;

        case 'sendoffenders':
            // Remote trigger for the receivables Top-Offender Scorecard.
            // ?tenant=kwr (default) &to=a@b.com,... (default: config list)
            $args = ['--tenant' => (string) ($_GET['tenant'] ?? 'kwr')];
            if (!empty($_GET['to'])) $args['--to'] = (string) $_GET['to'];
            $code = Illuminate\Support\Facades\Artisan::call('reports:send-offenders', $args);
            ln(rtrim(Illuminate\Support\Facades\Artisan::output()));
            ln('exit=' . $code);
            break;

        case 'recompile':
            // Force-recompile EVERY Blade template in place. Overwrite is
            // permitted (compiled files are web-user-owned); this repairs a
            // half-written/corrupted compiled view after a compile race.
            $compiler = app('blade.compiler');
            $ok = 0; $bad = [];
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base . '/resources/views', FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (!str_ends_with($f->getFilename(), '.blade.php')) continue;
                try { $compiler->compile($f->getPathname()); $ok++; }
                catch (\Throwable $e) { $bad[] = $f->getFilename() . ': ' . mb_substr($e->getMessage(), 0, 90); }
            }
            if (function_exists('opcache_reset')) @opcache_reset();
            ln("recompiled {$ok} templates · failures: " . count($bad));
            foreach (array_slice($bad, 0, 40) as $b) ln('  ✗ ' . $b);
            break;

        case 'viewfile':
            // Read a compiled view (hash-constrained, read-only) or a source
            // view by relative path — the ground truth when a compiled file
            // is suspected stale or corrupted. ?hash=<md5> or ?src=<rel>
            // with optional &from=&to= line range.
            $from = max(1, (int) ($_GET['from'] ?? 1));
            $to   = max($from, min($from + 400, (int) ($_GET['to'] ?? ($from + 60))));
            if (($h = preg_replace('/[^a-f0-9]/', '', (string) ($_GET['hash'] ?? ''))) !== '') {
                $p = $base . '/storage/framework/views/' . $h . '.php';
            } else {
                $rel = str_replace(['..', '\\'], '', (string) ($_GET['src'] ?? 'layouts/app.blade.php'));
                $p = $base . '/resources/views/' . ltrim($rel, '/');
            }
            if (!is_file($p)) { ln("missing: {$p}"); break; }
            ln("== {$p} (lines {$from}-{$to}) · mtime " . date('c', filemtime($p)) . ' · ' . filesize($p) . " bytes ==");
            $i = 0;
            $fh = fopen($p, 'r');
            while (($row = fgets($fh)) !== false) {
                $i++;
                if ($i < $from) continue;
                if ($i > $to) break;
                ln(sprintf('%5d| %s', $i, rtrim($row, "\n")));
            }
            fclose($fh);
            break;

        case 'render':
            // Reproduce a page render exactly like a logged-in visitor and
            // print the true exception if it fails. ?tenant=slug&page=label
            $slug = (string) ($_GET['tenant'] ?? 'kwr');
            $want = (string) ($_GET['page'] ?? 'dashboard');
            $tenant = App\Models\Tenant::where('slug', $slug)->first();
            if (!$tenant) { ln("no tenant '{$slug}'"); break; }
            app(App\Tenancy\TenantContext::class)->set($tenant);
            $def = null;
            foreach ((array) config('warm.pages', []) as $p) {
                if (($p['label'] ?? '') === $want) { $def = $p; break; }
            }
            if (!$def) { ln("no page '{$want}' in config/warm.php"); break; }
            $req = Illuminate\Http\Request::create('/t/' . $slug, 'GET', (array) ($def['query'] ?? []));
            app()->instance('request', $req);
            try { $s = app('session')->driver(); $s->start(); $req->setLaravelSession($s); } catch (\Throwable $e) {}
            $u = App\Models\User::where('tenant_id', $tenant->id)->orderBy('id')->first();
            if ($u) { try { Illuminate\Support\Facades\Auth::login($u); } catch (\Throwable $e) {} }
            // Put a flash in the session to exercise the toast path too
            // (skip with &noflash=1 to isolate flash-dependent failures).
            if (!isset($_GET['noflash'])) {
                session()->flash('flash', ['type' => 'success', 'msg' => 'render-test']);
            }
            try {
                $resp = app()->call([app((string) $def['controller']), 'index']);
                $html = ($resp instanceof Illuminate\Contracts\Support\Renderable) ? $resp->render() : '(non-view response: ' . get_class($resp) . ')';
                ln("RENDER OK — {$slug}/{$want} — " . strlen((string) $html) . ' bytes');
            } catch (\Throwable $e) {
                ln("RENDER FAILED — {$slug}/{$want}");
                ln('  ' . get_class($e) . ': ' . $e->getMessage());
                ln('  @ ' . $e->getFile() . ':' . $e->getLine());
                foreach (array_slice(explode("\n", $e->getTraceAsString()), 0, 4) as $tline) ln('  ' . $tline);
            }
            break;

        case 'collcheck':
            // Cash-basis collections verification: settlements applied in
            // the window, routed to streams, HT + TTC, with internal
            // tie-outs. ?tenant=slug &period=&year=&month=
            $slug = (string) ($_GET['tenant'] ?? 'transatlanticd');
            $tenant = App\Models\Tenant::where('slug', $slug)->first();
            if (!$tenant) { ln("no tenant '{$slug}'"); break; }
            app(App\Tenancy\TenantContext::class)->set($tenant);
            $client = App\Odoo\OdooFactory::fromConfig();
            $periodSel = [
                'type'  => in_array(($pt = strtolower((string) ($_GET['period'] ?? 'ytd'))), ['ytd', 'mtd', 'month', 'yoy'], true) ? $pt : 'ytd',
                'year'  => (int) ($_GET['year'] ?? date('Y')),
                'month' => (int) ($_GET['month'] ?? date('m')),
            ];
            $t0 = microtime(true);
            $svc = new App\Services\CollectionsByStreamService($client, new App\Services\GLRevenueService($client));
            $c = $svc->analyze(null, $periodSel);
            ln(sprintf('analyze: %.1fs · period=%s · settlements=%d · invoices touched=%d · as of %s',
                microtime(true) - $t0, json_encode($periodSel), $c['meta']['settlements'], $c['meta']['invoices'], $c['as_of']));
            $cols = $c['columns'];
            $hdr = sprintf('%-34s', 'stream');
            foreach ($cols as $cc) $hdr .= sprintf(' %16s', $cc['label']);
            ln($hdr);
            $rowOut = function (string $label, array $row) use ($cols) {
                $line = sprintf('%-34s', mb_substr($label, 0, 34));
                foreach ($cols as $cc) $line .= sprintf(' %16s', number_format((float) ($row[$cc['k']] ?? 0)));
                ln($line);
            };
            foreach ($c['streams'] as $s) $rowOut($s['label'], $s);
            $rowOut('UNMATCHED 70x (' . count($c['unmatched']['accounts']) . ')', $c['unmatched']);
            $rowOut('Other income (non-70x)', $c['other']);
            $rowOut('TOTAL', $c['totals']);
            $sumHt = $c['unmatched']['ytd_ht'] + $c['other']['ytd_ht'];
            $sumTtc = $c['unmatched']['ytd_ttc'] + $c['other']['ytd_ttc'];
            foreach ($c['streams'] as $s) { $sumHt += $s['ytd_ht']; $sumTtc += $s['ytd_ttc']; }
            ln('tie-out YTD: rows−totals HT=' . number_format($sumHt - $c['totals']['ytd_ht'], 2)
                . ' · TTC=' . number_format($sumTtc - $c['totals']['ytd_ttc'], 2) . ' (expect 0.00)');
            if (!empty($c['monthly']['series'])) {
                $ttcByKey = [];
                foreach ($c['streams'] as $s) $ttcByKey[$s['key']] = (float) $s['ytd_ttc'];
                $drift = 0.0;
                foreach ($c['monthly']['series'] as $s) {
                    if (!isset($ttcByKey[$s['key']])) continue;
                    $drift = max($drift, abs(array_sum($s['data']) - $ttcByKey[$s['key']]));
                }
                ln('monthly chart (TTC): ' . count($c['monthly']['labels']) . ' months · '
                    . count($c['monthly']['series']) . ' series · max drift vs YTD = ' . number_format($drift, 2));
            }
            break;

        case 'unlock':
            $n = Illuminate\Support\Facades\DB::table('cache_locks')
                ->where('key', 'like', '%bms:warm:lock%')->delete();
            ln("released sweep lock rows: {$n}");
            break;

        case 'opcachereset':
            if (function_exists('opcache_reset')) {
                ln('opcache_reset: ' . json_encode(opcache_reset()));
            } else {
                ln('opcache not available in web SAPI');
            }
            break;

        default: // status
            ln('== ops status @ ' . date('c') . ' ==');
            ln('php (web sapi): ' . PHP_VERSION . ' / ' . PHP_SAPI);
            foreach (['app/Support/helpers.php', 'config/warm.php', 'app/Console/Commands/WarmPages.php', 'config/bi.php'] as $f) {
                $p = $base . '/' . $f;
                ln(sprintf('  %-38s mtime %s', $f, is_file($p) ? date('c', filemtime($p)) : 'MISSING'));
            }
            ln('  bi.cache_ttl (web sees) = ' . var_export(config('bi.cache_ttl'), true)
                . ' · ea_data_timestamp() exists = ' . json_encode(function_exists('ea_data_timestamp')));
            if (function_exists('opcache_get_status') && ($st = @opcache_get_status(false))) {
                $cfg = function_exists('opcache_get_configuration') ? (array) @opcache_get_configuration() : [];
                $dirs = (array) ($cfg['directives'] ?? []);
                ln('  opcache: enabled=' . json_encode($st['opcache_enabled'] ?? null)
                    . ' validate_timestamps=' . json_encode($dirs['opcache.validate_timestamps'] ?? null)
                    . ' revalidate_freq=' . json_encode($dirs['opcache.revalidate_freq'] ?? null));
            } else {
                ln('  opcache: not active');
            }
            ln('  storage/logs writable by web user: ' . json_encode(is_writable(storage_path('logs')))
                . ' · tmp: ' . json_encode(is_writable(sys_get_temp_dir())));
            $found = false;
            foreach ([storage_path('logs/warm-metrics.jsonl'), rtrim(sys_get_temp_dir(), '/\\') . '/bms-warm-metrics.jsonl'] as $mf) {
                if (!is_file($mf)) continue;
                $found = true;
                $cnt = 0; $fh = fopen($mf, 'r');
                while (fgets($fh) !== false) $cnt++;
                fclose($fh);
                ln("  metrics {$mf}: {$cnt} lines · last: " . tailFile($mf, 1));
            }
            if (!$found) ln('  metrics: NO FILE in either location — no page has completed since the metrics fix deployed');
            // Is a sweep process holding the cron-level flock right now?
            $fh = @fopen('/tmp/bms-warm.lock', 'c');
            if ($fh) {
                $free = flock($fh, LOCK_EX | LOCK_NB);
                if ($free) flock($fh, LOCK_UN);
                fclose($fh);
                ln('  cron flock /tmp/bms-warm.lock: ' . ($free ? 'free' : 'HELD — a sweep process is alive right now'));
            }
            try {
                $rows = Illuminate\Support\Facades\DB::table('cache_locks')
                    ->where('key', 'like', '%bms:warm:lock%')->get();
                if ($rows->isEmpty()) {
                    ln('  sweep lock: free');
                } else {
                    foreach ($rows as $r) {
                        $left = (int) $r->expiration - time();
                        ln("  sweep lock: HELD (owner {$r->owner}, expires in {$left}s)"
                            . ($left > 0 ? ' — a live or crashed sweep' : ' — EXPIRED ROW'));
                    }
                }
            } catch (\Throwable $e) {
                ln('  sweep lock: check failed — ' . $e->getMessage());
            }
            ln('  warm log tail: ' . str_replace("\n", ' | ', tailFile('/var/log/bms-warm.log', 3)));
    }
} catch (\Throwable $e) {
    ln('EXC: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}
