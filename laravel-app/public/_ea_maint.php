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

        case 'mailcheck':
            // Mail config truth: which driver actually handles Mail:: sends,
            // whether the smtp mailer is fully provisioned regardless of the
            // default, and whether the MailerSend HTTP API key is present.
            // Booleans only for secrets — values never leave the box.
            ln('mail.default            = ' . config('mail.default'));
            $smtp = (array) config('mail.mailers.smtp', []);
            ln('smtp.host               = ' . ($smtp['host'] ?? '—'));
            ln('smtp.port               = ' . ($smtp['port'] ?? '—'));
            ln('smtp.scheme/encryption  = ' . ($smtp['scheme'] ?? $smtp['encryption'] ?? '—'));
            ln('smtp.username           = ' . ($smtp['username'] ?? '—'));
            ln('smtp.password set       = ' . (!empty($smtp['password']) ? 'yes' : 'NO'));
            ln('from                    = ' . config('mail.from.name') . ' <' . config('mail.from.address') . '>');
            ln('MAILERSEND_API_KEY set  = ' . (trim((string) config('daily_report.mailersend_api_key', '')) !== '' ? 'yes' : 'NO'));
            // Raw .env lines (keys only, secret values masked) so a stale
            // config cache vs .env mismatch is visible too.
            foreach (@file(base_path('.env')) ?: [] as $line) {
                if (preg_match('/^(MAIL_[A-Z_]+|MAILERSEND_API_KEY)=(.*)$/', trim($line), $m)) {
                    $v = $m[2];
                    $masked = in_array($m[1], ['MAIL_PASSWORD', 'MAILERSEND_API_KEY'], true)
                        ? ($v === '' ? '(empty)' : '(set, ' . strlen($v) . ' chars)') : $v;
                    ln('.env ' . str_pad($m[1], 22) . ' = ' . $masked);
                }
            }
            break;

        case 'mailtest':
            // Send one real test email (the exact WelcomeUserMail rendering
            // path) to &to=addr through whatever driver is active, and print
            // the outcome. Complements mailcheck: config says vs. wire says.
            $to = trim((string) ($_GET['to'] ?? ''));
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { ln('pass &to=someone@example.com'); break; }
            $dummy = new App\Models\User([
                'name' => 'Mail Test', 'email' => $to,
                'role' => App\Models\User::ROLE_VIEWER, 'tenant_id' => null,
            ]);
            // The CLI-style bootstrap has no bound request, and both url()
            // and the mail layout need one to build absolute links.
            $req = Illuminate\Http\Request::create((string) config('app.url', 'https://bms.cm-ea.com'), 'GET');
            app()->instance('request', $req);
            ln('driver: ' . config('mail.default'));
            try {
                $t0 = microtime(true);
                Illuminate\Support\Facades\Mail::to($to)->send(new App\Mail\WelcomeUserMail(
                    $dummy, '••• TEST — not a real password •••', url('/admin/login')
                ));
                ln('SENT to ' . $to . ' in ' . (int) round((microtime(true) - $t0) * 1000) . 'ms');
            } catch (\Throwable $e) {
                ln('SEND FAILED: ' . get_class($e) . ': ' . $e->getMessage());
            }
            break;

        case 'resttest':
            // Probe a tenant's REST-gateway ERP source (ERP_TYPE=rest) and
            // report warehouse coverage. ?tenant=slug&source=2
            $slug = (string) ($_GET['tenant'] ?? '');
            $srcN = max(1, min(2, (int) ($_GET['source'] ?? 1)));
            $tenant = App\Models\Tenant::where('slug', $slug)->first();
            if (!$tenant) { ln("no tenant '{$slug}'"); break; }
            app(App\Tenancy\TenantContext::class)->set($tenant);
            $src = App\Services\DataSource::of($srcN);
            ln('source ' . $srcN . ' type=' . $src->type() . ' configured=' . ($src->configured() ? 'yes' : 'no') . ' label=' . $src->label());
            $t = App\Services\SageRestClient::testCurrent($src);
            ln('test: ' . ($t['ok'] ? 'OK' : 'FAIL') . ' · ' . $t['latency_ms'] . 'ms · ' . $t['message']);
            $agg = Illuminate\Support\Facades\DB::table('wh_sage_ecriture')
                ->where('tenant_id', $tenant->id)->where('source', $srcN)
                ->selectRaw('COUNT(*) n, MIN(ecr_date) d0, MAX(ecr_date) d1, COUNT(DISTINCT account) accts,
                             SUM(CASE WHEN sens = 0 THEN amount ELSE 0 END) deb,
                             SUM(CASE WHEN sens = 1 THEN amount ELSE 0 END) cre')->first();
            ln('warehouse: ' . number_format((int) $agg->n) . ' rows · ' . ($agg->d0 ?? '—') . ' → ' . ($agg->d1 ?? '—')
                . ' · ' . (int) $agg->accts . ' accounts · débit ' . number_format((float) $agg->deb, 0, '.', ' ')
                . ' · crédit ' . number_format((float) $agg->cre, 0, '.', ' '));
            $st = Illuminate\Support\Facades\DB::table('wh_sync_state')->where('tenant_id', $tenant->id)
                ->where('model', $src->stateModel('sage:ecritures'))->first();
            ln('state: ' . ($st ? ('last_run ' . $st->last_run_at . ' · rows ' . $st->rows_synced . ' · ' . ($st->last_error ?: 'no error')) : 'never synced'));
            break;

        case 'teesedump':
            // Export the Teese (Elimelec legacy ERP) data for the Odoo
            // migration. ?tenant=elimelec&mode=warehouse|live
            //   warehouse — the BMS warehouse copy (works even while the
            //               legacy server is down; data as of the last sync)
            //   live      — every schema/table the ERP credentials can see
            //               on the legacy PostgreSQL, complete rows
            // Writes CSVs + manifest, zips them, and prints a one-time URL
            // (random name under public/). op=teeseclean removes the zips.
            @set_time_limit(600);
            $slug = (string) ($_GET['tenant'] ?? 'elimelec');
            $mode = (string) ($_GET['mode'] ?? 'warehouse');
            $tenant = App\Models\Tenant::where('slug', $slug)->first();
            if (!$tenant) { ln("no tenant '{$slug}'"); break; }
            app(App\Tenancy\TenantContext::class)->set($tenant);
            $dir = storage_path('app/teese-export');
            @mkdir($dir, 0775, true);
            foreach (glob($dir . '/*') as $old) @unlink($old);
            $csv = function (string $path, array $rows): int {
                $fh = fopen($path, 'w');
                $n = 0;
                foreach ($rows as $r) {
                    $r = (array) $r;
                    if ($n === 0) fputcsv($fh, array_keys($r));
                    fputcsv($fh, array_map(fn ($v) => is_bool($v) ? (int) $v : $v, array_values($r)));
                    $n++;
                }
                fclose($fh);
                return $n;
            };
            $manifest = ["Teese export — mode={$mode} — tenant={$slug} — " . date('c')];
            if ($mode === 'warehouse') {
                $tables = ['wh_dim_partner', 'wh_fact_invoice', 'wh_fact_invoice_line',
                           'wh_fact_dossier', 'wh_fact_advance', 'wh_fact_validation'];
                foreach ($tables as $tbl) {
                    if (!Illuminate\Support\Facades\Schema::hasTable($tbl)) continue;
                    $rows = Illuminate\Support\Facades\DB::table($tbl)->where('tenant_id', $tenant->id)->get();
                    $n = $csv("{$dir}/{$tbl}.csv", $rows->all());
                    $manifest[] = sprintf('%-28s %6d rows', $tbl . '.csv', $n);
                    ln(sprintf('%-28s %6d rows', $tbl, $n));
                }
                $st = Illuminate\Support\Facades\DB::table('wh_sync_state')->where('tenant_id', $tenant->id)->get();
                $csv("{$dir}/_sync_state.csv", $st->all());
                $manifest[] = 'data as of the last successful sync (see _sync_state.csv)';
            } else {
                $src = App\Services\DataSource::primary();
                $conn = 'teese_dump_' . bin2hex(random_bytes(3));
                config(["database.connections.{$conn}" => App\Services\ErpDbConnectionTester::connectionConfig(null, $src)]);
                $db = Illuminate\Support\Facades\DB::connection($conn);
                $rels = $db->select("
                    select n.nspname sch, c.relname rel, c.relkind kind
                      from pg_class c join pg_namespace n on n.oid = c.relnamespace
                     where c.relkind in ('r','v','m','f','p')
                       and n.nspname not in ('pg_catalog','information_schema')
                     order by n.nspname, c.relname");
                $manifest[] = count($rels) . ' relations visible';
                $cols = $db->select("
                    select table_schema sch, table_name rel, column_name col, data_type typ
                      from information_schema.columns
                     where table_schema not in ('pg_catalog','information_schema')
                     order by table_schema, table_name, ordinal_position");
                $cat = fopen("{$dir}/_catalog.csv", 'w');
                fputcsv($cat, ['schema', 'table', 'column', 'type']);
                foreach ($cols as $c) fputcsv($cat, [$c->sch, $c->rel, $c->col, $c->typ]);
                fclose($cat);
                foreach ($rels as $r) {
                    $name = "{$r->sch}.{$r->rel}";
                    try {
                        $rows = $db->select("select * from \"{$r->sch}\".\"{$r->rel}\"");
                        $n = $csv("{$dir}/{$r->sch}__{$r->rel}.csv", $rows);
                        $manifest[] = sprintf('%-44s %7d rows', $name, $n);
                        ln(sprintf('%-44s %7d rows', $name, $n));
                    } catch (\Throwable $e) {
                        $manifest[] = $name . '  FAILED: ' . mb_substr($e->getMessage(), 0, 120);
                        ln($name . '  FAILED: ' . mb_substr($e->getMessage(), 0, 160));
                    }
                }
            }
            file_put_contents("{$dir}/_manifest.txt", implode("\n", $manifest) . "\n");
            $zipName = '_teese_export_' . bin2hex(random_bytes(8)) . '.zip';
            $zip = new ZipArchive();
            $zip->open(public_path($zipName), ZipArchive::CREATE | ZipArchive::OVERWRITE);
            foreach (glob($dir . '/*') as $file) $zip->addFile($file, basename($file));
            $zip->close();
            ln('');
            ln('ZIP: https://bms.cm-ea.com/' . $zipName . ' (' . number_format(filesize(public_path($zipName))) . ' bytes)');
            ln('Delete with op=teeseclean when fetched.');
            break;

        case 'teeseclean':
            foreach (glob(public_path('_teese_export_*.zip')) as $z) { ln('deleted ' . basename($z)); @unlink($z); }
            foreach (glob(storage_path('app/teese-export/*')) as $file) @unlink($file);
            ln('done');
            break;

        case 'analyticcheck':
            // Cost-centre (analytic accounting) coverage in a tenant's Odoo
            // GL: which analytic accounts exist, and how much of the ledger
            // carries an analytic distribution, month by month and by
            // account. ?tenant=slug [&from=YYYY-MM-DD]
            $slug = (string) ($_GET['tenant'] ?? '');
            $from = (string) ($_GET['from'] ?? date('Y-m-01', strtotime('-3 months')));
            $tenant = App\Models\Tenant::where('slug', $slug)->first();
            if (!$tenant) { ln("no tenant '{$slug}'"); break; }
            app(App\Tenancy\TenantContext::class)->set($tenant);
            $client = App\Odoo\OdooFactory::fromConfig();

            // 1 — the cost centres themselves
            try {
                $ccs = $client->searchRead('account.analytic.account', [],
                    ['id', 'name', 'code', 'active'], ['limit' => 200]);
                ln('== analytic accounts (cost centres): ' . count($ccs) . ' ==');
                foreach ($ccs as $a) {
                    ln(sprintf('  #%-5d %-12s %s%s', $a['id'],
                        (string) ($a['code'] ?: '—'), $a['name'],
                        empty($a['active']) ? '  (archived)' : ''));
                }
            } catch (\Throwable $e) { ln('analytic accounts: ' . mb_substr($e->getMessage(), 0, 160)); }

            // 2 — analytic entries by cost centre since $from (amounts +
            // date range). account.analytic.line is generated from GL lines
            // carrying a distribution, so this is the authoritative "what
            // landed where" view.
            try {
                $byCc = $client->callModel('account.analytic.line', 'read_group', [
                    'domain'  => [['date', '>=', $from]],
                    'fields'  => ['amount:sum'],
                    'groupby' => ['account_id'],
                    'lazy'    => false,
                ]);
                ln('');
                ln("== analytic entries by cost centre since {$from} ==");
                foreach ($byCc as $g) {
                    $cc = is_array($g['account_id'] ?? null) ? $g['account_id'][1] : '(none)';
                    ln(sprintf('  %-38s %6d entries  amount %16s', mb_substr($cc, 0, 38),
                        (int) ($g['account_id_count'] ?? $g['__count'] ?? 0),
                        number_format((float) ($g['amount'] ?? 0), 0)));
                }
                $byMonth = $client->callModel('account.analytic.line', 'read_group', [
                    'domain'  => [['date', '>=', $from]],
                    'fields'  => ['amount:sum'],
                    'groupby' => ['date:month'],
                    'lazy'    => false,
                ]);
                ln('');
                ln('== analytic entries by month ==');
                foreach ($byMonth as $g) {
                    ln(sprintf('  %-16s %6d entries  amount %16s', (string) ($g['date:month'] ?? '?'),
                        (int) ($g['date_count'] ?? $g['__count'] ?? 0),
                        number_format((float) ($g['amount'] ?? 0), 0)));
                }
            } catch (\Throwable $e) { ln('analytic lines: ' . mb_substr($e->getMessage(), 0, 160)); }

            // 3 — GL coverage: posted move lines with vs without a
            // distribution, per month. Newer Odoo stores the link as the
            // JSON field analytic_distribution; fall back to the legacy
            // analytic_account_id column.
            // 2b — older-Odoo mechanisms: analytic TAGS and the all-time
            // analytic-line population (in case dates or fields differ).
            try {
                $tags = $client->searchRead('account.analytic.tag', [], ['id', 'name', 'active'], ['limit' => 200]);
                ln('');
                ln('== analytic tags: ' . count($tags) . ' ==');
                foreach ($tags as $t) ln('  #' . $t['id'] . '  ' . $t['name'] . (empty($t['active']) ? '  (archived)' : ''));
                $tagged = $client->callModel('account.move.line', 'read_group', [
                    'domain' => [['parent_state', '=', 'posted'], ['date', '>=', $from], ['analytic_tag_ids', '!=', false]],
                    'fields' => ['balance:sum'], 'groupby' => ['date:month'], 'lazy' => false,
                ]);
                ln('tagged GL lines by month since ' . $from . ': ' . count($tagged) . ' month group(s)');
                foreach ($tagged as $g) ln('  ' . ($g['date:month'] ?? '?') . '  ' . (int) ($g['__count'] ?? 0) . ' lines');
            } catch (\Throwable $e) { ln('analytic tags: ' . mb_substr($e->getMessage(), 0, 140)); }
            try {
                $all = $client->callModel('account.analytic.line', 'read_group', [
                    'domain' => [], 'fields' => ['amount:sum'], 'groupby' => ['date:year'], 'lazy' => false,
                ]);
                ln('');
                ln('== account.analytic.line all-time by year ==');
                if (!$all) ln('  (none — the analytic ledger is empty)');
                foreach ($all as $g) ln('  ' . ($g['date:year'] ?? '?') . '  ' . (int) ($g['__count'] ?? 0) . ' entries  amount ' . number_format((float) ($g['amount'] ?? 0), 0));
            } catch (\Throwable $e) { ln('analytic all-time: ' . mb_substr($e->getMessage(), 0, 140)); }

            foreach (['analytic_distribution', 'analytic_account_id'] as $fld) {
                try {
                    $tot = $client->callModel('account.move.line', 'read_group', [
                        'domain' => [['parent_state', '=', 'posted'], ['date', '>=', $from]],
                        'fields' => ['balance:sum'], 'groupby' => ['date:month'], 'lazy' => false,
                    ]);
                    $with = $client->callModel('account.move.line', 'read_group', [
                        'domain' => [['parent_state', '=', 'posted'], ['date', '>=', $from], [$fld, '!=', false]],
                        'fields' => ['balance:sum'], 'groupby' => ['date:month'], 'lazy' => false,
                    ]);
                    $wm = [];
                    foreach ($with as $g) $wm[(string) ($g['date:month'] ?? '?')] = $g;
                    ln('');
                    ln("== GL lines with a cost centre ({$fld}) · posted · since {$from} ==");
                    foreach ($tot as $g) {
                        $m = (string) ($g['date:month'] ?? '?');
                        $n = (int) ($g['date_count'] ?? $g['__count'] ?? 0);
                        $w = (int) ($wm[$m]['date_count'] ?? $wm[$m]['__count'] ?? 0);
                        ln(sprintf('  %-16s %6d lines · %6d with cost centre (%s%%)',
                            $m, $n, $w, $n > 0 ? number_format(100 * $w / $n, 1) : '0'));
                    }
                    // 4 — which GL accounts the analytic-tagged lines sit on
                    $byAcct = $client->callModel('account.move.line', 'read_group', [
                        'domain' => [['parent_state', '=', 'posted'], ['date', '>=', $from], [$fld, '!=', false]],
                        'fields' => ['balance:sum'], 'groupby' => ['account_id'], 'lazy' => false,
                    ]);
                    usort($byAcct, fn ($a, $b) => (int) ($b['account_id_count'] ?? $b['__count'] ?? 0) <=> (int) ($a['account_id_count'] ?? $a['__count'] ?? 0));
                    ln('');
                    ln('== tagged lines by GL account (top 20) ==');
                    foreach (array_slice($byAcct, 0, 20) as $g) {
                        $acct = is_array($g['account_id'] ?? null) ? $g['account_id'][1] : '?';
                        ln(sprintf('  %-52s %5d lines  balance %15s', mb_substr($acct, 0, 52),
                            (int) ($g['account_id_count'] ?? $g['__count'] ?? 0),
                            number_format((float) ($g['balance'] ?? 0), 0)));
                    }
                    break; // this field worked — no fallback needed
                } catch (\Throwable $e) {
                    ln("({$fld}: " . mb_substr($e->getMessage(), 0, 120) . ')');
                }
            }
            break;

        case 'userlist':
            // Read-only account inventory for the platform operator: who can
            // sign in where. Emails, roles, status, last login — NEVER the
            // password hashes (bcrypt is one-way; recovery = reset, not read).
            $rows = Illuminate\Support\Facades\DB::table('users as u')
                ->leftJoin('tenants as t', 't.id', '=', 'u.tenant_id')
                ->orderByRaw('t.slug IS NULL DESC')->orderBy('t.slug')->orderBy('u.role')->orderBy('u.email')
                ->get(['u.id', 't.slug', 'u.name', 'u.email', 'u.role', 'u.status',
                       'u.last_login_at', 'u.must_change_password']);
            ln(sprintf('%-4s %-16s %-34s %-24s %-13s %-9s %-17s %s',
                'id', 'workspace', 'email', 'name', 'role', 'status', 'last login', 'must-change'));
            foreach ($rows as $r) {
                ln(sprintf('%-4d %-16s %-34s %-24s %-13s %-9s %-17s %s',
                    $r->id, $r->slug ?? 'PLATFORM', mb_substr($r->email, 0, 34), mb_substr((string) $r->name, 0, 24),
                    $r->role, $r->status, $r->last_login_at ? substr($r->last_login_at, 0, 16) : 'never',
                    !empty($r->must_change_password) ? 'yes' : ''));
            }
            ln('');
            ln(count($rows) . ' accounts. Login URLs: platform admins → /admin/login · workspace users → /t/{workspace}/login');
            break;

        case 'fieldscheck':
            // fields_get validation of config/clearance_reports.php against a
            // tenant's Odoo: per model, missing fields, plus the selection
            // values of the state/kind/step fields the reports filter on.
            // ?tenant=slug
            $slug = (string) ($_GET['tenant'] ?? '');
            $tenant = App\Models\Tenant::where('slug', $slug)->first();
            if (!$tenant) { ln("no tenant '{$slug}'"); break; }
            app(App\Tenancy\TenantContext::class)->set($tenant);
            $client = App\Odoo\OdooFactory::fromConfig();
            $selFields = ['state', 'step', 'kind', 'payment_mode', 'originating_team', 'justification',
                          'clearance_category', 'clearance_service_kind', 'clearance_invoice_kind',
                          'customs_regime', 'opening_state', 'waiver_state', 'advance_waiver_state',
                          'recharge_state', 'reopen_request_state', 'stage_owner', 'shipment_type',
                          'billing_status', 'cancel_reason', 'target_state', 'from_state', 'clearance_receivable_kind', 'payment_state', 'move_type'];
            $allOk = true;
            // &cfg=clearance_intel_fields checks the Clearance Intelligence registry instead.
            $cfgFields = ($_GET['cfg'] ?? '') === 'clearance_intel_fields'
                ? (array) config('clearance_intel_fields') : (array) config('clearance_reports.required_fields');
            foreach ($cfgFields as $model => $need) {
                try {
                    $fg = $client->callModel($model, 'fields_get', ['attributes' => ['type', 'string', 'selection', 'relation']]);
                } catch (\Throwable $e) {
                    ln(sprintf('%-30s MODEL UNREADABLE: %s', $model, mb_substr($e->getMessage(), 0, 140)));
                    $allOk = false; continue;
                }
                $missing = array_values(array_diff($need, array_keys((array) $fg)));
                if ($missing) $allOk = false;
                ln(sprintf('%-30s %3d/%3d ok%s', $model, count($need) - count($missing), count($need),
                    $missing ? '  MISSING: ' . implode(', ', $missing) : ''));
                foreach ($selFields as $sf) {
                    if (in_array($sf, $need, true) && isset($fg[$sf]['selection']) && is_array($fg[$sf]['selection'])) {
                        ln('      ' . $sf . ' = ' . implode(' | ', array_map(fn ($p) => $p[0] . ' (' . $p[1] . ')', $fg[$sf]['selection'])));
                    }
                }
            }
            ln('');
            ln($allOk ? 'ALL REQUIRED FIELDS PRESENT' : 'VALIDATION FAILED — see MISSING above');
            break;

        case 'clrsnapshot':
            // Read-only snapshot of exactly what the clearance pack reads, for
            // offline development/testing. ?tenant=slug — writes a gzipped
            // JSON under a random public name; op=clrsnapclean deletes it.
            @set_time_limit(600);
            $slug = (string) ($_GET['tenant'] ?? 'elimelec');
            $tenant = App\Models\Tenant::where('slug', $slug)->first();
            if (!$tenant) { ln("no tenant '{$slug}'"); break; }
            app(App\Tenancy\TenantContext::class)->set($tenant);
            $d = App\Services\Clearance\ClearanceData::live(App\Odoo\OdooFactory::fromConfig());
            $req = (array) config('clearance_reports.required_fields');
            $snap = ['meta' => ['tenant' => $slug, 'taken_at' => date('c')], 'fields' => [], 'rows' => []];
            foreach (array_keys($req) as $m) $snap['fields'][$m] = $d->fieldsGet($m);
            $fl = fn (string $m, array $extra = []) => array_values(array_unique(array_merge(['id'], $req[$m], $extra)));

            $snap['rows']['res.company']               = $d->searchAll('res.company', [], $fl('res.company', ['name']));
            $snap['rows']['logistics.file']            = $d->searchAll('logistics.file', [], $fl('logistics.file'));
            $snap['rows']['logistics.expense']         = $d->searchAll('logistics.expense', [], $fl('logistics.expense'));
            $snap['rows']['logistics.expense.category']= $d->searchAll('logistics.expense.category', [], $fl('logistics.expense.category'));
            $snap['rows']['logistics.service.type']    = $d->searchAll('logistics.service.type', [], $fl('logistics.service.type'));
            $snap['rows']['clearance.turnaround']      = $d->searchAll('clearance.turnaround', [], $fl('clearance.turnaround'));
            $snap['rows']['clearance.task']            = $d->searchAll('clearance.task', [], $fl('clearance.task'));
            $snap['rows']['res.partner']               = $d->searchAll('res.partner', [], $fl('res.partner'));
            $snap['rows']['hr.employee']               = $d->searchAll('hr.employee', [], $fl('hr.employee'));
            $jr = $d->searchAll('account.journal', [['type', 'in', ['bank', 'cash']]], $fl('account.journal'));
            $snap['rows']['account.journal'] = $jr;
            $pml = [];
            foreach ($jr as $j) foreach ((array) ($j['outbound_payment_method_line_ids'] ?? []) as $id) $pml[] = (int) $id;
            $snap['rows']['account.payment.method.line'] = $pml
                ? $d->searchAll('account.payment.method.line', [['id', 'in', $pml]], $fl('account.payment.method.line')) : [];
            $snap['rows']['account.move'] = $d->searchAll('account.move',
                [['move_type', 'in', ['out_invoice', 'out_refund', 'entry']]], $fl('account.move'));

            // Lines: every clearance-tagged line, plus every line on the
            // company's clearance accounts and on bank/till/holding accounts.
            $co = $d->companyAccounts();
            $accts = array_values(array_filter(array_map(fn ($k) => $co[$k] ?? null, $req['res.company'])));
            foreach ($jr as $j) if ($id = App\Services\Clearance\ClearanceData::m2oId($j['default_account_id'] ?? false)) $accts[] = $id;
            foreach ($snap['rows']['account.payment.method.line'] as $p) if ($id = App\Services\Clearance\ClearanceData::m2oId($p['payment_account_id'] ?? false)) $accts[] = $id;
            $accts = array_values(array_unique($accts));
            $snap['rows']['account.move.line'] = $d->searchAll('account.move.line',
                ['|', ['clearance_category', '!=', false], ['account_id', 'in', $accts]],
                $fl('account.move.line', ['parent_state', 'journal_id']));

            $snap['rows']['mail.message'] = $d->searchAll('mail.message', [
                ['model', 'in', ['logistics.file', 'logistics.expense', 'logistics.billing.service']],
                ['body', 'ilike', 'Reason:'],
            ], $fl('mail.message'));

            foreach ($snap['rows'] as $m => $r) ln(sprintf('%-30s %7d rows', $m, count($r)));
            $name = '_clrsnap_' . bin2hex(random_bytes(10)) . '.json.gz';
            file_put_contents(public_path($name), gzencode(json_encode($snap, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), 9));
            ln('');
            ln('SNAPSHOT: https://bms.cm-ea.com/' . $name . ' (' . number_format(filesize(public_path($name))) . ' bytes) — delete with op=clrsnapclean');
            break;

        case 'clrsnapclean':
            foreach (glob(public_path('_clrsnap_*.json.gz')) as $z) { @unlink($z); ln('deleted ' . basename($z)); }
            ln('done');
            break;

        case 'offenderslog':
            // The scorecard's own log. Empty or missing means the scheduled
            // send has not run since output capture was added — which is the
            // whole point of capturing it.
            $p = storage_path('logs/offenders-report.log');
            ln("== {$p} (tail {$L}) ==");
            ln(tailFile($p, $L));
            break;

        case 'schedcheck':
            // Is Laravel's scheduler actually running on this box?
            //
            // This matters more than it looks. The daily digest fires from its
            // OWN crontab entry, so it would keep working even if
            // `schedule:run` were absent from cron entirely — while every task
            // registered in routes/console.php silently never fired. The
            // offenders scorecard is one of those, so "the daily emails work"
            // is not evidence that it does.
            ln('== registered schedule ==');
            Illuminate\Support\Facades\Artisan::call('schedule:list');
            ln(rtrim(Illuminate\Support\Facades\Artisan::output()));
            ln();
            ln('== evidence the scheduler runs ==');
            // bms:warehouse-sync exists ONLY as a Laravel-scheduled task, at
            // minutes 7/22/37/52. If last_run_at lands on one of those and
            // nobody triggered it by hand, the scheduler is alive.
            foreach (Illuminate\Support\Facades\DB::table('wh_sync_state')
                ->orderBy('tenant_id')->orderBy('model')->get() as $st) {
                $t = (string) ($st->last_run_at ?? '');
                $min = $t !== '' ? (int) substr($t, 14, 2) : -1;
                ln(sprintf('  tenant %-4s %-20s last_run_at %-20s %s',
                    $st->tenant_id, $st->model, $t !== '' ? $t : '—',
                    in_array($min, [7, 22, 37, 52], true) ? '← scheduled minute' : ''));
            }
            ln();
            ln('server now: ' . date('Y-m-d H:i:s') . ' (' . date_default_timezone_get() . ')');
            break;

        case 'odooprofile':
            // Read-only profile of an Odoo-connected tenant: for each model
            // of interest, the record count, the field list, and a few
            // sample records. The API-side equivalent of `erpprofile` (which
            // introspects a direct Postgres schema). ?tenant=slug [&rows=3]
            $slug = (string) ($_GET['tenant'] ?? '');
            $tenant = App\Models\Tenant::where('slug', $slug)->first();
            if (!$tenant) { echo json_encode(['error' => "no tenant '{$slug}'"]); break; }
            app(App\Tenancy\TenantContext::class)->set($tenant);
            $client = App\Odoo\OdooFactory::fromConfig();
            $nRows = max(1, min(10, (int) ($_GET['rows'] ?? 3)));

            // Models worth profiling for a BI/reporting conversation, grouped
            // by the business question they answer.
            $models = [
                'res.partner'            => 'Customers & suppliers',
                'account.move'           => 'Invoices / bills / journal entries',
                'account.move.line'      => 'GL lines (the ledger itself)',
                'account.account'        => 'Chart of accounts',
                'account.journal'        => 'Journals (bank, cash, sales…)',
                'account.payment'        => 'Payments received / sent',
                'product.product'        => 'Products & services',
                'product.category'       => 'Product categories',
                'sale.order'             => 'Sales orders',
                'sale.order.line'        => 'Sales order lines',
                'purchase.order'         => 'Purchase orders',
                'purchase.order.line'    => 'Purchase order lines',
                'stock.picking'          => 'Deliveries / receipts',
                'stock.move'             => 'Stock moves',
                'stock.quant'            => 'Stock on hand',
                'hr.employee'            => 'Employees',
                'project.project'        => 'Projects',
                'project.task'           => 'Tasks',
                'crm.lead'               => 'CRM leads / opportunities',
                'res.currency'           => 'Currencies',
                'res.company'            => 'Companies',
            ];
            $only = (string) ($_GET['model'] ?? '');

            $out = ['tenant' => $slug, 'name' => $tenant->name, 'models' => []];
            foreach ($models as $model => $purpose) {
                if ($only !== '' && $only !== $model) continue;
                $entry = ['purpose' => $purpose];
                try {
                    $entry['count'] = $client->searchCount($model, []);
                } catch (\Throwable $e) {
                    $entry['available'] = false;
                    $entry['note'] = 'model not installed / not accessible';
                    $out['models'][$model] = $entry;
                    continue;
                }
                $entry['available'] = true;
                try {
                    $fields = (array) $client->callModel($model, 'fields_get', [
                        'allfields' => [],
                        'attributes' => ['string', 'type'],
                    ]);
                    $flat = [];
                    foreach ($fields as $fname => $meta) {
                        $flat[$fname] = ($meta['type'] ?? '?') . ' · ' . ($meta['string'] ?? '');
                    }
                    ksort($flat);
                    $entry['fields'] = $flat;
                    $entry['field_count'] = count($flat);
                } catch (\Throwable $e) {
                    $entry['fields_error'] = mb_substr($e->getMessage(), 0, 200);
                }
                if (($entry['count'] ?? 0) > 0 && empty($_GET['nosample'])) {
                    try {
                        // A small, safe field subset for the sample so the
                        // payload stays readable (full list is in 'fields').
                        $prefer = ['id', 'name', 'display_name', 'date', 'date_order', 'invoice_date',
                                   'state', 'move_type', 'partner_id', 'amount_total', 'amount_untaxed',
                                   'product_id', 'quantity', 'price_unit', 'debit', 'credit', 'code'];
                        $use = array_values(array_intersect($prefer, array_keys($entry['fields'] ?? [])));
                        if ($use === []) $use = ['id'];
                        $entry['sample'] = $client->searchRead($model, [], $use, ['limit' => $nRows]);
                    } catch (\Throwable $e) {
                        $entry['sample_error'] = mb_substr($e->getMessage(), 0, 200);
                    }
                }
                $out['models'][$model] = $entry;
            }
            echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            break;

        case 'odoomodels':
            // FULL model inventory: every non-transient model registered on
            // the instance with its record count. Paged (&skip=&take=) so a
            // large registry can be walked across several requests without
            // hitting the gateway timeout. ?tenant=slug
            $slug = (string) ($_GET['tenant'] ?? '');
            $tenant = App\Models\Tenant::where('slug', $slug)->first();
            if (!$tenant) { echo json_encode(['error' => "no tenant '{$slug}'"]); break; }
            app(App\Tenancy\TenantContext::class)->set($tenant);
            $client = App\Odoo\OdooFactory::fromConfig();
            $skip = max(0, (int) ($_GET['skip'] ?? 0));
            $take = max(1, min(400, (int) ($_GET['take'] ?? 200)));

            $all = $client->searchRead('ir.model', [['transient', '=', false]],
                ['model', 'name'], ['order' => 'model asc']);
            $slice = array_slice($all, $skip, $take);
            $out = ['tenant' => $slug, 'total_models' => count($all),
                    'skip' => $skip, 'take' => $take, 'models' => []];
            foreach ($slice as $m) {
                $name = (string) $m['model'];
                // Skip framework plumbing that can never carry business data.
                if (preg_match('/^(ir\.|bus\.|base_|report\.|web_|mail\.tracking|iap\.)/', $name)) {
                    continue;
                }
                try {
                    $out['models'][$name] = [
                        'label' => (string) ($m['name'] ?? ''),
                        'count' => $client->searchCount($name, []),
                    ];
                } catch (\Throwable $e) {
                    $out['models'][$name] = ['label' => (string) ($m['name'] ?? ''), 'count' => -1];
                }
            }
            echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            break;

        case 'volcheck':
            // What VOLUME data does a tenant actually have, and how complete
            // is it? Coverage first (a metric computed on 12% of rows is a
            // trap), then the axes volume can be cut by.
            $slug = (string) ($_GET['tenant'] ?? '');
            $tenant = App\Models\Tenant::where('slug', $slug)->first();
            if (!$tenant) { ln("no tenant '{$slug}'"); break; }
            $tid = $tenant->id;
            // Each section is a heavy scan; all together exceed the Cloudflare
            // ceiling on a 747k-line tenant, so callers fetch one part at a time.
            $part = (string) ($_GET['part'] ?? 'all');
            $want = fn (string $k) => $part === 'all' || $part === $k;

            if ($want('coverage')) {
            $tot = \Illuminate\Support\Facades\DB::table('wh_fact_gl_line')->where('tenant_id', $tid)->count();
            $withProd = \Illuminate\Support\Facades\DB::table('wh_fact_gl_line')->where('tenant_id', $tid)
                ->whereNotNull('product_odoo_id')->count();
            $withQty = \Illuminate\Support\Facades\DB::table('wh_fact_gl_line')->where('tenant_id', $tid)
                ->whereNotNull('product_odoo_id')->where('quantity', '>', 0)->count();
            $joinable = \Illuminate\Support\Facades\DB::table('wh_fact_gl_line as l')
                ->join('wh_dim_product as p', function ($j) {
                    $j->on('p.tenant_id', '=', 'l.tenant_id')->on('p.odoo_id', '=', 'l.product_odoo_id');
                })->where('l.tenant_id', $tid)->where('l.quantity', '>', 0)->count();
            ln('== coverage ==');
            ln(sprintf('  gl lines total            %s', number_format($tot)));
            ln(sprintf('  with a product            %s (%s%%)', number_format($withProd), $tot ? round(100*$withProd/$tot) : 0));
            ln(sprintf('  with product AND qty>0    %s', number_format($withQty)));
            ln(sprintf('  joinable to product dim   %s  <-- usable for volume', number_format($joinable)));
            ln();
            }

            $base = fn () => \Illuminate\Support\Facades\DB::table('wh_fact_gl_line as l')
                ->join('wh_dim_product as p', function ($j) {
                    $j->on('p.tenant_id', '=', 'l.tenant_id')->on('p.odoo_id', '=', 'l.product_odoo_id');
                })
                ->where('l.tenant_id', $tid)->where('l.quantity', '>', 0);

            if ($want('year')) {
            ln('== volume by year ==');
            foreach ($base()->whereNotNull('l.invoice_date')
                ->selectRaw('YEAR(l.invoice_date) y, SUM(l.quantity) qty, SUM(l.price_subtotal) rev, COUNT(*) n')
                ->groupBy('y')->orderBy('y')->get() as $r) {
                ln(sprintf('  %s  qty=%-10s revenue=%-18s lines=%-8s per-unit=%s',
                    $r->y, number_format((float) $r->qty), number_format((float) $r->rev),
                    number_format($r->n), $r->qty > 0 ? number_format((float) $r->rev / (float) $r->qty) : '—'));
            }
            ln();

            }
            if ($want('category')) {
            ln('== volume by container category ==');
            foreach ($base()
                ->selectRaw("COALESCE(p.category_name,'—') k, SUM(l.quantity) qty, SUM(l.price_subtotal) rev, COUNT(*) n")
                ->groupBy('k')->orderByDesc('qty')->limit(14)->get() as $r) {
                ln(sprintf('  %-34s qty=%-10s revenue=%-18s per-unit=%s',
                    mb_substr($r->k, 0, 32), number_format((float) $r->qty), number_format((float) $r->rev),
                    $r->qty > 0 ? number_format((float) $r->rev / (float) $r->qty) : '—'));
            }
            ln();

            }
            if ($want('voyage')) {
            ln('== volume by voyage type (Type_voyage) ==');
            foreach (\Illuminate\Support\Facades\DB::table('wh_fact_gl_line as l')
                ->join('wh_fact_invoice as i', function ($j) {
                    $j->on('i.tenant_id', '=', 'l.tenant_id')->on('i.odoo_id', '=', 'l.move_odoo_id');
                })
                ->where('l.tenant_id', $tid)->where('l.quantity', '>', 0)
                ->whereNotNull('l.product_odoo_id')
                ->selectRaw("COALESCE(NULLIF(i.voyage_type,''),'(unset)') k, SUM(l.quantity) qty,
                             SUM(l.price_subtotal) rev, COUNT(*) n")
                ->groupBy('k')->orderByDesc('qty')->get() as $r) {
                ln(sprintf('  %-22s qty=%-10s revenue=%-18s per-unit=%s',
                    $r->k, number_format((float) $r->qty), number_format((float) $r->rev),
                    $r->qty > 0 ? number_format((float) $r->rev / (float) $r->qty) : '—'));
            }
            ln();

            }
            if ($want('customers')) {
            ln('== top customers by volume ==');
            foreach ($base()
                ->join('wh_fact_invoice as i', function ($j) {
                    $j->on('i.tenant_id', '=', 'l.tenant_id')->on('i.odoo_id', '=', 'l.move_odoo_id');
                })
                ->leftJoin('wh_dim_partner as pt', function ($j) {
                    $j->on('pt.tenant_id', '=', 'i.tenant_id')->on('pt.odoo_id', '=', 'i.partner_odoo_id');
                })
                ->selectRaw("COALESCE(pt.name,'—') k, SUM(l.quantity) qty, SUM(l.price_subtotal) rev")
                ->groupBy('k')->orderByDesc('qty')->limit(10)->get() as $r) {
                ln(sprintf('  %-40s qty=%-9s revenue=%-16s per-unit=%s',
                    mb_substr($r->k, 0, 38), number_format((float) $r->qty), number_format((float) $r->rev),
                    $r->qty > 0 ? number_format((float) $r->rev / (float) $r->qty) : '—'));
            }
            }
            break;

        case 'odooprobe':
            // Model discovery WITHOUT admin rights: ir.model is usually
            // restricted, so probe a broad catalogue of known Odoo model
            // names and report which ones this login can actually read.
            // ?tenant=slug [&group=finance|sales|stock|hr|crm|other|all]
            $slug = (string) ($_GET['tenant'] ?? '');
            $tenant = App\Models\Tenant::where('slug', $slug)->first();
            if (!$tenant) { echo json_encode(['error' => "no tenant '{$slug}'"]); break; }
            app(App\Tenancy\TenantContext::class)->set($tenant);
            $client = App\Odoo\OdooFactory::fromConfig();

            $catalogue = [
                'finance' => [
                    'account.move', 'account.move.line', 'account.account', 'account.journal',
                    'account.payment', 'account.payment.method', 'account.payment.term',
                    'account.tax', 'account.tax.group', 'account.fiscal.position',
                    'account.analytic.account', 'account.analytic.line', 'account.analytic.group',
                    'account.asset', 'account.bank.statement', 'account.bank.statement.line',
                    'account.reconcile.model', 'account.full.reconcile', 'account.partial.reconcile',
                    'account.account.type', 'account.group', 'account.incoterms',
                    'account.cash.rounding', 'account.invoice.report', 'account.report',
                ],
                'sales' => [
                    'sale.order', 'sale.order.line', 'sale.report', 'crm.team',
                    'product.product', 'product.template', 'product.category', 'product.pricelist',
                    'product.pricelist.item', 'product.attribute', 'product.supplierinfo',
                    'uom.uom', 'uom.category',
                ],
                'purchase' => [
                    'purchase.order', 'purchase.order.line', 'purchase.report',
                    'purchase.requisition',
                ],
                'stock' => [
                    'stock.picking', 'stock.picking.type', 'stock.move', 'stock.move.line',
                    'stock.quant', 'stock.location', 'stock.warehouse', 'stock.lot',
                    'stock.production.lot', 'stock.valuation.layer', 'stock.scrap',
                ],
                'partners' => [
                    'res.partner', 'res.partner.category', 'res.partner.bank', 'res.partner.title',
                    'res.company', 'res.currency', 'res.currency.rate', 'res.country', 'res.users',
                    'res.groups', 'res.bank',
                ],
                'hr' => [
                    'hr.employee', 'hr.department', 'hr.job', 'hr.contract', 'hr.leave',
                    'hr.leave.type', 'hr.attendance', 'hr.expense', 'hr.expense.sheet',
                    'hr.payslip', 'hr.applicant',
                ],
                'crm' => [
                    'crm.lead', 'crm.stage', 'crm.lost.reason', 'calendar.event',
                ],
                'project' => [
                    'project.project', 'project.task', 'project.task.type', 'account.analytic.tag',
                ],
                'cargo' => [
                    'aw_cargo.cargo', 'aw_cargo.stopover', 'aw_cargo.equipment', 'aw_cargo.vessel',
                    'aw_cargo.container', 'aw_cargo.bl', 'aw_cargo.declaration', 'aw_cargo.scan',
                    'aw_vehicule.vehicule', 'aw_cargo.operation', 'aw_cargo.client',
                    'aw_cargo.tarif', 'aw_cargo.facture', 'aw_cargo.voyage', 'aw_cargo.port',
                ],
                'other' => [
                    'mail.message', 'mail.activity', 'ir.attachment', 'documents.document',
                    'fleet.vehicle', 'maintenance.equipment', 'survey.survey', 'website.page',
                    'sign.request', 'helpdesk.ticket', 'repair.order', 'mrp.production',
                ],
            ];
            $group = (string) ($_GET['group'] ?? 'all');
            $out = ['tenant' => $slug, 'groups' => []];
            foreach ($catalogue as $gname => $models) {
                if ($group !== 'all' && $group !== $gname) continue;
                foreach ($models as $model) {
                    try {
                        $out['groups'][$gname][$model] = ['count' => $client->searchCount($model, [])];
                    } catch (\Throwable $e) {
                        $msg = $e->getMessage();
                        $out['groups'][$gname][$model] = ['count' => -1,
                            'why' => str_contains($msg, 'does not exist') ? 'not installed' : 'no access'];
                    }
                }
            }
            echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            break;

        case 'odoodeep':
            // Deep-dive on specific models: full field list + samples, for a
            // comma-separated model list. ?tenant=slug&models=a,b,c [&rows=3]
            $slug = (string) ($_GET['tenant'] ?? '');
            $tenant = App\Models\Tenant::where('slug', $slug)->first();
            if (!$tenant) { echo json_encode(['error' => "no tenant '{$slug}'"]); break; }
            app(App\Tenancy\TenantContext::class)->set($tenant);
            $client = App\Odoo\OdooFactory::fromConfig();
            $nRows = max(1, min(8, (int) ($_GET['rows'] ?? 3)));
            $want = array_filter(array_map('trim', explode(',', (string) ($_GET['models'] ?? ''))));
            $out = ['tenant' => $slug, 'models' => []];
            foreach ($want as $model) {
                $e = [];
                try { $e['count'] = $client->searchCount($model, []); }
                catch (\Throwable $ex) { $out['models'][$model] = ['error' => 'not accessible']; continue; }
                try {
                    $fields = (array) $client->callModel($model, 'fields_get', [
                        'allfields' => [], 'attributes' => ['string', 'type', 'relation', 'store'],
                    ]);
                    $flat = [];
                    foreach ($fields as $fn => $meta) {
                        $flat[$fn] = trim(($meta['type'] ?? '?')
                            . (!empty($meta['relation']) ? '→' . $meta['relation'] : '')
                            . ' · ' . ($meta['string'] ?? '')
                            . (isset($meta['store']) && !$meta['store'] ? ' (computed)' : ''));
                    }
                    ksort($flat);
                    $e['fields'] = $flat; $e['field_count'] = count($flat);
                } catch (\Throwable $ex) { $e['fields_error'] = mb_substr($ex->getMessage(), 0, 160); }
                if (($e['count'] ?? 0) > 0) {
                    try {
                        $prefer = ['id','name','display_name','code','date','payment_date','date_maturity',
                                   'state','payment_type','partner_type','amount','amount_total','amount_untaxed',
                                   'amount_residual','partner_id','journal_id','account_id','move_id','ref',
                                   'debit','credit','balance','analytic_account_id','product_id','quantity',
                                   'Type_voyage','aw_cargo_state','type','active'];
                        $use = array_values(array_intersect($prefer, array_keys($e['fields'] ?? [])));
                        if ($use === []) $use = ['id'];
                        $e['sample'] = $client->searchRead($model, [], $use, ['limit' => $nRows]);
                    } catch (\Throwable $ex) { $e['sample_error'] = mb_substr($ex->getMessage(), 0, 160); }
                }
                $out['models'][$model] = $e;
            }
            echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            break;

        case 'odoodims':
            // Analytical dimensions: the group-by axes that make reporting
            // possible. Payments, analytic accounts, withholding journals,
            // custom cargo fields. ?tenant=slug
            $slug = (string) ($_GET['tenant'] ?? '');
            $tenant = App\Models\Tenant::where('slug', $slug)->first();
            if (!$tenant) { echo json_encode(['error' => "no tenant '{$slug}'"]); break; }
            app(App\Tenancy\TenantContext::class)->set($tenant);
            $client = App\Odoo\OdooFactory::fromConfig();
            $out = ['tenant' => $slug];

            $grp = function (string $model, array $domain, array $groupby, ?string $measure = null)
                   use ($client) {
                try {
                    $fields = $measure ? [$measure] : [];
                    $rows = (array) $client->callModel($model, 'read_group', [
                        'domain' => $domain, 'fields' => $fields, 'groupby' => $groupby, 'lazy' => false,
                    ]);
                    $o = [];
                    foreach ($rows as $r) {
                        $k = [];
                        foreach ($groupby as $gg) {
                            $gk = explode(':', $gg)[0];
                            $v = $r[$gg] ?? $r[$gk] ?? null;
                            $k[] = is_array($v) ? ($v[1] ?? $v[0]) : ($v === false ? '—' : (string) $v);
                        }
                        $o[] = ['k' => implode(' · ', $k), 'n' => (int) ($r['__count'] ?? 0),
                                'v' => ($measure && isset($r[$measure]) && !is_array($r[$measure])) ? (float) $r[$measure] : null];
                    }
                    usort($o, fn ($a, $b) => $b['n'] <=> $a['n']);
                    return array_slice($o, 0, 40);
                } catch (\Throwable $e) { return ['error' => mb_substr($e->getMessage(), 0, 200)]; }
            };

            // Payments — the 107k records the warehouse never captured.
            $out['payments_by_type']    = $grp('account.payment', [], ['payment_type'], 'amount');
            $out['payments_by_partner_type'] = $grp('account.payment', [], ['partner_type'], 'amount');
            $out['payments_by_journal'] = $grp('account.payment', [], ['journal_id'], 'amount');
            $out['payments_by_year']    = $grp('account.payment', [], ['date:year'], 'amount');
            $out['payments_by_state']   = $grp('account.payment', [], ['state'], 'amount');

            // Analytic accounts — a whole reporting axis sitting unused.
            $out['analytic_accounts']   = $grp('account.analytic.line', [], ['account_id'], 'amount');
            $out['analytic_by_year']    = $grp('account.analytic.line', [], ['date:year'], 'amount');

            // Custom cargo fields on invoices.
            $out['cargo_type_voyage']   = $grp('account.move', [], ['Type_voyage']);
            $out['cargo_state']         = $grp('account.move', [], ['aw_cargo_state']);

            // Withholding tax journals + taxes.
            $out['journals_by_type']    = $grp('account.journal', [], ['type']);
            $out['taxes_used']          = $grp('account.tax', [], ['type_tax_use']);

            // Ledger by account type — the OHADA classes.
            $out['gl_by_internal_group'] = $grp('account.move.line', [], ['account_internal_group'], 'balance');

            echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            break;

        case 'odoocompose':
            // Composition of an Odoo tenant's accounting data: document
            // types, states, journals, per-year volumes, top partners.
            // Answers "what can we actually report on?" ?tenant=slug
            $slug = (string) ($_GET['tenant'] ?? '');
            $tenant = App\Models\Tenant::where('slug', $slug)->first();
            if (!$tenant) { echo json_encode(['error' => "no tenant '{$slug}'"]); break; }
            app(App\Tenancy\TenantContext::class)->set($tenant);
            $client = App\Odoo\OdooFactory::fromConfig();
            $out = ['tenant' => $slug];

            $group = function (string $model, array $domain, array $groupby, string $measure = '__count')
                     use ($client) {
                try {
                    $rows = (array) $client->callModel($model, 'read_group', [
                        'domain' => $domain, 'fields' => [$measure], 'groupby' => $groupby, 'lazy' => false,
                    ]);
                    $o = [];
                    foreach ($rows as $r) {
                        $k = [];
                        foreach ($groupby as $g) {
                            $gk = explode(':', $g)[0];
                            $v = $r[$g] ?? $r[$gk] ?? null;
                            $k[] = is_array($v) ? ($v[1] ?? $v[0]) : (string) ($v === false ? '—' : $v);
                        }
                        $o[] = ['k' => implode(' · ', $k), 'n' => (int) ($r['__count'] ?? 0),
                                'v' => isset($r[$measure]) && !is_array($r[$measure]) && $measure !== '__count'
                                       ? (float) $r[$measure] : null];
                    }
                    usort($o, fn ($a, $b) => $b['n'] <=> $a['n']);
                    return array_slice($o, 0, 40);
                } catch (\Throwable $e) {
                    return ['error' => mb_substr($e->getMessage(), 0, 160)];
                }
            };

            $out['moves_by_type']    = $group('account.move', [], ['move_type']);
            $out['moves_by_state']   = $group('account.move', [], ['state']);
            $out['moves_by_journal'] = $group('account.move', [], ['journal_id']);
            $out['moves_by_year']    = $group('account.move', [], ['date:year']);
            $out['invoices_by_year'] = $group('account.move',
                [['move_type', 'in', ['out_invoice', 'out_refund']], ['state', '=', 'posted']],
                ['date:year'], 'amount_untaxed');
            $out['bills_by_year']    = $group('account.move',
                [['move_type', 'in', ['in_invoice', 'in_refund']], ['state', '=', 'posted']],
                ['date:year'], 'amount_untaxed');
            $out['top_customers']    = $group('account.move',
                [['move_type', '=', 'out_invoice'], ['state', '=', 'posted']],
                ['partner_id'], 'amount_untaxed');
            $out['accounts_by_class'] = $group('account.account', [], ['user_type_id']);
            $out['products_by_cat']   = $group('product.product', [], ['categ_id']);
            $out['partners_by_type']  = $group('res.partner', [], ['company_type']);

            echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            break;

        case 'cachecheck':
            // Does the shared cache actually hold what the warm worker
            // should have written for this tenant's dashboard? Checks the
            // exact keys DashboardController::index() reads for the given
            // period (default ytd, current year/month — same defaults the
            // controller and config/warm.php's 'dashboard' entry use).
            $slug = (string) ($_GET['tenant'] ?? '');
            $tenant = App\Models\Tenant::where('slug', $slug)->first();
            if (!$tenant) { ln("no tenant '{$slug}'"); break; }
            app(App\Tenancy\TenantContext::class)->set($tenant);
            $today = new DateTime();
            $type  = (string) ($_GET['period'] ?? 'ytd');
            $year  = (int) ($_GET['year'] ?? $today->format('Y'));
            $month = (int) ($_GET['month'] ?? $today->format('m'));
            $cacheTag = "{$type}_{$year}_{$month}";
            $prefix = ea_cache_prefix();
            ln("tenant: {$tenant->id} / {$slug} — cacheTag={$cacheTag}");
            ln("prefix: {$prefix}");
            $store = Illuminate\Support\Facades\Cache::getFacadeRoot();
            foreach (["revenue_ht_v1_{$cacheTag}", "pl_{$cacheTag}", "ar_{$cacheTag}",
                      "recon_{$cacheTag}", "dash_kpi_running_v1_{$cacheTag}"] as $key) {
                $full = $prefix . $key;
                $hit = $store->get($full);
                if (is_array($hit) && array_key_exists('__at', $hit)) {
                    ln(sprintf('%-32s HIT  computed_at=%s', $key, date('H:i:s', $hit['__at'])));
                } elseif ($hit !== null) {
                    ln(sprintf('%-32s HIT  (legacy, no timestamp)', $key));
                } else {
                    ln(sprintf('%-32s MISS', $key));
                }
            }
            // Raw DB row check — bypasses the Cache facade entirely, in case
            // the configured store isn't actually 'database'.
            ln('');
            ln('cache config store: ' . config('cache.default'));
            if (Illuminate\Support\Facades\Schema::hasTable('cache')) {
                $row = Illuminate\Support\Facades\DB::table('cache')
                    ->where('key', 'like', "%{$cacheTag}%")->where('key', 'like', "%t{$tenant->id}%")
                    ->select('key', 'expiration')->limit(10)->get();
                ln('matching rows in `cache` table: ' . $row->count());
                foreach ($row as $r) {
                    ln('  ' . $r->key . '  expires=' . date('Y-m-d H:i:s', $r->expiration));
                }
            }
            break;

        case 'warmnow':
            // Priority warm pass for one tenant (e.g. right after its first
            // Odoo/ERP connection or warehouse backfill), rather than
            // waiting for the rotation to reach it on its own.
            // ?tenant=slug [&only=label-substring]
            $args = ['--tenant' => (string) ($_GET['tenant'] ?? '')];
            if (!empty($_GET['only'])) $args['--only'] = (string) $_GET['only'];
            if ($args['--tenant'] === '') { ln('need ?tenant=slug'); break; }
            $code = Illuminate\Support\Facades\Artisan::call('bms:warm', $args);
            ln(rtrim(Illuminate\Support\Facades\Artisan::output()));
            ln('exit=' . $code);
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

        case 'erpdiscover':
            // Read-only discovery of a tenant's external ERP database
            // (non-Odoo direct-DB tenants): lists relations + columns in
            // the configured schema so the report sync can be mapped
            // without provider round-trips. ?tenant=<slug> (defaults to
            // the first tenant that has ERP_DB_HOST configured).
            $slug = (string) ($_GET['tenant'] ?? '');
            if ($slug !== '') {
                $tenant = App\Models\Tenant::where('slug', $slug)->first();
            } else {
                $row = Illuminate\Support\Facades\DB::table('tenant_settings')->where('key', 'ERP_DB_HOST')->first();
                $tenant = $row ? App\Models\Tenant::find($row->tenant_id) : null;
            }
            if (!$tenant) { ln('no tenant with ERP_DB_HOST configured (or unknown slug)'); break; }
            ln("tenant: {$tenant->id} / {$tenant->slug} / {$tenant->name}");

            app(App\Tenancy\TenantContext::class)->set($tenant);
            $cfg = App\Services\ErpDbConnectionTester::connectionConfig();
            config(['database.connections.erp_discover' => $cfg]);
            $erp = Illuminate\Support\Facades\DB::connection('erp_discover');
            $schema = trim((string) config_env('ERP_DB_SCHEMA', 'public')) ?: 'public';
            ln("connected: {$cfg['driver']} {$cfg['host']}:{$cfg['port']}/{$cfg['database']} schema={$schema}");
            ln();

            $rels = $erp->select(
                "select c.relname as name, c.relkind as kind, coalesce(c.reltuples, -1)::bigint as est_rows
                 from pg_class c join pg_namespace n on n.oid = c.relnamespace
                 where n.nspname = ? and c.relkind in ('r','v','m','f','p') order by c.relname", [$schema]);
            $kinds = ['r' => 'table', 'v' => 'view', 'm' => 'matview', 'f' => 'foreign', 'p' => 'partitioned'];
            ln('== ' . count($rels) . " relations in \"{$schema}\" ==");
            foreach ($rels as $t) {
                ln(sprintf('%-42s %-11s ~%s rows', $t->name, $kinds[$t->kind] ?? $t->kind, number_format(max(0, (int) $t->est_rows))));
            }

            ln();
            ln('== columns ==');
            $cols = $erp->select(
                "select table_name, column_name, data_type, is_nullable
                 from information_schema.columns where table_schema = ?
                 order by table_name, ordinal_position", [$schema]);
            $byTable = [];
            foreach ($cols as $c) {
                $byTable[$c->table_name][] = $c->column_name . ':' . $c->data_type . ($c->is_nullable === 'YES' ? '?' : '');
            }
            foreach ($byTable as $tbl => $list) {
                ln();
                ln("[{$tbl}]");
                ln('  ' . implode("\n  ", $list));
            }
            break;

        case 'erpprofile':
            // Read-only data profile of a direct-DB tenant's ERP schema:
            // per table — exact row count and up to N sample rows (values
            // truncated). JSON output; feeds offline exploration and
            // report design. ?tenant=slug [&rows=5] [&table=only_this]
            $slug = (string) ($_GET['tenant'] ?? '');
            $tenant = App\Models\Tenant::where('slug', $slug)->first();
            if (!$tenant) { ln("no tenant '{$slug}'"); break; }
            app(App\Tenancy\TenantContext::class)->set($tenant);
            config(['database.connections.erp_profile' => App\Services\ErpDbConnectionTester::connectionConfig()]);
            $erp = Illuminate\Support\Facades\DB::connection('erp_profile');
            $schema = trim((string) config_env('ERP_DB_SCHEMA', 'public')) ?: 'public';
            $nRows  = max(1, min(20, (int) ($_GET['rows'] ?? 5)));
            $onlyT  = preg_replace('/[^a-z0-9_]/', '', (string) ($_GET['table'] ?? ''));

            $tables = $erp->select(
                "select c.relname as name from pg_class c
                 join pg_namespace n on n.oid = c.relnamespace
                 where n.nspname = ? and c.relkind in ('r','v','m','f','p') order by c.relname", [$schema]);
            $out = [];
            foreach ($tables as $t) {
                $tb = (string) $t->name;
                if ($onlyT !== '' && $tb !== $onlyT) continue;
                if (!preg_match('/^[a-z0-9_]+$/', $tb)) continue;
                $q = '"' . $schema . '"."' . $tb . '"';
                try {
                    $cnt = (int) ($erp->select("select count(*) c from {$q}")[0]->c ?? 0);
                    $sample = [];
                    foreach ($erp->select("select * from {$q} limit {$nRows}") as $row) {
                        $r = [];
                        foreach ((array) $row as $k => $v) {
                            $r[$k] = is_string($v) && mb_strlen($v) > 60 ? mb_substr($v, 0, 57) . '…' : $v;
                        }
                        $sample[] = $r;
                    }
                    $out[$tb] = ['rows' => $cnt, 'sample' => $sample];
                } catch (\Throwable $e) {
                    $out[$tb] = ['rows' => -1, 'error' => $e->getMessage()];
                }
            }
            echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        case 'opsdata':
            // Read-only 2026 aggregates for the Elimelec Operations report
            // PREVIEW (design artifact with live numbers). Every query is a
            // SELECT on the tenant's external BI schema. ?tenant=slug
            $slug = (string) ($_GET['tenant'] ?? 'elimelec');
            $tenant = App\Models\Tenant::where('slug', $slug)->first();
            if (!$tenant) { ln("no tenant '{$slug}'"); break; }
            app(App\Tenancy\TenantContext::class)->set($tenant);
            config(['database.connections.erp_ops' => App\Services\ErpDbConnectionTester::connectionConfig()]);
            $erp = Illuminate\Support\Facades\DB::connection('erp_ops');
            $J = [];

            // ── ① throughput & backlog ─────────────────────────────
            $J['open_now']  = (int) $erp->select("select count(*) c from fait_dossier_transit where est_clos = false")[0]->c;
            $J['suspended'] = (int) $erp->select("select count(*) c from fait_dossier_transit where est_clos = false and est_suspendu = true")[0]->c;
            $J['backlog_median_age'] = (float) ($erp->select(
                "select percentile_cont(0.5) within group (order by (current_date - jour_ouverture)) m
                 from fait_dossier_transit where est_clos = false and jour_ouverture is not null")[0]->m ?? 0);
            $J['monthly_flow'] = $erp->select(
                "select m, sum(o) opened, sum(c) closed from (
                   select to_char(jour_ouverture,'YYYY-MM') m, 1 o, 0 c from fait_dossier_transit where jour_ouverture >= '2026-01-01'
                   union all
                   select to_char(jour_cloture,'YYYY-MM') m, 0 o, 1 c from fait_dossier_transit where jour_cloture >= '2026-01-01'
                 ) x where m is not null group by m order by m");
            $J['aging'] = $erp->select(
                "select case when a <= 15 then '0-15' when a <= 30 then '16-30' when a <= 60 then '31-60'
                             when a <= 90 then '61-90' else '90+' end bucket, count(*) n
                 from (select (current_date - jour_ouverture) a from fait_dossier_transit
                       where est_clos = false and jour_ouverture is not null) x
                 group by 1 order by min(a)");
            $J['type_port'] = $erp->select(
                "select coalesce(t.nom,'?') type, coalesce(p.nom,'?') port,
                        count(*) filter (where f.est_clos = false) open,
                        count(*) filter (where f.jour_cloture >= '2026-01-01') closed
                 from fait_dossier_transit f
                 left join dim_type_dossier t on t.cle_type_dossier = f.cle_type_dossier
                 left join dim_port p on p.cle_port = f.cle_port
                 group by 1,2 order by (count(*)) desc limit 10");

            // ── ② lead time (files closed in 2026) ─────────────────
            $J['leadtime_by_type'] = $erp->select(
                "select t.nom type, t.delai_traitement_cible target, count(*) n,
                        percentile_cont(0.5) within group (order by f.delai_traitement_jours) median,
                        percentile_cont(0.9) within group (order by f.delai_traitement_jours) p90,
                        avg((f.delai_traitement_jours <= t.delai_traitement_cible)::int) ontime
                 from fait_dossier_transit f
                 join dim_type_dossier t on t.cle_type_dossier = f.cle_type_dossier
                 where f.jour_cloture >= '2026-01-01' and f.delai_traitement_jours is not null
                 group by 1,2 order by n desc");
            $J['ontime_monthly'] = $erp->select(
                "select to_char(f.jour_cloture,'YYYY-MM') m, count(*) n,
                        avg((f.delai_traitement_jours <= t.delai_traitement_cible)::int) ontime
                 from fait_dossier_transit f
                 join dim_type_dossier t on t.cle_type_dossier = f.cle_type_dossier
                 where f.jour_cloture >= '2026-01-01' and f.delai_traitement_jours is not null
                 group by 1 order by 1");
            $J['leadtime_by_employee'] = $erp->select(
                "select coalesce(e.nom_complet,'?') who, count(*) n,
                        percentile_cont(0.5) within group (order by f.delai_traitement_jours) median,
                        avg((f.delai_traitement_jours <= t.delai_traitement_cible)::int) ontime
                 from fait_dossier_transit f
                 join dim_type_dossier t on t.cle_type_dossier = f.cle_type_dossier
                 left join dim_employe e on e.cle_employe = f.cle_employe_suiveur
                 where f.jour_cloture >= '2026-01-01' and f.delai_traitement_jours is not null
                 group by 1 having count(*) >= 5 order by median asc limit 10");

            // ── ③ step bottlenecks (steps completed in 2026, top type) ──
            $J['steps'] = $erp->select(
                "select d.nom step, d.rang_etape rang, coalesce(td.nom,'?') type, count(*) n,
                        avg(s.ecart_jours) slip, avg(s.duree_depuis_precedente_jours) dwell,
                        avg((s.ecart_jours <= 0)::int) ontime, avg(s.est_invalidee::int) rework
                 from fait_etape_dossier s
                 join dim_etape_dossier d on d.cle_etape_dossier = s.cle_etape_dossier
                 left join dim_type_dossier td on td.cle_type_dossier = d.cle_type_dossier
                 where s.jour_reel >= '2026-01-01'
                 group by 1,2,3 order by n desc limit 14");

            // ── ④ approval circuits ────────────────────────────────
            $J['circuits'] = $erp->select(
                "select coalesce(td.libelle, x.doc) doc, count(*) docs,
                        percentile_cont(0.5) within group (order by x.total) median_days
                 from (select v.cle_document, max(v.cle_type_document) doc,
                              max(v.delai_total_circuit_jours) total
                       from fait_validation v where v.jour_document >= '2026-01-01'
                       group by v.cle_document) x
                 left join dim_type_document_valide td on td.cle_type_document = x.doc
                 where x.total is not null group by 1 order by docs desc");
            $J['circuit_steps'] = $erp->select(
                "select coalesce(w.nom_circuit,'?') circuit, v.rang_etape rang,
                        avg(v.delai_depuis_precedente_jours) lag, count(*) n
                 from fait_validation v
                 left join dim_etape_workflow w on w.cle_etape_workflow = v.cle_etape_workflow
                 where v.jour_etape >= '2026-01-01' and v.delai_depuis_precedente_jours is not null
                 group by 1,2 order by 1,2");
            $J['pending_validations'] = (int) $erp->select(
                "select count(distinct cle_document) c from fait_validation where est_franchie = false")[0]->c;

            // ── ⑤ volumes (files opened 2026) ──────────────────────
            $J['vol_monthly'] = $erp->select(
                "select to_char(jour_ouverture,'YYYY-MM') m, sum(poids_net) tons, sum(nombre_conteneurs) ctn,
                        sum(nombre_colis) colis, sum(valeur_marchandise) val, count(*) files
                 from fait_dossier_transit where jour_ouverture >= '2026-01-01' group by 1 order by 1");
            foreach (['regime_douanier', 'incoterm', 'type_embarquement'] as $ax) {
                $J['mix_' . $ax] = $erp->select(
                    "select coalesce({$ax},'—') k, count(*) n, sum(poids_net) tons
                     from fait_dossier_transit where jour_ouverture >= '2026-01-01'
                     group by 1 order by n desc limit 8");
            }
            $J['top_importers'] = $erp->select(
                "select coalesce(importateur,'—') k, count(*) n, sum(poids_net) tons, sum(valeur_marchandise) val
                 from fait_dossier_transit where jour_ouverture >= '2026-01-01'
                 group by 1 order by tons desc nulls last limit 10");

            // ── ⑥ billing lag / leakage ────────────────────────────
            $J['unbilled'] = $erp->select(
                "select count(*) n, sum(f.valeur_marchandise) exposure
                 from fait_dossier_transit f
                 where f.est_clos = true and f.jour_cloture <= current_date - 7
                   and not exists (select 1 from fait_facture_generique i where i.cle_dossier = f.cle_dossier)")[0];
            $J['unbilled_list'] = $erp->select(
                "select f.code_dossier code, coalesce(c.raison_sociale,'?') client, coalesce(t.nom,'?') type,
                        f.jour_cloture closed, (current_date - f.jour_cloture) age, f.valeur_marchandise val
                 from fait_dossier_transit f
                 left join dim_client c on c.cle_client = f.cle_client
                 left join dim_type_dossier t on t.cle_type_dossier = f.cle_type_dossier
                 where f.est_clos = true and f.jour_cloture <= current_date - 7
                   and not exists (select 1 from fait_facture_generique i where i.cle_dossier = f.cle_dossier)
                 order by f.jour_cloture asc limit 10");
            $J['billing_lag'] = $erp->select(
                "select t.nom type, t.delai_facturation_cible target, count(*) n,
                        percentile_cont(0.5) within group (order by f.delai_facturation_jours) median,
                        avg((f.delai_facturation_jours <= t.delai_facturation_cible)::int) ontime
                 from fait_dossier_transit f
                 join dim_type_dossier t on t.cle_type_dossier = f.cle_type_dossier
                 where f.delai_facturation_jours is not null and f.jour_cloture >= '2026-01-01'
                 group by 1,2 order by n desc");

            // ── ⑦ advances & profitability ─────────────────────────
            $J['adv_kpis'] = $erp->select(
                "select sum(montant) filter (where est_decaissee and not est_justifiee) unjustified,
                        avg(est_justifiee::int) filter (where est_decaissee) just_rate,
                        percentile_cont(0.5) within group (order by delai_decaissement_jours) disb_lag,
                        sum(montant) filter (where cle_dossier is not null) alloc,
                        sum(montant) total
                 from fait_avance_frais where jour_demande >= '2026-01-01'")[0];
            $J['adv_by_rubrique'] = $erp->select(
                "select coalesce(r.nom,'—') k, sum(a.montant) amt, count(*) n
                 from fait_avance_frais a
                 left join dim_rubrique_budgetaire r on r.cle_rubrique = a.cle_rubrique
                 where a.jour_demande >= '2026-01-01' group by 1 order by amt desc nulls last limit 8");
            $J['adv_holders'] = $erp->select(
                "select coalesce(nom_beneficiaire,'—') k, sum(montant) amt, count(*) n
                 from fait_avance_frais
                 where est_decaissee and not est_justifiee and jour_demande >= '2026-01-01'
                 group by 1 order by amt desc nulls last limit 8");
            $J['dossier_margin'] = $erp->select(
                "with fee as (
                   select i.cle_dossier d,
                          sum(l.montant_ht) filter (where l.est_debours is not true) fees,
                          sum(l.montant_ht) filter (where l.est_debours is true) deb
                   from fait_facture_generique i
                   join fait_facture_generique_ligne l on l.cle_facture = i.cle_facture
                   where i.cle_dossier is not null group by 1),
                 adv as (
                   select cle_dossier d, sum(montant) a from fait_avance_frais
                   where est_decaissee and cle_dossier is not null group by 1)
                 select coalesce(fee.d, adv.d) d, f.code_dossier code, coalesce(c.raison_sociale,'?') client,
                        coalesce(t.nom,'?') type,
                        coalesce(fee.fees,0) fees, coalesce(fee.deb,0) deb, coalesce(adv.a,0) advances,
                        coalesce(fee.fees,0) + coalesce(fee.deb,0) - coalesce(adv.a,0) margin
                 from fee full outer join adv on adv.d = fee.d
                 left join fait_dossier_transit f on f.cle_dossier = coalesce(fee.d, adv.d)
                 left join dim_client c on c.cle_client = f.cle_client
                 left join dim_type_dossier t on t.cle_type_dossier = f.cle_type_dossier
                 order by margin desc");
            // Ship only head + tail of the margin ranking (top/bottom 8).
            $dm = $J['dossier_margin'];
            $J['margin_top']    = array_slice($dm, 0, 8);
            $J['margin_bottom'] = array_slice($dm, max(0, count($dm) - 8));
            $J['margin_count']  = count($dm);
            unset($J['dossier_margin']);

            // ── drill-down + finance + profitability detail ────────
            // File-level list for 2026 (drill target for months, aging,
            // type×port, importer, shipment mode).
            $J['files'] = $erp->select(
                "select f.code_dossier code, coalesce(c.raison_sociale,'?') client, coalesce(t.nom,'?') type,
                        coalesce(p.nom,'—') port, f.jour_ouverture o, f.est_clos closed,
                        f.poids_net kg, f.nombre_conteneurs ctn, f.valeur_marchandise val,
                        coalesce(f.importateur,'—') imp, coalesce(f.type_embarquement,'—') emb
                 from fait_dossier_transit f
                 left join dim_client c on c.cle_client = f.cle_client
                 left join dim_type_dossier t on t.cle_type_dossier = f.cle_type_dossier
                 left join dim_port p on p.cle_port = f.cle_port
                 where f.jour_ouverture >= '2026-01-01' order by f.jour_ouverture");
            // Every invoice (finance page computes monthly revenue, AR and
            // collections client-side; also the drill target per month/client).
            $J['invoices'] = $erp->select(
                "select i.code_facture code, coalesce(c.raison_sociale,'?') client, i.jour_facture d,
                        i.montant_ht ht, i.montant_ttc ttc, i.montant_tva tva,
                        i.montant_encaisse enc, i.reste_du rest, i.cle_dossier dossier
                 from fait_facture_generique i
                 left join dim_client c on c.cle_client = i.cle_client
                 order by i.jour_facture");
            // Fee vs débours revenue split per month (needs the lines).
            $J['fee_split'] = $erp->select(
                "select to_char(i.jour_facture,'YYYY-MM') m,
                        sum(l.montant_ht) filter (where l.est_debours is not true) fees,
                        sum(l.montant_ht) filter (where l.est_debours is true) deb
                 from fait_facture_generique i
                 join fait_facture_generique_ligne l on l.cle_facture = i.cle_facture
                 where i.jour_facture is not null group by 1 order by 1");
            // Cash till movements (finance page).
            $J['cash_monthly'] = $erp->select(
                "select to_char(jour_operation,'YYYY-MM') m, sum(entree) cin, sum(sortie) cout, count(*) n
                 from fait_mouvement_caisse where jour_operation >= '2026-01-01' group by 1 order by 1");
            $J['cash_by_caisse'] = $erp->select(
                "select coalesce(k.nom,'—') caisse, sum(m.entree) cin, sum(m.sortie) cout, count(*) n
                 from fait_mouvement_caisse m
                 left join dim_caisse k on k.cle_caisse = m.cle_caisse
                 where m.jour_operation >= '2026-01-01' group by 1 order by (sum(m.entree)+sum(m.sortie)) desc");
            // Full per-dossier margin set (profitability page + drills).
            $J['margin_all'] = $erp->select(
                "with fee as (
                   select i.cle_dossier d,
                          sum(l.montant_ht) filter (where l.est_debours is not true) fees,
                          sum(l.montant_ht) filter (where l.est_debours is true) deb
                   from fait_facture_generique i
                   join fait_facture_generique_ligne l on l.cle_facture = i.cle_facture
                   where i.cle_dossier is not null group by 1),
                 adv as (
                   select cle_dossier d, sum(montant) a from fait_avance_frais
                   where est_decaissee and cle_dossier is not null group by 1)
                 select coalesce(fee.d, adv.d) d, f.code_dossier code, coalesce(c.raison_sociale,'?') client,
                        coalesce(t.nom,'?') type,
                        coalesce(fee.fees,0) fees, coalesce(fee.deb,0) deb, coalesce(adv.a,0) advances,
                        coalesce(fee.fees,0) + coalesce(fee.deb,0) - coalesce(adv.a,0) margin
                 from fee full outer join adv on adv.d = fee.d
                 left join fait_dossier_transit f on f.cle_dossier = coalesce(fee.d, adv.d)
                 left join dim_client c on c.cle_client = f.cle_client
                 left join dim_type_dossier t on t.cle_type_dossier = f.cle_type_dossier
                 order by margin desc");

            // ── unit economics / customer / supplier detail ────────
            // Invoice LINES with their file + client: powers the per-file
            // statement (fees vs débours lines) and customer analysis.
            $J['inv_lines'] = $erp->select(
                "select i.cle_dossier d, i.code_facture inv, coalesce(c.raison_sociale,'?') c,
                        l.jour_facture j, left(coalesce(l.libelle,''), 48) lib,
                        coalesce(l.categorie,'') cat, (l.est_debours is true) deb,
                        round(coalesce(l.montant_ht,0)) ht
                 from fait_facture_generique_ligne l
                 join fait_facture_generique i on i.cle_facture = l.cle_facture
                 left join dim_client c on c.cle_client = i.cle_client
                 order by l.jour_facture");
            // Advance lines with supplier / beneficiary: powers the
            // per-file cost detail and the débours supplier analysis.
            $J['adv_lines'] = $erp->select(
                "select a.cle_dossier d,
                        coalesce(s.raison_sociale, nullif(a.nom_beneficiaire,''), '—') who,
                        left(coalesce(a.libelle_ligne,''), 48) lib,
                        round(coalesce(a.montant,0)) ht,
                        coalesce(a.jour_decaissement, a.jour_demande) j,
                        (a.est_justifiee is true) just
                 from fait_avance_frais a
                 left join dim_fournisseur s on s.cle_fournisseur = a.cle_fournisseur
                 where a.est_decaissee order by 5");
            // Débours/advances aggregated per supplier with their rôle flags.
            $J['adv_by_supplier'] = $erp->select(
                "select coalesce(s.raison_sociale, nullif(a.nom_beneficiaire,''), '—') who,
                        max(case when s.est_transitaire then 'transitaire' when s.est_acconier then 'acconier'
                                 when s.est_assureur then 'assureur' when s.est_transporteur then 'transporteur'
                                 when s.cle_fournisseur is not null then 'fournisseur' else 'bénéficiaire' end) role,
                        count(*) n, round(sum(a.montant)) amt,
                        round(sum(a.montant) filter (where a.est_justifiee is not true)) unjust,
                        round(sum(a.montant) filter (where a.cle_dossier is not null)) on_files
                 from fait_avance_frais a
                 left join dim_fournisseur s on s.cle_fournisseur = a.cle_fournisseur
                 where a.est_decaissee
                 group by 1 order by amt desc limit 30");
            // EVERY open file (incl. pre-2026) for true backlog aging.
            $J['open_files_all'] = $erp->select(
                "select f.code_dossier code, coalesce(c.raison_sociale,'?') client,
                        coalesce(t.nom,'?') type, coalesce(p.nom,'—') port, f.jour_ouverture o
                 from fait_dossier_transit f
                 left join dim_client c on c.cle_client = f.cle_client
                 left join dim_type_dossier t on t.cle_type_dossier = f.cle_type_dossier
                 left join dim_port p on p.cle_port = f.cle_port
                 where f.est_clos = false and f.jour_ouverture is not null
                 order by f.jour_ouverture");

            echo json_encode($J, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            break;

        case 'prodcheck':
            // Read-only: saved productivity settings + what HR Productivity
            // computes from them right now (no cache).
            $slug = (string) ($_GET['tenant'] ?? 'elimelec');
            $tenant = App\Models\Tenant::where('slug', $slug)->first();
            if (!$tenant) { ln("no tenant '{$slug}'"); break; }
            app(App\Tenancy\TenantContext::class)->set($tenant);
            $y = (int) ($_GET['year'] ?? date('Y'));
            ln('PRODUCTIVITY_MANUAL=' . var_export(config_env('PRODUCTIVITY_MANUAL'), true) . ' enabled=' . var_export(App\Services\ProductivityOverrides::enabled(), true));
            ln('PRODUCTIVITY_FILE_NATURES=' . var_export(config_env('PRODUCTIVITY_FILE_NATURES'), true));
            foreach (Illuminate\Support\Facades\DB::table('tenant_productivity_months')->where('tenant_id', $tenant->id)->orderBy('year')->orderBy('month')->get() as $r) {
                ln(sprintf('  %d-%02d employees=%s files=%s natures=%s updated=%s', $r->year, $r->month, var_export($r->employees, true), var_export($r->files, true), (string) $r->natures, $r->updated_at));
            }
            ln('fingerprint=' . App\Services\ProductivityOverrides::fingerprint());
            try {
                $a = (new App\Services\HrProductivityService(App\Odoo\OdooFactory::fromConfig()))->analyze($y);
                foreach ($a['rows'] as $ym => $r) ln(sprintf('  %s cost=%s emp=%s%s files=%s%s per_emp=%s per_file=%s', $ym, number_format($r['cost']), $r['employees'], $r['manual_employees'] ? '*' : '', $r['files'], $r['manual_files'] ? '*' : '', var_export($r['per_employee'], true), var_export($r['per_file'], true)));
                ln('kpis: ' . json_encode(array_diff_key($a['kpis'], ['last_month' => 1])));
                ln('notes: ' . implode(' | ', $a['notes']));
            } catch (\Throwable $e) { ln('analyze failed: ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()); }
            break;

        case 'hrprobe':
            // Read-only: clearance role groups and members, employee count,
            // and who acts on files (chatter / tracking authors).
            $slug = (string) ($_GET['tenant'] ?? 'elimelec');
            $tenant = App\Models\Tenant::where('slug', $slug)->first();
            if (!$tenant) { ln("no tenant '{$slug}'"); break; }
            app(App\Tenancy\TenantContext::class)->set($tenant);
            $client = App\Odoo\OdooFactory::fromConfig();
            try {
                $gf = array_keys((array) $client->callModel('res.groups', 'fields_get', ['attributes' => ['type']]));
                ln('res.groups fields: ' . implode(',', array_intersect($gf, ['name', 'full_name', 'category_id', 'user_ids', 'users', 'privilege_id', 'all_user_ids'])));
                $uf = in_array('user_ids', $gf, true) ? 'user_ids' : 'users';
                $groups = $client->searchRead('res.groups', ['|', '|', '|', '|', '|', ['name', 'ilike', 'head of'], ['name', 'ilike', 'agent'],
                    ['name', 'ilike', 'customer service'], ['name', 'ilike', 'transit'], ['name', 'ilike', 'operations'], ['name', 'ilike', 'logistics']],
                    array_values(array_intersect(['id', 'name', 'full_name', $uf], $gf)), ['limit' => 80]);
                foreach ($groups as $g) ln(sprintf('group %-4d %-45s users=%s', $g['id'], mb_substr((string) ($g['full_name'] ?? $g['name']), 0, 45), json_encode($g[$uf] ?? [])));
            } catch (\Throwable $e) { ln('groups: ' . $e->getMessage()); }
            try {
                ln('hr.employee active: ' . $client->searchCount('hr.employee', []) . ' · all incl. archived: ' . $client->searchCount('hr.employee', ['|', ['active', '=', true], ['active', '=', false]]));
                foreach ($client->searchRead('hr.employee', [], ['name', 'user_id', 'create_date', 'department_id', 'job_title'], ['limit' => 60]) as $e) {
                    ln(sprintf('  emp %-28s user=%-22s created=%s dept=%s job=%s', mb_substr($e['name'], 0, 28), is_array($e['user_id']) ? mb_substr($e['user_id'][1], 0, 22) : '-', substr((string) $e['create_date'], 0, 10), is_array($e['department_id']) ? $e['department_id'][1] : '-', (string) ($e['job_title'] ?: '')));
                }
                ln('res.users (internal): ' . $client->searchCount('res.users', [['share', '=', false]]));
            } catch (\Throwable $e) { ln('employees: ' . $e->getMessage()); }
            foreach (['logistics.file', 'logistics.expense'] as $model) {
                try {
                    $rows = $client->callModel('mail.message', 'read_group', ['domain' => [['model', '=', $model]], 'fields' => [], 'groupby' => ['author_id', 'message_type'], 'lazy' => false]);
                    ln("messages on {$model}:");
                    foreach ((array) $rows as $r) ln('   ' . json_encode($r['author_id'] ?? null, JSON_UNESCAPED_UNICODE) . ' ' . ($r['message_type'] ?? '') . ' n=' . ($r['__count'] ?? '?'));
                } catch (\Throwable $e) { ln("messages {$model}: " . $e->getMessage()); }
            }
            break;

        case 'assetprobe':
            // Read-only: account.asset shape on this tenant's Odoo — fields,
            // counts by state, a sample, and the depreciation-move link.
            $slug = (string) ($_GET['tenant'] ?? 'elimelec');
            $tenant = App\Models\Tenant::where('slug', $slug)->first();
            if (!$tenant) { ln("no tenant '{$slug}'"); break; }
            app(App\Tenancy\TenantContext::class)->set($tenant);
            $client = App\Odoo\OdooFactory::fromConfig();
            try {
                $fg = $client->callModel('account.asset', 'fields_get', ['attributes' => ['type', 'string', 'relation', 'selection']]);
            } catch (\Throwable $e) { ln('fields_get failed: ' . $e->getMessage()); break; }
            $keep = [];
            foreach ($fg as $k => $f) {
                if (in_array($f['type'], ['one2many', 'many2many', 'binary', 'html'], true) && !in_array($k, ['depreciation_move_ids', 'original_move_line_ids'], true)) continue;
                if (str_starts_with($k, 'message_') || str_starts_with($k, 'activity_') || str_starts_with($k, 'website_')) continue;
                $keep[] = $k;
                $sel = isset($f['selection']) && is_array($f['selection']) ? ' [' . implode(',', array_map(fn ($s) => $s[0], $f['selection'])) . ']' : '';
                ln(sprintf('  %-34s %-10s %s%s%s', $k, $f['type'], $f['string'] ?? '', isset($f['relation']) ? ' → ' . $f['relation'] : '', $sel));
            }
            try {
                foreach ((array) $client->callModel('account.asset', 'read_group', ['domain' => [], 'fields' => ['original_value:sum'], 'groupby' => ['state'], 'lazy' => false]) as $g) {
                    ln('state ' . json_encode($g['state'] ?? null) . ': ' . ($g['__count'] ?? '?') . ' assets, original ' . number_format((float) ($g['original_value'] ?? 0)));
                }
            } catch (\Throwable $e) { ln('read_group: ' . $e->getMessage()); }
            $sampleFields = array_values(array_intersect($keep, ['name', 'state', 'original_value', 'book_value', 'value_residual', 'salvage_value', 'acquisition_date', 'prorata_date', 'method', 'method_number', 'method_period', 'account_asset_id', 'account_depreciation_id', 'account_depreciation_expense_id', 'model_id', 'asset_group_id', 'disposal_date', 'already_depreciated_amount_import', 'company_id', 'analytic_distribution']));
            try {
                foreach ($client->searchRead('account.asset', [], $sampleFields, ['limit' => 5, 'order' => 'original_value desc']) as $a) ln(json_encode($a, JSON_UNESCAPED_UNICODE));
            } catch (\Throwable $e) { ln('sample: ' . $e->getMessage()); }
            break;

        case 'fileref':
            // Read-only: which field on class-70 GL lines carries the invoice
            // reference (files = distinct references). ?tenant=&year=
            $slug = (string) ($_GET['tenant'] ?? 'elimelec');
            $tenant = App\Models\Tenant::where('slug', $slug)->first();
            if (!$tenant) { ln("no tenant '{$slug}'"); break; }
            app(App\Tenancy\TenantContext::class)->set($tenant);
            $client = App\Odoo\OdooFactory::fromConfig();
            $year = (int) ($_GET['year'] ?? date('Y'));
            $ids = array_map(fn ($a) => (int) $a['id'], $client->searchRead('account.account', [['code', '=like', '70%']], ['id'], []));
            $lines = $client->searchRead('account.move.line', [
                ['parent_state', '=', 'posted'], ['account_id', 'in', $ids],
                ['date', '>=', $year . '-01-01'], ['date', '<=', $year . '-12-31'],
            ], ['move_id', 'move_name', 'ref', 'name', 'date', 'balance', 'account_id', 'partner_id'], ['limit' => 20000, 'order' => 'date asc, id asc']);
            ln(count($lines) . ' class-70 lines in ' . $year);
            foreach (['move_name', 'ref', 'name'] as $f) {
                $vals = array_filter(array_map(fn ($l) => is_string($l[$f] ?? null) ? trim($l[$f]) : '', $lines));
                ln(sprintf('%-10s non-empty %5d  distinct %5d', $f, count($vals), count(array_unique($vals))));
            }
            $mv = array_unique(array_map(fn ($l) => is_array($l['move_id']) ? $l['move_id'][0] : 0, $lines));
            ln('distinct move_id ' . count($mv));
            foreach (array_slice($lines, 0, 25) as $l) {
                ln(sprintf('%s | %-14s | %-22s | ref=%-22s | name=%s | %s | %s', $l['date'], is_array($l['account_id']) ? mb_substr($l['account_id'][1], 0, 14) : '', (string) $l['move_name'],
                    mb_substr((string) ($l['ref'] ?: ''), 0, 22), mb_substr((string) ($l['name'] ?: ''), 0, 50), number_format((float) $l['balance']), is_array($l['partner_id']) ? mb_substr($l['partner_id'][1], 0, 20) : '-'));
            }
            break;

        case 'whtenants':
            // Read-only: every tenant row plus logistics/GL fact counts per
            // tenant_id (including ids whose tenant row no longer exists).
            foreach (Illuminate\Support\Facades\DB::table('tenants')->orderBy('id')->get() as $t) {
                ln(sprintf('tenant %-3d %-16s %s', $t->id, $t->slug, $t->name ?? ''));
            }
            foreach (['wh_fact_dossier', 'wh_fact_advance', 'wh_fact_validation', 'wh_fact_invoice', 'wh_fact_invoice_line', 'wh_fact_gl_line'] as $tb) {
                if (!Illuminate\Support\Facades\Schema::hasTable($tb)) continue;
                $rows = Illuminate\Support\Facades\DB::table($tb)->selectRaw('tenant_id, COUNT(*) n')->groupBy('tenant_id')->get();
                ln($tb . ': ' . $rows->map(fn ($r) => "t{$r->tenant_id}=" . number_format($r->n))->implode('  '));
            }
            if (Illuminate\Support\Facades\Schema::hasTable('wh_fact_dossier')) {
                foreach (Illuminate\Support\Facades\DB::table('wh_fact_dossier')->selectRaw('tenant_id, MIN(opened_date) a, MAX(opened_date) b')->groupBy('tenant_id')->get() as $r) {
                    ln("dossier dates t{$r->tenant_id}: {$r->a} → {$r->b}");
                }
            }
            break;

        case 'whstats':
            // Pure-warehouse row counts and invoice totals for one tenant —
            // no live ERP/Odoo needed, so it works for direct-DB tenants
            // where the vs-Odoo check ops cannot.
            $slug = (string) ($_GET['tenant'] ?? '');
            $tenant = App\Models\Tenant::where('slug', $slug)->first();
            if (!$tenant) { ln("no tenant '{$slug}'"); break; }
            $tid = $tenant->id;
            foreach (['wh_dim_partner', 'wh_dim_account', 'wh_dim_product', 'wh_dim_journal',
                      'wh_fact_invoice', 'wh_fact_invoice_line', 'wh_fact_gl_line',
                      'wh_fact_payment', 'wh_fact_reconcile', 'wh_key_map', 'wh_sync_state'] as $tb) {
                if (!Illuminate\Support\Facades\Schema::hasTable($tb)) { ln(sprintf('%-24s (no table)', $tb)); continue; }
                $n = Illuminate\Support\Facades\DB::table($tb)->where('tenant_id', $tid)->count();
                ln(sprintf('%-24s %s rows', $tb, number_format($n)));
            }
            ln();
            ln('== wh_fact_invoice by year ==');
            foreach (Illuminate\Support\Facades\DB::table('wh_fact_invoice')
                ->where('tenant_id', $tid)->whereNotNull('invoice_date')
                ->selectRaw('YEAR(invoice_date) y, COUNT(*) n, SUM(amount_untaxed) ht, SUM(amount_total) ttc, SUM(amount_residual) open_ar')
                ->groupBy('y')->orderBy('y')->get() as $r) {
                ln(sprintf('%s  n=%-6s HT=%18s  TTC=%18s  open AR=%16s',
                    $r->y, number_format($r->n), number_format((float) $r->ht, 0),
                    number_format((float) $r->ttc, 0), number_format((float) $r->open_ar, 0)));
            }
            ln();
            foreach ([['wh_fact_gl_line', 'invoice_date'], ['wh_fact_payment', 'payment_date']] as [$tb, $col]) {
                if (!Illuminate\Support\Facades\Schema::hasTable($tb)) continue;
                ln("== {$tb} by year ==");
                foreach (Illuminate\Support\Facades\DB::table($tb)
                    ->where('tenant_id', $tid)->whereNotNull($col)
                    ->selectRaw("YEAR({$col}) y, COUNT(*) n")
                    ->groupBy('y')->orderBy('y')->get() as $r) {
                    ln(sprintf('  %s  n=%s', $r->y, number_format($r->n)));
                }
                ln();
            }
            if (Illuminate\Support\Facades\Schema::hasTable('wh_dim_product')) {
                $withProd = Illuminate\Support\Facades\DB::table('wh_fact_gl_line as l')
                    ->join('wh_dim_product as p', function ($j) {
                        $j->on('p.tenant_id', '=', 'l.tenant_id')->on('p.odoo_id', '=', 'l.product_odoo_id');
                    })->where('l.tenant_id', $tid)->count();
                ln('gl lines joinable to a synced product: ' . number_format($withProd));
                ln();
            }
            ln('== top 5 partners by open AR ==');
            foreach (Illuminate\Support\Facades\DB::table('wh_fact_invoice as i')
                ->leftJoin('wh_dim_partner as p', fn ($j) => $j->on('p.tenant_id', '=', 'i.tenant_id')->on('p.odoo_id', '=', 'i.partner_odoo_id'))
                ->where('i.tenant_id', $tid)->where('i.amount_residual', '>', 0)
                ->selectRaw('MAX(p.name) name, SUM(i.amount_residual) ar, COUNT(*) n')
                ->groupBy('i.partner_odoo_id')->orderByDesc('ar')->limit(5)->get() as $r) {
                ln(sprintf('%-40s %16s  (%d open)', (string) $r->name, number_format((float) $r->ar, 0), $r->n));
            }
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

        case 'scancheck':
            // GATE - the Product Revenue page's scanned total and the Billing
            // section's Expected figure are the same qty x tariff computation
            // through two different code paths. The client's requirement is
            // that they be IDENTICAL; this proves it month by month instead
            // of asserting it.
            $slug = (string) ($_GET['tenant'] ?? 'transatlanticd');
            $tenant = App\Models\Tenant::where('slug', $slug)->first();
            if (!$tenant) { ln("no tenant '{$slug}'"); break; }
            app(App\Tenancy\TenantContext::class)->set($tenant);
            $client = App\Odoo\OdooFactory::fromConfig();
            $yr = (int) ($_GET['year'] ?? date('Y'));

            $ops = new App\Services\OperationsService($client);
            $expected = $ops->scanRevenueByMonth(
                new DateTimeImmutable($yr . '-01-01'),
                new DateTimeImmutable($yr . '-12-31'));

            $pr = (new App\Services\ProductRevenueService(
                app(App\Services\BudgetService::class),
                new App\Services\ProductVolumeService($client),
                new App\Services\GLRevenueService($client)
            ))->analyze($tenant, $yr);
            $scanned = array_fill(1, 12, 0.0);
            foreach ((array) ($pr['products'] ?? []) as $prod) {
                foreach ((array) ($prod['actual_revenue'] ?? []) as $i => $v) {
                    if ($v === null) continue;
                    $m = $i + 1;   // actual_revenue is 0-indexed via array_values
                    if ($m >= 1 && $m <= 12) $scanned[$m] += (float) $v;
                }
            }

            ln("== scanned (product page) vs Expected (billing) · {$slug} · {$yr} ==");
            ln(sprintf('%-6s %18s %18s %14s', 'month', 'product scanned', 'billing expected', 'delta'));
            $worst = 0.0;
            for ($m = 1; $m <= 12; $m++) {
                $a = $scanned[$m]; $b = (float) ($expected[$m] ?? 0.0);
                $d = $a - $b;
                $worst = max($worst, abs($d));
                if (abs($a) < 0.005 && abs($b) < 0.005) continue;
                ln(sprintf('%-6s %18s %18s %14s', $m,
                    number_format($a, 2), number_format($b, 2), number_format($d, 2)));
            }
            ln();
            ln('GATE: worst monthly delta = ' . number_format($worst, 2) . ' - ' . ($worst < 0.005 ? 'PASS ✅' : 'FAIL ❌'));
            break;

        case 'prodtrace':
            // Do invoice lines actually carry product references we could map
            // collections by? Collections are attributed today by matching 706
            // ACCOUNT names, which only covers containers - everything sold as
            // a product (BULDOOZER, CAMION, OOG, VEHICULE IMPORT) has no
            // account of its own, so its cash is invisible on the Treasury
            // page. If product_id is populated on the invoice lines, mapping by
            // product works for every stream regardless of account naming.
            //
            // ?terms=BULDOOZER,CAMION,OOG,VEHICULE  (comma separated, ILIKE)
            $slug = (string) ($_GET['tenant'] ?? 'transatlanticd');
            $tenant = App\Models\Tenant::where('slug', $slug)->first();
            if (!$tenant) { ln("no tenant '{$slug}'"); break; }
            app(App\Tenancy\TenantContext::class)->set($tenant);
            $client = App\Odoo\OdooFactory::fromConfig();
            $yr  = (int) ($_GET['year'] ?? date('Y'));
            $terms = array_filter(array_map('trim', explode(',',
                (string) ($_GET['terms'] ?? 'BULDOOZER,CAMION,OOG,VEHICULE'))));

            ln("== product references on invoice lines · {$slug} · {$yr} ==");
            ln();

            // 1) Which products match the client's names?
            $prodDom = [];
            for ($i = 0; $i < count($terms) - 1; $i++) $prodDom[] = '|';
            foreach ($terms as $t) $prodDom[] = ['name', 'ilike', $t];
            $prods = $client->searchRead('product.product', $prodDom, ['id', 'name', 'default_code'], ['limit' => 200]);
            ln(sprintf('products matching [%s]: %s', implode(', ', $terms), number_format(count($prods))));
            foreach (array_slice($prods, 0, 25) as $pr) {
                ln(sprintf('  id %-8s %-14s %s', $pr['id'],
                    (string) ($pr['default_code'] ?: '-'), mb_substr((string) $pr['name'], 0, 50)));
            }
            ln();

            // 1b) The same terms as free-text LINE LABELS. Odoo accepts invoice
            //     lines typed by hand with no product_id at all, and the client
            //     reads these names off real invoices - so if products come up
            //     empty, the labels are where the terms live. Grouping by label
            //     shows the exact spellings in use and what each is worth.
            foreach ($terms as $t) {
                $g = $client->callModel('account.move.line', 'read_group', [
                    'domain' => [
                        ['move_id.move_type', '=', 'out_invoice'],
                        ['parent_state', '=', 'posted'],
                        ['name', 'ilike', $t],
                        ['date', '>=', $yr . '-01-01'], ['date', '<=', $yr . '-12-31'],
                    ],
                    'fields'  => ['price_subtotal:sum'],
                    'groupby' => ['name'],
                    'lazy'    => false,
                ]);
                $g = (array) $g;
                $sum = 0.0; foreach ($g as $r) $sum += (float) ($r['price_subtotal'] ?? 0);
                ln(sprintf('line labels containing "%s": %s distinct · %s YTD',
                    $t, number_format(count($g)), number_format($sum, 2)));
                usort($g, fn ($a, $b) => (float) ($b['price_subtotal'] ?? 0) <=> (float) ($a['price_subtotal'] ?? 0));
                foreach (array_slice($g, 0, 8) as $r) {
                    ln(sprintf('    %-52s %14s  (%s lines)',
                        mb_substr((string) ($r['name'] ?? '?'), 0, 52),
                        number_format((float) ($r['price_subtotal'] ?? 0), 2),
                        number_format((int) ($r['__count'] ?? 0))));
                }
            }
            ln();

            // 1c-pre) terms=* lists the TOP class-7 accounts by YTD revenue
            //     instead of name-matching. The decisive view when a term
            //     matches nothing: if the revenue is booked under an
            //     unrelated account name, it is in this list and the client
            //     will recognise it on sight.
            if (in_array('*', $terms, true)) {
                $accs = $client->searchRead('account.account', [['code', '=like', '7%']], ['id', 'code', 'name'], ['limit' => 300]);
                $byId = [];
                foreach ($accs as $a) $byId[(int) $a['id']] = $a;
                $g = $client->callModel('account.move.line', 'read_group', [
                    'domain' => [
                        ['account_id', 'in', array_map(fn ($x) => (int) $x['id'], $accs)],
                        ['parent_state', '=', 'posted'],
                        ['date', '>=', $yr . '-01-01'], ['date', '<=', $yr . '-12-31'],
                    ],
                    'fields' => ['credit:sum', 'debit:sum'], 'groupby' => ['account_id'], 'lazy' => false,
                ]);
                $rows2 = [];
                foreach ((array) $g as $r) {
                    $aid = is_array($r['account_id'] ?? null) ? (int) $r['account_id'][0] : 0;
                    $net = (float) ($r['credit'] ?? 0) - (float) ($r['debit'] ?? 0);
                    if ($aid && abs($net) > 0.005) $rows2[] = [$aid, $net];
                }
                usort($rows2, fn ($a, $b) => $b[1] <=> $a[1]);
                ln(sprintf('ALL class-7 accounts with %d YTD movement, largest first:', $yr));
                foreach (array_slice($rows2, 0, 40) as [$aid, $net]) {
                    $a = $byId[$aid] ?? ['code' => '?', 'name' => '?'];
                    ln(sprintf('    %-10s %-52s %16s',
                        (string) $a['code'], mb_substr((string) $a['name'], 0, 52), number_format($net, 2)));
                }
                ln();
                break;
            }

            // 1c) The terms as ACCOUNT names. This tenant books each revenue
            //     type to its own named 706 sub-account - that is exactly how
            //     the container streams attribute - so terms read off real
            //     invoices that match neither products nor line labels most
            //     plausibly name accounts the stream registry does not know
            //     about yet. If these exist, the fix is registry entries, not
            //     a new mapping mechanism.
            foreach ($terms as $t) {
                $accs = $client->searchRead('account.account',
                    [['name', 'ilike', $t], ['code', '=like', '7%']],
                    ['id', 'code', 'name'], ['limit' => 40]);
                ln(sprintf('class-7 accounts named like "%s": %s', $t, number_format(count($accs))));
                foreach ($accs as $a) {
                    $g = $client->callModel('account.move.line', 'read_group', [
                        'domain' => [
                            ['account_id', '=', (int) $a['id']],
                            ['parent_state', '=', 'posted'],
                            ['date', '>=', $yr . '-01-01'], ['date', '<=', $yr . '-12-31'],
                        ],
                        'fields' => ['credit:sum', 'debit:sum'], 'groupby' => [], 'lazy' => false,
                    ]);
                    $cr = (float) (($g[0]['credit'] ?? 0)) - (float) (($g[0]['debit'] ?? 0));
                    ln(sprintf('    %-10s %-46s YTD revenue %16s',
                        (string) $a['code'], mb_substr((string) $a['name'], 0, 46), number_format($cr, 2)));
                }
            }
            ln();
            if (!$prods) { ln('  (no PRODUCT records - see labels/accounts above for where the terms live)'); break; }
            ln();

            // 2) Do posted customer-invoice lines reference them, and where does
            //    the revenue land?
            $pids = array_map(fn ($x) => (int) $x['id'], $prods);
            $rows = $client->callModel('account.move.line', 'read_group', [
                'domain' => [
                    ['move_id.move_type', '=', 'out_invoice'],
                    ['parent_state', '=', 'posted'],
                    ['product_id', 'in', $pids],
                    ['date', '>=', $yr . '-01-01'], ['date', '<=', $yr . '-12-31'],
                ],
                'fields'  => ['price_subtotal:sum'],
                'groupby' => ['product_id'],
                'lazy'    => false,
            ]);
            ln('invoiced revenue YTD by product (posted customer invoices):');
            $tot = 0.0;
            foreach ((array) $rows as $r) {
                $nm = is_array($r['product_id'] ?? null) ? (string) $r['product_id'][1] : '?';
                $amt = (float) ($r['price_subtotal'] ?? 0);
                $tot += $amt;
                ln(sprintf('  %-46s %16s  (%s lines)', mb_substr($nm, 0, 46),
                    number_format($amt, 2), number_format((int) ($r['__count'] ?? 0))));
            }
            ln(sprintf('  %-46s %16s', 'TOTAL', number_format($tot, 2)));
            if (!$rows) ln('  (no posted invoice lines reference these products)');
            ln();

            // 3) Which revenue accounts do those lines post to? This says
            //    whether account-name matching could ever have caught them.
            $acc = $client->callModel('account.move.line', 'read_group', [
                'domain' => [
                    ['move_id.move_type', '=', 'out_invoice'],
                    ['parent_state', '=', 'posted'],
                    ['product_id', 'in', $pids],
                    ['date', '>=', $yr . '-01-01'], ['date', '<=', $yr . '-12-31'],
                ],
                'fields'  => ['price_subtotal:sum'],
                'groupby' => ['account_id'],
                'lazy'    => false,
            ]);
            ln('revenue accounts these product lines post to:');
            foreach ((array) $acc as $r) {
                $nm = is_array($r['account_id'] ?? null) ? (string) $r['account_id'][1] : '?';
                ln(sprintf('  %-56s %16s', mb_substr($nm, 0, 56),
                    number_format((float) ($r['price_subtotal'] ?? 0), 2)));
            }
            ln();

            // 4) Coverage: how much of ALL invoiced revenue carries ANY product?
            $withProd = $client->callModel('account.move.line', 'read_group', [
                'domain' => [
                    ['move_id.move_type', '=', 'out_invoice'], ['parent_state', '=', 'posted'],
                    ['product_id', '!=', false],
                    ['date', '>=', $yr . '-01-01'], ['date', '<=', $yr . '-12-31'],
                ],
                'fields' => ['price_subtotal:sum'], 'groupby' => [], 'lazy' => false,
            ]);
            $noProd = $client->callModel('account.move.line', 'read_group', [
                'domain' => [
                    ['move_id.move_type', '=', 'out_invoice'], ['parent_state', '=', 'posted'],
                    ['product_id', '=', false], ['display_type', '=', false],
                    ['date', '>=', $yr . '-01-01'], ['date', '<=', $yr . '-12-31'],
                ],
                'fields' => ['price_subtotal:sum'], 'groupby' => [], 'lazy' => false,
            ]);
            $wp = (float) (($withProd[0]['price_subtotal'] ?? 0));
            $np = (float) (($noProd[0]['price_subtotal'] ?? 0));
            ln('coverage of product-based mapping across ALL invoice lines:');
            ln(sprintf('  lines WITH a product    %16s', number_format($wp, 2)));
            ln(sprintf('  lines WITHOUT a product %16s', number_format($np, 2)));
            ln(sprintf('  %% mappable by product   %15.1f%%', ($wp + $np) > 0 ? 100 * $wp / ($wp + $np) : 0));
            break;

        case 'colcheck':
            // Treasury collections by stream, traced from the cash side.
            // Reports what the page shows AND an independent invoice-first
            // total for the same month, so "collections look wrong" can be
            // split into: no cash arrived, cash arrived but was not attributed
            // to a stream, or the stream is absent from the model entirely.
            $slug = (string) ($_GET['tenant'] ?? 'transatlanticd');
            $tenant = App\Models\Tenant::where('slug', $slug)->first();
            if (!$tenant) { ln("no tenant '{$slug}'"); break; }
            app(App\Tenancy\TenantContext::class)->set($tenant);
            $client = App\Odoo\OdooFactory::fromConfig();
            $mon = (int) ($_GET['month'] ?? date('n'));
            $yr  = (int) ($_GET['year'] ?? date('Y'));

            // Constructed explicitly, matching how the controller builds it —
            // this service is not container-bound.
            $r = (new App\Services\CollectionsByStreamService($client, new App\Services\GLRevenueService($client)))
                ->analyze(null, ['type' => 'mtd', 'year' => $yr, 'month' => $mon]);

            ln("== collections by stream · {$slug} · {$yr}-{$mon} ==");
            ln(sprintf('%-34s %18s %18s', 'stream', 'HT', 'TTC'));
            $sumHt = 0.0;
            foreach ((array) ($r['streams'] ?? []) as $st) {
                $ht = (float) ($st['sel_ht'] ?? 0); $sumHt += $ht;
                ln(sprintf('%-34s %18s %18s',
                    mb_substr((string) $st['label'], 0, 34),
                    number_format($ht, 2), number_format((float) ($st['sel_ttc'] ?? 0), 2)));
            }
            ln(sprintf('%-34s %18s', 'STREAM TOTAL (HT)', number_format($sumHt, 2)));
            foreach (['unmatched', 'other'] as $k) {
                if (isset($r[$k])) {
                    ln(sprintf('%-34s %18s %18s', strtoupper($k),
                        number_format((float) ($r[$k]['sel_ht'] ?? 0), 2),
                        number_format((float) ($r[$k]['sel_ttc'] ?? 0), 2)));
                }
            }
            if (!empty($r['unmatched']['accounts'])) {
                ln();
                ln('unmatched 70x accounts seen:');
                foreach (array_slice((array) $r['unmatched']['accounts'], 0, 15) as $a) ln('  ' . $a);
            }

            ln();
            ln('== independent check: invoices MARKED PAID with a settlement this month ==');
            $mStart = sprintf('%04d-%02d-01', $yr, $mon);
            $mEnd   = date('Y-m-t', strtotime($mStart));
            $paid = $client->searchRead('account.move',
                [['move_type', '=', 'out_invoice'], ['state', '=', 'posted'],
                 ['payment_state', 'in', ['paid', 'in_payment', 'reversed']]],
                ['id', 'name', 'invoice_date', 'amount_untaxed_signed', 'amount_total_signed'],
                ['limit' => 8000]);
            ln(sprintf('customer invoices currently in a paid state: %s', number_format(count($paid))));
            ln('(the page attributes CASH settled in the month, which is not the same set —');
            ln(' an invoice paid in instalments contributes to each month it was paid in)');
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
            // NO ea_cache_clear() here. It was pointless — every call below
            // invokes a service DIRECTLY and never reads ea_cached — and it was
            // actively harmful: ea_cache_clear() bumps the tenant's cache
            // version, invalidating EVERY page for that tenant at once. Running
            // this repeatedly on 17 Aug 2026 left transatlanticd cold, and with
            // gl-quality at 9.1 min and billing at 5 min uncached, visitors got
            // Cloudflare 524s until the ~35-minute warm sweep caught up.
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
            // Dashboard build time.
            //
            // Measures the WARM path by default. Cold timing needs &cold=1 and
            // is genuinely destructive in production: ea_cache_clear() bumps the
            // tenant's cache version, so EVERY page for that tenant goes cold at
            // once, not just the dashboard. The warm sweep then takes ~35 minutes
            // to rebuild transatlanticd, and pages that cannot finish inside
            // Cloudflare's ~100s ceiling — gl-quality at 9.1 min, billing at
            // 5 min, treasury at 4.3 min — serve 524s to real visitors for that
            // whole window. Repeated cold runs on 17 Aug 2026 did exactly that.
            //
            // Warm timing is also the more honest number: it is what a user
            // actually experiences, since every page is meant to be served from
            // the warmer's cache.
            $slug = (string) ($_GET['tenant'] ?? 'transatlanticd');
            $tenant = App\Models\Tenant::where('slug', $slug)->first();
            if (!$tenant) { ln("no tenant '{$slug}'"); break; }
            app(App\Tenancy\TenantContext::class)->set($tenant);
            $cold = !empty($_GET['cold']);
            if ($cold) {
                ln('⚠ COLD MODE — clearing this tenant\'s ENTIRE page cache.');
                ln('  Visitors may hit 524s until the warm sweep rebuilds (~35 min on transatlanticd).');
                ln();
                if (function_exists('ea_cache_clear')) ea_cache_clear();
            } else {
                ln('(warm path — pass &cold=1 to force a cold build, which wipes the tenant cache)');
                ln();
            }
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

        case 'pnlcheck':
            // GATE — P&L from the warehouse vs Odoo. Walks every numeric field
            // of both the current and prior windows, since the page shows a
            // period-on-period comparison and the prior window is just as
            // capable of drifting as the current one.
            $slug = (string) ($_GET['tenant'] ?? 'kwr');
            $tenant = App\Models\Tenant::where('slug', $slug)->first();
            if (!$tenant) { ln("no tenant '{$slug}'"); break; }
            app(App\Tenancy\TenantContext::class)->set($tenant);
            $client = App\Odoo\OdooFactory::fromConfig();
            $period = ['type' => (string) ($_GET['period'] ?? 'ytd'), 'year' => (int) date('Y')];
            $run = function (bool $wh) use ($client, $period) {
                config(['bi.warehouse_gl' => $wh]);
                $t0 = microtime(true);
                $r = (new App\Services\ProfitLossService($client))->analyze(null, $period);
                return [$r, microtime(true) - $t0];
            };
            [$w, $tW] = $run(true);
            [$o, $tO] = $run(false);
            config(['bi.warehouse_gl' => true]);

            ln(sprintf('warehouse %.2fs · odoo %.2fs · speed-up %.1f×', $tW, $tO, $tW > 0 ? $tO / $tW : 0));
            ln();
            $worst = 0.0; $worstKey = '—'; $compared = 0; $shape = 0;
            foreach (['current', 'previous'] as $sec) {
                foreach ((array) ($o[$sec] ?? []) as $k => $v) {
                    if (!is_numeric($v)) continue;
                    if (!array_key_exists($k, (array) ($w[$sec] ?? []))) { $shape++; continue; }
                    $compared++;
                    $a1 = (float) $w[$sec][$k]; $b1 = (float) $v;
                    $d = abs($a1 - $b1);
                    if ($d > $worst) { $worst = $d; $worstKey = "{$sec}.{$k}"; }
                    ln(sprintf('%-22s wh %18s · odoo %18s · Δ %s', "{$sec}.{$k}",
                        number_format($a1, 2), number_format($b1, 2), number_format($d, 2)));
                }
            }
            ln();
            ln(sprintf('%d value comparisons made', $compared));
            $pass = $compared > 0 && $worst < 0.005 && $shape === 0;
            ln('GATE: worst variance = ' . number_format($worst, 2) . " ({$worstKey})"
                . ($shape ? " · {$shape} shape mismatch(es)" : '')
                . ($compared === 0 ? ' · NOTHING COMPARED' : '')
                . ' — ' . ($pass ? 'PASS ✅' : 'FAIL ❌'));
            break;

        case 'glreconcile':
            // Re-check parent_state and existence for a window. Deletion only
            // happens on a complete scan — see the service note.
            $slug = (string) ($_GET['tenant'] ?? 'kwr');
            $tenant = App\Models\Tenant::where('slug', $slug)->first();
            if (!$tenant) { ln("no tenant '{$slug}'"); break; }
            app(App\Tenancy\TenantContext::class)->set($tenant);
            $year = (int) ($_GET['year'] ?? date('Y'));
            // Explicit from/to so a big tenant can be done in slices that fit
            // the gateway timeout. A whole year of transatlanticd is ~140k ids
            // and runs past it, and an incomplete scan cannot delete safely.
            $from = (string) ($_GET['from'] ?? ($year . '-01-01'));
            $to   = (string) ($_GET['to']   ?? ($year . '-12-31'));
            $svc = new App\Services\WarehouseSyncService(App\Odoo\OdooFactory::fromConfig(), $tenant);
            $svc->setDeadline(microtime(true) + (float) ($_GET['seconds'] ?? 70));
            $t0 = microtime(true);
            $r = $svc->reconcileGlState($from, $to);
            ln(sprintf('%s %s..%s · checked %s · states fixed %s · rows removed %s · %s · %.1fs',
                $slug, $from, $to, number_format($r['checked']), number_format($r['fixed']),
                number_format($r['removed']),
                $r['complete'] ? 'COMPLETE' : 'INCOMPLETE (deadline) — no deletions performed',
                microtime(true) - $t0));
            break;

        case 'acctmonths':
            // Every account under a prefix, with all 12 monthly balances, from
            // ODOO directly. Deliberately does NOT filter on parent_state: an
            // entry sitting in draft is exactly the kind of thing a posted-only
            // query hides, and "the amount is there but the report shows zero"
            // is usually one of those two.
            $slug = (string) ($_GET['tenant'] ?? 'kwr');
            $tenant = App\Models\Tenant::where('slug', $slug)->first();
            if (!$tenant) { ln("no tenant '{$slug}'"); break; }
            app(App\Tenancy\TenantContext::class)->set($tenant);
            $client = App\Odoo\OdooFactory::fromConfig();
            $pref = (string) ($_GET['prefix'] ?? '67');
            $year = (int) ($_GET['year'] ?? date('Y'));

            $accts = $client->searchRead('account.account',
                [['code', '=like', $pref . '%']], ['id', 'code', 'name'], ['order' => 'code asc']);
            ln("== {$slug} · accounts '{$pref}%' · {$year} ==");
            ln(sprintf('%d accounts in the chart', count($accts)));
            if (!$accts) break;
            $ids = array_map(fn ($a) => (int) $a['id'], $accts);
            $nameOf = [];
            foreach ($accts as $a) $nameOf[(int) $a['id']] = [(string) $a['code'], (string) ($a['name'] ?? '')];

            foreach ([['posted only', [['parent_state', '=', 'posted']]], ['ANY state', []]] as [$lbl, $extra]) {
                $rows = $client->callModel('account.move.line', 'read_group', [
                    'domain'  => array_merge($extra, [
                        ['account_id', 'in', $ids],
                        ['date', '>=', $year . '-01-01'], ['date', '<=', $year . '-12-31'],
                    ]),
                    'fields'  => ['balance:sum'], 'groupby' => ['account_id', 'date:month'], 'lazy' => false,
                ]);
                $by = [];
                foreach ((array) $rows as $r) {
                    if (!is_array($r['account_id'] ?? null)) continue;
                    $aid = (int) $r['account_id'][0];
                    $mk  = null;
                    foreach (['date', 'date:month'] as $rk) {
                        if (isset($r['__range'][$rk]['from'])) { $mk = (int) substr((string) $r['__range'][$rk]['from'], 5, 2); break; }
                    }
                    if ($mk === null) continue;
                    $by[$aid][$mk] = ($by[$aid][$mk] ?? 0.0) + (float) ($r['balance'] ?? 0);
                }
                ln();
                ln("-- {$lbl} · accounts with any movement --");
                if (!$by) { ln('   (none)'); continue; }
                foreach ($by as $aid => $months) {
                    [$code, $name] = $nameOf[$aid] ?? ['?', '?'];
                    $tot = array_sum($months);
                    ln(sprintf('  %-12s %-38s total %16s', $code, mb_substr($name, 0, 38), number_format($tot, 2)));
                    $parts = [];
                    foreach ($months as $mk => $v) {
                        if (abs($v) < 0.005) continue;
                        $parts[] = sprintf('%s %s', date('M', mktime(0, 0, 0, $mk, 1)), number_format($v, 2));
                    }
                    if ($parts) ln('       ' . implode(' · ', $parts));
                }
            }
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

            // Same ids, different CODE. This is what the id-set comparison
            // above cannot see, and it is the one that silently changes a
            // figure: every P&L bucket is chosen by code prefix, so a stale
            // code moves an account's balance into a different line - or out
            // of the statement entirely, if it lands on an excluded prefix
            // like 69.
            ln();
            $codeDiff = [];
            foreach ($wMap as $id => $wc) {
                if (!isset($oMap[$id])) continue;
                if ((string) $wc !== (string) $oMap[$id]) $codeDiff[$id] = [$wc, $oMap[$id]];
            }
            ln(sprintf('CODE MISMATCHES (same id, different code): %s', number_format(count($codeDiff))));
            foreach (array_slice($codeDiff, 0, 20, true) as $id => [$wc, $oc]) {
                $bal = Illuminate\Support\Facades\DB::table('wh_fact_gl_line')
                    ->where('tenant_id', $tenant->id)->where('account_odoo_id', $id)
                    ->where('parent_state', 'posted')
                    ->whereBetween('gl_date', [date('Y') . '-01-01', date('Y') . '-12-31'])
                    ->selectRaw('SUM(debit) - SUM(credit) AS b')->value('b');
                ln(sprintf('  id %-8s warehouse %-14s odoo %-14s ytd balance %s',
                    $id, $wc, $oc, number_format((float) ($bal ?? 0), 2)));
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
            // Two pages outside the warm list, so admin/tenant settings
            // views can be verified on production like any report page.
            if (!$def && $want === 'settings')       $def = ['controller' => App\Http\Controllers\SettingsController::class, 'action' => 'show', 'query' => []];
            if (!$def && $want === 'productivity-settings') $def = ['controller' => App\Http\Controllers\ProductivitySettingsController::class, 'action' => 'show', 'query' => []];
            if (!$def && $want === 'admin-settings') $def = ['controller' => App\Http\Controllers\Admin\TenantController::class, 'action' => 'showSettings', 'query' => [], 'params' => ['tenant' => $tenant]];
            // Clearance pack pages: page=clearance-r1 … clearance-r11.
            if (!$def && preg_match('/^clearance-(r\d{1,2})$/', $want, $cm)) $def = ['controller' => App\Http\Controllers\ClearanceReportsController::class, 'action' => 'show', 'query' => [], 'params' => ['code' => $cm[1]]];
            if (!$def) { ln("no page '{$want}' in config/warm.php"); break; }
            // &period=month&year=2026&month=8 overrides the page's default
            // query (and, via RememberPeriod, the session) — lets this op
            // verify the master filter actually reaches a given page.
            // &lg_customer=Name passes through too (Billing's drill-down).
            $queryOverride = array_merge((array) ($def['query'] ?? []), array_intersect_key(
                $_GET, array_flip(['period', 'year', 'month', 'lg_customer', 'lg_file', 'g', 'from', 'to'])
            ));
            $req = Illuminate\Http\Request::create('/t/' . $slug, 'GET', $queryOverride);
            app()->instance('request', $req);
            try { $s = app('session')->driver(); $s->start(); $req->setLaravelSession($s); } catch (\Throwable $e) {}
            try { (new App\Http\Middleware\RememberPeriod())->handle($req, fn ($r) => new Illuminate\Http\Response()); } catch (\Throwable $e) {}
            // &as=<user id> renders as that tenant user (role-dependent UI).
            $u = App\Models\User::where('tenant_id', $tenant->id)
                ->when(isset($_GET['as']), fn ($q) => $q->where('id', (int) $_GET['as']))->orderBy('id')->first();
            if ($u) { try { Illuminate\Support\Facades\Auth::login($u); } catch (\Throwable $e) {} }
            // Put a flash in the session to exercise the toast path too
            // (skip with &noflash=1 to isolate flash-dependent failures).
            if (!isset($_GET['noflash'])) {
                session()->flash('flash', ['type' => 'success', 'msg' => 'render-test']);
            }
            // &force=1 recomputes (and re-caches) every dataset like the warm worker.
            if (!empty($_GET['force'])) App\Support\CacheFreshness::force(true);
            try {
                $resp = app()->call([app((string) $def['controller']), (string) ($def['action'] ?? 'index')], (array) ($def['params'] ?? []));
                $html = ($resp instanceof Illuminate\Contracts\Support\Renderable) ? $resp->render() : '(non-view response: ' . get_class($resp) . ')';
                // &html=1 dumps the full page (for offline snapshots);
                // &grep=needle counts occurrences (content assertions —
                // RENDER OK alone can hide an in-page error banner).
                if (!empty($_GET['html'])) { echo (string) $html; break; }
                ln("RENDER OK — {$slug}/{$want} — " . strlen((string) $html) . ' bytes');
                if (isset($_GET['grep']) && $_GET['grep'] !== '') {
                    $needle = (string) $_GET['grep'];
                    ln('grep "' . $needle . '": ' . substr_count((string) $html, $needle) . ' occurrence(s)');
                }
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
