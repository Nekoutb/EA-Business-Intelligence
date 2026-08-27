<?php
declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Logistics Operations analytics (dossier-based sector, first tenant:
 * Elimelec). Reads ONLY the local warehouse facts synced by
 * ErpDbSyncService (wh_fact_dossier / wh_fact_advance /
 * wh_fact_validation / wh_fact_invoice[_line]) — never the external DB
 * at page time. Every section fails soft: a broken query empties one
 * card, not the page.
 */
final class LogisticsOpsService
{
    public function __construct(private int $tenantId)
    {
    }

    private function d()
    {
        return DB::table('wh_fact_dossier')->where('tenant_id', $this->tenantId);
    }

    /** Page 1 — Dossiers: throughput, backlog, aging, lead time. */
    public function dossiers(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $out = ['open_now' => 0, 'opened' => 0, 'closed' => 0, 'median_age' => null,
                'monthly' => [], 'aging' => [], 'cum' => [], 'type_port' => [], 'finding' => null];
        try {
            $out['open_now'] = (int) $this->d()->where('is_closed', false)->count();
            $out['opened']   = (int) $this->d()->whereBetween('opened_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])->count();
            $out['closed']   = (int) $this->d()->whereBetween('closed_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])->count();

            $ages = $this->d()->where('is_closed', false)->whereNotNull('opened_date')
                ->selectRaw('DATEDIFF(CURDATE(), opened_date) a')->pluck('a')->map(fn ($v) => (int) $v)->sort()->values();
            if ($ages->isNotEmpty()) {
                $out['median_age'] = $ages[(int) floor($ages->count() / 2)];
            }
            foreach ([['0-30', 0, 30], ['31-40', 31, 40], ['41-60', 41, 60], ['61-90', 61, 90], ['90+', 91, PHP_INT_MAX]] as [$k, $lo, $hi]) {
                $out['aging'][$k] = $ages->filter(fn ($a) => $a >= $lo && $a <= $hi)->count();
            }
            foreach ([30, 40, 60, 90] as $t) {
                $out['cum']['>' . $t] = $ages->filter(fn ($a) => $a > $t)->count();
            }

            foreach ($this->d()->whereNotNull('opened_date')->where('opened_date', '>=', $from->format('Y-01-01'))
                ->selectRaw("DATE_FORMAT(opened_date, '%Y-%m') m, COUNT(*) n")->groupBy('m')->orderBy('m')->get() as $r) {
                $out['monthly'][$r->m] = (int) $r->n;
            }

            $out['type_port'] = $this->d()
                ->selectRaw("COALESCE(type_name,'?') type, COALESCE(port_name,'—') port,
                             SUM(CASE WHEN is_closed = 0 THEN 1 ELSE 0 END) open_n, COUNT(*) total")
                ->groupBy('type', 'port')->orderByDesc('total')->limit(10)->get()->all();

            // Closure discipline: the lead-time section only means something
            // once files actually get closed. Surface it as a finding.
            if ($out['closed'] < max(10, (int) ($out['opened'] * 0.1))) {
                $out['finding'] = 'Files are rarely marked closed in the source system ('
                    . number_format($out['closed']) . ' closures vs ' . number_format($out['opened'])
                    . ' openings in the period), so backlog age measures status discipline rather than '
                    . 'workload, and lead-time metrics stay unavailable. Recommend closing dossiers at delivery.';
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('logistics dossiers analysis failed', ['error' => $e->getMessage()]);
        }
        return $out;
    }

    /** Page 2 — Workflow: approval circuits from validation events. */
    public function workflow(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $out = ['docs' => 0, 'pending' => 0, 'circuits' => [], 'steps' => [], 'note' => null];
        try {
            $base = DB::table('wh_fact_validation')->where('tenant_id', $this->tenantId);

            $out['pending'] = (int) (clone $base)->where('is_passed', false)->distinct()->count('doc_key');

            $out['circuits'] = (clone $base)
                ->whereBetween('doc_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
                ->whereNotNull('total_days')
                ->selectRaw("COALESCE(doc_type, '?') doc, COUNT(DISTINCT doc_key) docs, AVG(total_days) avg_days, MAX(total_days) max_days")
                ->groupBy('doc')->orderByDesc('docs')->get()->all();
            $out['docs'] = array_sum(array_map(fn ($r) => (int) $r->docs, $out['circuits']));

            $out['steps'] = (clone $base)
                ->whereBetween('step_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
                ->whereNotNull('step_lag_days')
                ->selectRaw("COALESCE(circuit,'?') circuit, rang, AVG(step_lag_days) lag, COUNT(*) n")
                ->groupBy('circuit', 'rang')->orderBy('circuit')->orderBy('rang')->get()->all();

            $out['note'] = 'Dossier step tracking (planned vs actual per workflow step) carries no actual '
                . 'completion dates in the source data yet; this page shows document approval circuits, '
                . 'which are fully recorded.';
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('logistics workflow analysis failed', ['error' => $e->getMessage()]);
        }
        return $out;
    }

    /** Page 3 — Volumes: tonnage, containers, mix, importers. */
    public function volumes(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $out = ['tons' => 0.0, 'containers' => 0, 'packages' => 0, 'cargo_value' => 0.0,
                'monthly' => [], 'embarquement' => [], 'importers' => [], 'capture_note' => null];
        try {
            $win = fn () => $this->d()->whereBetween('opened_date', [$from->format('Y-m-d'), $to->format('Y-m-d')]);

            $t = $win()->selectRaw('SUM(weight_kg) kg, SUM(containers) c, SUM(packages) p, SUM(cargo_value) v, COUNT(*) n,
                                    SUM(CASE WHEN importer IS NULL THEN 1 ELSE 0 END) no_imp')->first();
            $out['tons']        = round(((float) ($t->kg ?? 0)) / 1000);
            $out['containers']  = (int) ($t->c ?? 0);
            $out['packages']    = (int) ($t->p ?? 0);
            $out['cargo_value'] = (float) ($t->v ?? 0);
            if (($t->n ?? 0) > 0 && $t->no_imp / max(1, $t->n) > 0.5) {
                $out['capture_note'] = 'Importer, customs regime and incoterm are blank on most files — '
                    . 'those mix views stay hidden until the fields are captured in the source system.';
            }

            foreach ($this->d()->whereNotNull('opened_date')->where('opened_date', '>=', $from->format('Y-01-01'))
                ->selectRaw("DATE_FORMAT(opened_date, '%Y-%m') m, SUM(weight_kg)/1000 t, SUM(containers) c")
                ->groupBy('m')->orderBy('m')->get() as $r) {
                $out['monthly'][$r->m] = ['tons' => round((float) $r->t), 'containers' => (int) $r->c];
            }

            $out['embarquement'] = $win()
                ->selectRaw("COALESCE(embarquement,'—') k, COUNT(*) n, SUM(weight_kg)/1000 t")
                ->groupBy('k')->orderByDesc('t')->get()->all();

            $out['importers'] = $win()->whereNotNull('importer')
                ->selectRaw('importer k, COUNT(*) n, SUM(weight_kg)/1000 t, SUM(cargo_value) v')
                ->groupBy('k')->orderByDesc('t')->limit(10)->get()->all();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('logistics volumes analysis failed', ['error' => $e->getMessage()]);
        }
        return $out;
    }

    /**
     * Profitability extension — unit economics + débours reconciliation.
     * Rendered on the existing Profitability page for logistics tenants.
     */
    public function profitability(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $out = ['unit' => [], 'recon' => [], 'top' => [], 'bottom' => []];
        try {
            $f = $from->format('Y-m-d'); $t = $to->format('Y-m-d');

            // Money in the window (invoice date basis).
            $money = DB::table('wh_fact_invoice_line as l')
                ->join('wh_fact_invoice as i', function ($j) {
                    $j->on('i.tenant_id', '=', 'l.tenant_id')->on('i.odoo_id', '=', 'l.move_odoo_id');
                })
                ->where('l.tenant_id', $this->tenantId)
                ->whereBetween('l.invoice_date', [$f, $t])
                ->selectRaw('SUM(CASE WHEN l.is_debours = 1 THEN l.price_subtotal ELSE 0 END) deb,
                             SUM(CASE WHEN l.is_debours = 1 THEN 0 ELSE l.price_subtotal END) fees')
                ->first();
            $phys = $this->d()->whereBetween('opened_date', [$f, $t])
                ->selectRaw('SUM(weight_kg)/1000 tons, SUM(containers) c')->first();
            $adv = (float) (DB::table('wh_fact_advance')->where('tenant_id', $this->tenantId)
                ->whereBetween('disbursed_date', [$f, $t])->sum('amount'));

            $tons = (float) ($phys->tons ?? 0); $ctn = (int) ($phys->c ?? 0);
            $fees = (float) ($money->fees ?? 0); $deb = (float) ($money->deb ?? 0);
            $out['unit'] = [
                'tons' => round($tons), 'containers' => $ctn, 'fees' => $fees, 'debours_billed' => $deb,
                'advances' => $adv,
                'revenue_per_ton'  => $tons > 0 ? ($fees + $deb) / $tons : null,
                'fees_per_ctn'     => $ctn > 0 ? $fees / $ctn : null,
                'fees_per_ton'     => $tons > 0 ? $fees / $tons : null,
                'debours_per_ton'  => $tons > 0 ? $deb / $tons : null,
                'advances_per_ton' => $tons > 0 ? $adv / $tons : null,
            ];

            // Per-dossier reconciliation (all time — files live longer than periods).
            $rows = DB::table('wh_fact_dossier as d')
                ->leftJoin(DB::raw('(select i.tenant_id tid, i.dossier_odoo_id did,
                        SUM(CASE WHEN l.is_debours = 1 THEN l.price_subtotal ELSE 0 END) deb,
                        SUM(CASE WHEN l.is_debours = 1 THEN 0 ELSE l.price_subtotal END) fees
                      from wh_fact_invoice_line l
                      join wh_fact_invoice i on i.tenant_id = l.tenant_id and i.odoo_id = l.move_odoo_id
                      where i.dossier_odoo_id is not null group by i.tenant_id, i.dossier_odoo_id) b'),
                    function ($j) { $j->on('b.tid', '=', 'd.tenant_id')->on('b.did', '=', 'd.odoo_id'); })
                ->leftJoin(DB::raw('(select tenant_id tid2, dossier_odoo_id did2, SUM(amount) adv
                      from wh_fact_advance where dossier_odoo_id is not null group by tenant_id, dossier_odoo_id) a'),
                    function ($j) { $j->on('a.tid2', '=', 'd.tenant_id')->on('a.did2', '=', 'd.odoo_id'); })
                ->where('d.tenant_id', $this->tenantId)
                ->whereRaw('(b.did is not null or a.did2 is not null)')
                ->selectRaw('d.code, d.client_name, d.type_name,
                             COALESCE(b.fees,0) fees, COALESCE(b.deb,0) deb, COALESCE(a.adv,0) adv,
                             COALESCE(b.fees,0) + COALESCE(b.deb,0) - COALESCE(a.adv,0) margin,
                             COALESCE(b.deb,0) - COALESCE(a.adv,0) variance')
                ->get();

            $recon = ['files' => $rows->count(), 'billed' => 0.0, 'incurred' => 0.0,
                      'gain_n' => 0, 'gain' => 0.0, 'loss_n' => 0, 'loss' => 0.0,
                      'billed_no_cost_n' => 0, 'cost_no_billing_n' => 0, 'nil_n' => 0];
            foreach ($rows as $r) {
                $recon['billed'] += (float) $r->deb; $recon['incurred'] += (float) $r->adv;
                if ($r->deb > 0 && $r->adv == 0)      $recon['billed_no_cost_n']++;
                elseif ($r->adv > 0 && $r->deb == 0)  $recon['cost_no_billing_n']++;
                elseif (abs((float) $r->variance) < 1) $recon['nil_n']++;
                elseif ($r->variance > 0) { $recon['gain_n']++; $recon['gain'] += (float) $r->variance; }
                else                       { $recon['loss_n']++; $recon['loss'] += -(float) $r->variance; }
            }
            $out['recon']  = $recon;
            $sorted = $rows->sortByDesc('margin')->values();
            $out['top']    = $sorted->take(8)->all();
            $out['bottom'] = $sorted->reverse()->take(8)->values()->all();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('logistics profitability analysis failed', ['error' => $e->getMessage()]);
        }
        return $out;
    }

    /**
     * Billing page extension — two lag analyses:
     *  1. File creation → first billing date (per customer, per file).
     *  2. Débours incurred (advance disbursed) → rebilled to the client,
     *     matched by NAME within the same file (normalized fuzzy match,
     *     not exact — 'Transport conventionnel - Kimalé' matches
     *     'Transport conventionnel QUAI - MAG KIMALE').
     *
     * $customer narrows the transaction detail to one client (drill-down).
     */
    public function billingLags(\DateTimeImmutable $from, \DateTimeImmutable $to, ?string $customer = null): array
    {
        $out = ['file_bill' => null, 'debours' => null];
        try {
            $f = $from->format('Y-m-d'); $t = $to->format('Y-m-d');

            // ── 1. file creation → first invoice ISSUED IN THE PERIOD ──
            // The period filters on invoice_date: "August" means files
            // whose first bill within the selection landed in August, even
            // if the file itself opened earlier. MIN() is taken only over
            // in-period invoices, so a file billed again in a later period
            // doesn't drag an older bill into this window.
            $rows = DB::table('wh_fact_dossier as d')
                ->join('wh_fact_invoice as i', function ($j) {
                    $j->on('i.tenant_id', '=', 'd.tenant_id')->on('i.dossier_odoo_id', '=', 'd.odoo_id');
                })
                ->where('d.tenant_id', $this->tenantId)
                ->whereNotNull('d.opened_date')->whereNotNull('i.invoice_date')
                ->whereBetween('i.invoice_date', [$f, $t])
                ->selectRaw('d.code, d.client_name, d.opened_date, MIN(i.invoice_date) first_bill')
                ->groupBy('d.code', 'd.client_name', 'd.opened_date')
                ->get()
                ->map(function ($r) {
                    $r->days = (new \DateTimeImmutable((string) $r->first_bill))
                        ->diff(new \DateTimeImmutable((string) $r->opened_date))->days;
                    return $r;
                });

            $byClient = [];
            foreach ($rows as $r) {
                $c = (string) ($r->client_name ?? '?');
                ($byClient[$c] ??= ['client' => $c, 'n' => 0, 'sum' => 0]);
                $byClient[$c]['n']++; $byClient[$c]['sum'] += $r->days;
            }
            foreach ($byClient as &$c) { $c['avg'] = $c['n'] > 0 ? $c['sum'] / $c['n'] : null; }
            unset($c);
            usort($byClient, fn ($a, $b) => ($b['avg'] <=> $a['avg']));

            $out['file_bill'] = [
                'avg'       => $rows->count() ? $rows->sum('days') / $rows->count() : null,
                'n'         => $rows->count(),
                'by_client' => array_values($byClient),
                'detail'    => $customer !== null
                    ? $rows->filter(fn ($r) => (string) $r->client_name === $customer)
                        ->sortByDesc('days')->values()->take(100)->all()
                    : [],
            ];

            // ── 2. débours incurred → rebilled, anchored on BILLS ISSUED
            // IN THE PERIOD (the recharge line's invoice_date). The
            // matching advance can predate the period — that's normal:
            // a cost incurred in June is often only rebilled once the
            // client invoice goes out in August.
            $linesInPeriod = DB::table('wh_fact_invoice_line as l')
                ->join('wh_fact_invoice as i', function ($j) {
                    $j->on('i.tenant_id', '=', 'l.tenant_id')->on('i.odoo_id', '=', 'l.move_odoo_id');
                })
                ->where('l.tenant_id', $this->tenantId)->where('l.is_debours', true)
                ->whereNotNull('i.dossier_odoo_id')->whereBetween('l.invoice_date', [$f, $t])
                ->get(['i.dossier_odoo_id', 'l.label', 'l.price_subtotal', 'l.invoice_date', 'i.name as inv']);

            $advances = DB::table('wh_fact_advance')
                ->where('tenant_id', $this->tenantId)
                ->whereNotNull('dossier_odoo_id')->whereNotNull('disbursed_date')
                ->get(['dossier_odoo_id', 'label', 'amount', 'disbursed_date', 'supplier_name']);
            $meta = DB::table('wh_fact_dossier')->where('tenant_id', $this->tenantId)
                ->pluck('client_name', 'odoo_id');
            $codes = DB::table('wh_fact_dossier')->where('tenant_id', $this->tenantId)
                ->pluck('code', 'odoo_id');

            $advByD = [];
            foreach ($advances as $a) { $advByD[$a->dossier_odoo_id][] = $a; }

            $pairs = []; $matched = 0; $unmatched = 0; $lagSum = 0.0;
            foreach ($linesInPeriod as $l) {
                $cands = $advByD[$l->dossier_odoo_id] ?? [];
                $lt = $this->normalizeExpenseWords($l->label);
                $best = null; $bestScore = 0;
                foreach ($cands as $a) {
                    $at = $this->normalizeExpenseWords($a->label);
                    $score = count(array_intersect($lt, $at));
                    if ($score > $bestScore) { $bestScore = $score; $best = $a; }
                }
                if ($best !== null && $bestScore >= 1) {
                    $days = (int) ((strtotime((string) $l->invoice_date) - strtotime((string) $best->disbursed_date)) / 86400);
                    $matched++; $lagSum += $days;
                    $pairs[] = [
                        'client'      => (string) ($meta[$l->dossier_odoo_id] ?? '?'),
                        'file'        => (string) ($codes[$l->dossier_odoo_id] ?? $l->dossier_odoo_id),
                        'adv_label'   => (string) $best->label,
                        'adv_who'     => (string) ($best->supplier_name ?? '—'),
                        'adv_date'    => (string) $best->disbursed_date,
                        'adv_amount'  => (float) $best->amount,
                        'bill_label'  => (string) $l->label,
                        'bill_inv'    => (string) $l->inv,
                        'bill_date'   => (string) $l->invoice_date,
                        'bill_amount' => (float) $l->price_subtotal,
                        'days'        => $days,
                    ];
                } else {
                    $unmatched++;
                }
            }

            $byClient2 = [];
            foreach ($pairs as $p) {
                ($byClient2[$p['client']] ??= ['client' => $p['client'], 'n' => 0, 'sum' => 0]);
                $byClient2[$p['client']]['n']++; $byClient2[$p['client']]['sum'] += $p['days'];
            }
            foreach ($byClient2 as &$c2) { $c2['avg'] = $c2['n'] > 0 ? $c2['sum'] / $c2['n'] : null; }
            unset($c2);
            usort($byClient2, fn ($a, $b) => ($b['avg'] <=> $a['avg']));

            $detail = $customer !== null
                ? array_values(array_filter($pairs, fn ($p) => $p['client'] === $customer))
                : [];
            usort($detail, fn ($a, $b) => $b['days'] <=> $a['days']);

            $out['debours'] = [
                'avg'        => $matched > 0 ? $lagSum / $matched : null,
                'matched'    => $matched,
                'unmatched'  => $unmatched,
                'match_rate' => ($matched + $unmatched) > 0 ? $matched / ($matched + $unmatched) : null,
                'by_client'  => array_values($byClient2),
                'detail'     => array_slice($detail, 0, 150),
            ];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('logistics billing lags failed', ['error' => $e->getMessage()]);
        }
        return $out;
    }

    /** Shared fuzzy-match tokenizer for débours label matching (name match, not exact). */
    private function normalizeExpenseWords(?string $s): array
    {
        $s = mb_strtolower((string) $s);
        $s = strtr($s, ['é'=>'e','è'=>'e','ê'=>'e','à'=>'a','â'=>'a','î'=>'i','ï'=>'i','ô'=>'o','û'=>'u','ù'=>'u','ç'=>'c']);
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
        return array_values(array_filter(explode(' ', trim((string) $s)), fn ($t) => mb_strlen($t) >= 4));
    }

    /**
     * Sandbox — Unbilled Débours. Advances DISBURSED in the period that
     * have never been name-matched to any débours line billed to the
     * client (searched across all time, since the recharge — if it ever
     * happens — may land in a later period). A chase-up list: cash paid
     * out with nothing recovered from the client yet.
     */
    public function unbilledDebours(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $out = ['count' => 0, 'total' => 0.0, 'items' => [], 'by_client' => []];
        try {
            $advances = DB::table('wh_fact_advance')->where('tenant_id', $this->tenantId)
                ->whereNotNull('dossier_odoo_id')
                ->whereBetween('disbursed_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
                ->get(['dossier_odoo_id', 'label', 'amount', 'disbursed_date', 'supplier_name']);
            if ($advances->isEmpty()) return $out;

            $ids = $advances->pluck('dossier_odoo_id')->unique()->all();
            $lines = DB::table('wh_fact_invoice_line as l')
                ->join('wh_fact_invoice as i', function ($j) {
                    $j->on('i.tenant_id', '=', 'l.tenant_id')->on('i.odoo_id', '=', 'l.move_odoo_id');
                })
                ->where('l.tenant_id', $this->tenantId)->where('l.is_debours', true)
                ->whereIn('i.dossier_odoo_id', $ids)
                ->get(['i.dossier_odoo_id', 'l.label']);
            $linesByD = [];
            foreach ($lines as $l) { $linesByD[$l->dossier_odoo_id][] = $l->label; }

            $meta  = DB::table('wh_fact_dossier')->where('tenant_id', $this->tenantId)->whereIn('odoo_id', $ids)->pluck('client_name', 'odoo_id');
            $codes = DB::table('wh_fact_dossier')->where('tenant_id', $this->tenantId)->whereIn('odoo_id', $ids)->pluck('code', 'odoo_id');

            foreach ($advances as $a) {
                $at = $this->normalizeExpenseWords($a->label);
                $found = false;
                foreach (($linesByD[$a->dossier_odoo_id] ?? []) as $lbl) {
                    if (count(array_intersect($at, $this->normalizeExpenseWords($lbl))) >= 1) { $found = true; break; }
                }
                if ($found) continue;
                $out['items'][] = [
                    'client' => (string) ($meta[$a->dossier_odoo_id] ?? '?'),
                    'file'   => (string) ($codes[$a->dossier_odoo_id] ?? $a->dossier_odoo_id),
                    'who'    => (string) ($a->supplier_name ?? '—'),
                    'label'  => (string) $a->label,
                    'date'   => (string) $a->disbursed_date,
                    'amount' => (float) $a->amount,
                ];
                $out['total'] += (float) $a->amount;
            }
            $out['count'] = count($out['items']);
            usort($out['items'], fn ($x, $y) => $y['amount'] <=> $x['amount']);

            $byC = [];
            foreach ($out['items'] as $it) {
                ($byC[$it['client']] ??= ['client' => $it['client'], 'n' => 0, 'amt' => 0.0]);
                $byC[$it['client']]['n']++; $byC[$it['client']]['amt'] += $it['amount'];
            }
            usort($byC, fn ($x, $y) => $y['amt'] <=> $x['amt']);
            $out['by_client'] = array_values($byC);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('logistics unbilled debours failed', ['error' => $e->getMessage()]);
        }
        return $out;
    }

    /**
     * Sandbox — Unbilled Revenue/Commissions. Files with activity in the
     * period (opened, invoiced, or an advance disbursed) whose all-time
     * fee revenue is zero — work done or cost incurred with no commission
     * ever earned on that file.
     */
    public function unbilledRevenue(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $out = ['count' => 0, 'files' => [], 'by_client' => [], 'detail' => [],
                'total_debours_billed' => 0.0, 'total_advances' => 0.0];
        try {
            $f = $from->format('Y-m-d'); $t = $to->format('Y-m-d');

            $activeIds = DB::table('wh_fact_dossier')->where('tenant_id', $this->tenantId)
                ->whereBetween('opened_date', [$f, $t])->pluck('odoo_id')
                ->merge(DB::table('wh_fact_invoice')->where('tenant_id', $this->tenantId)
                    ->whereNotNull('dossier_odoo_id')->whereBetween('invoice_date', [$f, $t])->pluck('dossier_odoo_id'))
                ->merge(DB::table('wh_fact_advance')->where('tenant_id', $this->tenantId)
                    ->whereNotNull('dossier_odoo_id')->whereBetween('disbursed_date', [$f, $t])->pluck('dossier_odoo_id'))
                ->unique()->values();
            if ($activeIds->isEmpty()) return $out;
            $ids = $activeIds->all();

            $feeSub = DB::table('wh_fact_invoice_line as l')
                ->join('wh_fact_invoice as i', function ($j) {
                    $j->on('i.tenant_id', '=', 'l.tenant_id')->on('i.odoo_id', '=', 'l.move_odoo_id');
                })
                ->where('l.tenant_id', $this->tenantId)->whereIn('i.dossier_odoo_id', $ids)
                ->selectRaw("i.dossier_odoo_id did,
                             SUM(CASE WHEN l.is_debours = 1 THEN 0 ELSE l.price_subtotal END) fees,
                             SUM(CASE WHEN l.is_debours = 1 THEN l.price_subtotal ELSE 0 END) deb")
                ->groupBy('i.dossier_odoo_id')->get()->keyBy('did');

            $advSub = DB::table('wh_fact_advance')->where('tenant_id', $this->tenantId)
                ->whereIn('dossier_odoo_id', $ids)
                ->selectRaw('dossier_odoo_id did, SUM(amount) amt')
                ->groupBy('dossier_odoo_id')->get()->keyBy('did');

            $meta = DB::table('wh_fact_dossier')->where('tenant_id', $this->tenantId)->whereIn('odoo_id', $ids)
                ->get(['odoo_id', 'code', 'client_name', 'type_name', 'opened_date'])->keyBy('odoo_id');

            // Per-transaction detail for the file-level drilldown: every
            // advance (incurred) and every invoice line (issued) on these
            // files, all-time — a file's reconciliation isn't bounded by
            // the period that made it "active" and worth reviewing.
            $advItems = DB::table('wh_fact_advance')->where('tenant_id', $this->tenantId)
                ->whereIn('dossier_odoo_id', $ids)
                ->get(['dossier_odoo_id', 'label', 'supplier_name', 'amount', 'disbursed_date']);
            $invItems = DB::table('wh_fact_invoice_line as l')
                ->join('wh_fact_invoice as i', function ($j) {
                    $j->on('i.tenant_id', '=', 'l.tenant_id')->on('i.odoo_id', '=', 'l.move_odoo_id');
                })
                ->where('l.tenant_id', $this->tenantId)->whereIn('i.dossier_odoo_id', $ids)
                ->get(['i.dossier_odoo_id', 'l.label', 'i.name as inv', 'l.invoice_date', 'l.price_subtotal']);

            $invByD = [];
            foreach ($invItems as $it) { $invByD[$it->dossier_odoo_id][] = $it; }

            $detailByD = [];
            foreach ($advItems as $a) {
                $at = $this->normalizeExpenseWords($a->label);
                $matched = false;
                foreach (($invByD[$a->dossier_odoo_id] ?? []) as $li) {
                    if (count(array_intersect($at, $this->normalizeExpenseWords($li->label))) >= 1) { $matched = true; break; }
                }
                $detailByD[$a->dossier_odoo_id]['incurred'][] = [
                    'label' => (string) $a->label, 'who' => (string) ($a->supplier_name ?? '—'),
                    'date' => (string) $a->disbursed_date, 'amount' => (float) $a->amount,
                    'matched' => $matched,
                ];
            }
            foreach ($invItems as $li) {
                $detailByD[$li->dossier_odoo_id]['invoiced'][] = [
                    'inv' => (string) $li->inv, 'label' => (string) $li->label,
                    'date' => (string) $li->invoice_date, 'amount' => (float) $li->price_subtotal,
                ];
            }

            foreach ($ids as $id) {
                $fee = (float) ($feeSub[$id]->fees ?? 0);
                if (abs($fee) > 0.5) continue;
                $m = $meta[$id] ?? null; if (!$m) continue;
                $deb = (float) ($feeSub[$id]->deb ?? 0);
                $adv = (float) ($advSub[$id]->amt ?? 0);
                $out['files'][] = [
                    'id' => (string) $id, 'code' => (string) $m->code, 'client' => (string) $m->client_name,
                    'type' => (string) $m->type_name, 'opened' => (string) $m->opened_date,
                    'debours_billed' => $deb, 'advances' => $adv,
                ];
                $out['detail'][(string) $id] = [
                    'incurred' => $detailByD[$id]['incurred'] ?? [],
                    'invoiced' => $detailByD[$id]['invoiced'] ?? [],
                ];
                $out['total_debours_billed'] += $deb; $out['total_advances'] += $adv;
            }
            $out['count'] = count($out['files']);
            usort($out['files'], fn ($a, $b) => $b['advances'] <=> $a['advances']);

            // Per-client summary — shown first; files (and their
            // transaction detail) only render once a client is expanded.
            $byC = [];
            foreach ($out['files'] as $r) {
                ($byC[$r['client']] ??= ['client' => $r['client'], 'n' => 0, 'deb' => 0.0, 'adv' => 0.0]);
                $byC[$r['client']]['n']++;
                $byC[$r['client']]['deb'] += $r['debours_billed'];
                $byC[$r['client']]['adv'] += $r['advances'];
            }
            usort($byC, fn ($a, $b) => $b['adv'] <=> $a['adv']);
            $out['by_client'] = array_values($byC);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('logistics unbilled revenue failed', ['error' => $e->getMessage()]);
        }
        return $out;
    }

    /**
     * Reports_Logistics — revenue per customer for the period.
     * Revenue is the FEE commission only (warehouse amount_untaxed carries
     * the fee portion of invoice lines; débours are rebilled costs, not
     * revenue). Includes each customer's % contribution to total revenue
     * and the running cumulative share.
     */
    public function revenueByCustomer(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $out = ['total' => 0.0, 'customers' => [], 'invoices' => 0];
        try {
            $rows = DB::table('wh_fact_invoice as i')
                ->leftJoin('wh_dim_partner as p', function ($j) {
                    $j->on('p.tenant_id', '=', 'i.tenant_id')->on('p.odoo_id', '=', 'i.partner_odoo_id');
                })
                ->where('i.tenant_id', $this->tenantId)
                ->whereBetween('i.invoice_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
                ->selectRaw("COALESCE(p.name, '—') client, SUM(i.amount_untaxed) revenue, COUNT(*) invoices")
                ->groupBy('client')->orderByDesc('revenue')->get();

            $total = (float) $rows->sum('revenue');
            $cum = 0.0;
            $out['total']    = $total;
            $out['invoices'] = (int) $rows->sum('invoices');
            $out['customers'] = $rows->map(function ($r) use ($total, &$cum) {
                $share = $total > 0 ? 100.0 * (float) $r->revenue / $total : 0.0;
                $cum += $share;
                return [
                    'client'   => (string) $r->client,
                    'revenue'  => (float) $r->revenue,
                    'invoices' => (int) $r->invoices,
                    'share'    => $share,
                    'cum'      => $cum,
                ];
            })->all();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('logistics revenue by customer failed', ['error' => $e->getMessage()]);
        }
        return $out;
    }

    /**
     * Invoice-level detail for the customer roll-down / drill-down.
     * Fee revenue only (débours excluded), matching revenueByCustomer().
     */
    public function customerInvoices(\DateTimeImmutable $from, \DateTimeImmutable $to, ?string $client = null): array
    {
        try {
            $q = DB::table('wh_fact_invoice as i')
                ->leftJoin('wh_dim_partner as p', function ($j) {
                    $j->on('p.tenant_id', '=', 'i.tenant_id')->on('p.odoo_id', '=', 'i.partner_odoo_id');
                })
                ->where('i.tenant_id', $this->tenantId)
                ->whereBetween('i.invoice_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
                ->when($client !== null, fn ($q) => $q->where('p.name', $client))
                ->selectRaw("i.name inv, i.invoice_date d, COALESCE(p.name,'—') client,
                             i.amount_untaxed fee, i.amount_total ttc, i.amount_residual outstanding")
                ->orderBy('i.invoice_date');
            return $q->get()->map(fn ($r) => (array) $r)->all();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('logistics customer invoices failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * OOP — Out of Pocket Expense Compliance. Per file: débours incurred
     * (cash advances disbursed in the period) vs débours recharged to the
     * client (débours-flagged lines billed in the period), the gap in
     * value and %, and the file's customer. Gap > 0 = recharged MORE than
     * incurred (client absorbs the excess, shown green); gap < 0 =
     * recharged LESS (Elimelec absorbs the loss, shown red).
     *
     * Period-scoped like the rest of the sector's pages — the master
     * filter drives which transactions count. Also returns per-file
     * transaction detail (the underlying incurred/recharged line items)
     * for the drill-down.
     */
    public function oopCompliance(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $out = ['files' => [], 'incurred' => 0.0, 'recharged' => 0.0, 'over_n' => 0, 'under_n' => 0, 'nil_n' => 0, 'detail' => []];
        try {
            $f = $from->format('Y-m-d'); $t = $to->format('Y-m-d');

            $debSub = DB::table('wh_fact_invoice_line as l')
                ->join('wh_fact_invoice as i', function ($j) {
                    $j->on('i.tenant_id', '=', 'l.tenant_id')->on('i.odoo_id', '=', 'l.move_odoo_id');
                })
                ->where('l.tenant_id', $this->tenantId)->where('l.is_debours', true)
                ->whereNotNull('i.dossier_odoo_id')->whereBetween('l.invoice_date', [$f, $t])
                ->selectRaw('i.tenant_id as tid, i.dossier_odoo_id as did, SUM(l.price_subtotal) as recharged')
                ->groupBy('i.tenant_id', 'i.dossier_odoo_id');

            $advSub = DB::table('wh_fact_advance')
                ->where('tenant_id', $this->tenantId)->whereNotNull('dossier_odoo_id')
                ->whereBetween('disbursed_date', [$f, $t])
                ->selectRaw('tenant_id as tid2, dossier_odoo_id as did2, SUM(amount) as incurred')
                ->groupBy('tenant_id', 'dossier_odoo_id');

            $rows = DB::table('wh_fact_dossier as d')
                ->leftJoinSub($debSub, 'b', fn ($j) => $j->on('b.tid', '=', 'd.tenant_id')->on('b.did', '=', 'd.odoo_id'))
                ->leftJoinSub($advSub, 'a', fn ($j) => $j->on('a.tid2', '=', 'd.tenant_id')->on('a.did2', '=', 'd.odoo_id'))
                ->where('d.tenant_id', $this->tenantId)
                ->whereRaw('(b.did is not null or a.did2 is not null)')
                ->selectRaw('d.odoo_id, d.code, d.client_name, COALESCE(a.incurred,0) incurred, COALESCE(b.recharged,0) recharged,
                             COALESCE(b.recharged,0) - COALESCE(a.incurred,0) gap')
                ->orderByRaw('ABS(COALESCE(b.recharged,0) - COALESCE(a.incurred,0)) DESC')
                ->get();

            $out['incurred']  = (float) $rows->sum('incurred');
            $out['recharged'] = (float) $rows->sum('recharged');
            $out['files'] = $rows->map(function ($r) {
                $gap = (float) $r->gap;
                $pct = $r->incurred > 0 ? 100.0 * $gap / (float) $r->incurred : ($gap > 0 ? null : 0.0);
                return [
                    'id' => (string) $r->odoo_id, 'code' => (string) $r->code, 'client' => (string) $r->client_name,
                    'incurred' => (float) $r->incurred, 'recharged' => (float) $r->recharged,
                    'gap' => $gap, 'gap_pct' => $pct,
                ];
            })->all();
            foreach ($out['files'] as $fl) {
                if (abs($fl['gap']) < 1) $out['nil_n']++;
                elseif ($fl['gap'] > 0) $out['over_n']++;
                else $out['under_n']++;
            }

            // Transaction-level detail for the drill-down, keyed by dossier
            // odoo id (matches the surrogate the view uses for its target).
            $ids = $rows->pluck('odoo_id')->all();
            if ($ids !== []) {
                $advLines = DB::table('wh_fact_advance')
                    ->where('tenant_id', $this->tenantId)->whereIn('dossier_odoo_id', $ids)
                    ->whereBetween('disbursed_date', [$f, $t])
                    ->get(['dossier_odoo_id', 'label', 'supplier_name', 'amount', 'disbursed_date']);
                $debLines = DB::table('wh_fact_invoice_line as l')
                    ->join('wh_fact_invoice as i', function ($j) {
                        $j->on('i.tenant_id', '=', 'l.tenant_id')->on('i.odoo_id', '=', 'l.move_odoo_id');
                    })
                    ->where('l.tenant_id', $this->tenantId)->where('l.is_debours', true)
                    ->whereIn('i.dossier_odoo_id', $ids)->whereBetween('l.invoice_date', [$f, $t])
                    ->get(['i.dossier_odoo_id', 'l.label', 'i.name as inv', 'l.price_subtotal', 'l.invoice_date']);

                $detail = [];
                foreach ($advLines as $a) {
                    $detail[$a->dossier_odoo_id]['incurred'][] = [
                        'label' => (string) $a->label, 'who' => (string) ($a->supplier_name ?? '—'),
                        'date' => (string) $a->disbursed_date, 'amount' => (float) $a->amount,
                    ];
                }
                foreach ($debLines as $l) {
                    $detail[$l->dossier_odoo_id]['recharged'][] = [
                        'label' => (string) $l->label, 'inv' => (string) $l->inv,
                        'date' => (string) $l->invoice_date, 'amount' => (float) $l->price_subtotal,
                    ];
                }
                foreach ($rows as $r) {
                    $out['detail'][(string) $r->odoo_id] = [
                        'incurred'  => $detail[$r->odoo_id]['incurred'] ?? [],
                        'recharged' => $detail[$r->odoo_id]['recharged'] ?? [],
                    ];
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('logistics oop compliance failed', ['error' => $e->getMessage()]);
        }
        return $out;
    }

    /** True when this tenant has synced dossier facts (gates the sections). */
    public static function available(?int $tenantId): bool
    {
        if (!$tenantId) return false;
        try {
            return DB::table('wh_fact_dossier')->where('tenant_id', $tenantId)->limit(1)->exists();
        } catch (\Throwable) {
            return false;
        }
    }
}
