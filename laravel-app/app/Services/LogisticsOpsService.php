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
    public function billingLags(?string $customer = null): array
    {
        $out = ['file_bill' => null, 'debours' => null];
        try {
            // ── 1. file creation → first invoice ─────────────────────
            $rows = DB::table('wh_fact_dossier as d')
                ->join('wh_fact_invoice as i', function ($j) {
                    $j->on('i.tenant_id', '=', 'd.tenant_id')->on('i.dossier_odoo_id', '=', 'd.odoo_id');
                })
                ->where('d.tenant_id', $this->tenantId)
                ->whereNotNull('d.opened_date')->whereNotNull('i.invoice_date')
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

            // ── 2. débours incurred → rebilled (name match per file) ──
            $advances = DB::table('wh_fact_advance')
                ->where('tenant_id', $this->tenantId)
                ->whereNotNull('dossier_odoo_id')->whereNotNull('disbursed_date')
                ->get(['dossier_odoo_id', 'label', 'amount', 'disbursed_date', 'supplier_name']);
            $lines = DB::table('wh_fact_invoice_line as l')
                ->join('wh_fact_invoice as i', function ($j) {
                    $j->on('i.tenant_id', '=', 'l.tenant_id')->on('i.odoo_id', '=', 'l.move_odoo_id');
                })
                ->where('l.tenant_id', $this->tenantId)->where('l.is_debours', true)
                ->whereNotNull('i.dossier_odoo_id')
                ->get(['i.dossier_odoo_id', 'l.label', 'l.price_subtotal', 'l.invoice_date', 'i.name as inv']);
            $meta = DB::table('wh_fact_dossier')->where('tenant_id', $this->tenantId)
                ->pluck('client_name', 'odoo_id');
            $codes = DB::table('wh_fact_dossier')->where('tenant_id', $this->tenantId)
                ->pluck('code', 'odoo_id');

            $norm = function (?string $s): array {
                $s = mb_strtolower((string) $s);
                $s = strtr($s, ['é'=>'e','è'=>'e','ê'=>'e','à'=>'a','â'=>'a','î'=>'i','ï'=>'i','ô'=>'o','û'=>'u','ù'=>'u','ç'=>'c']);
                $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
                return array_values(array_filter(explode(' ', trim((string) $s)), fn ($t) => mb_strlen($t) >= 4));
            };
            $linesByD = [];
            foreach ($lines as $l) { $linesByD[$l->dossier_odoo_id][] = $l; }

            $pairs = []; $matched = 0; $unmatched = 0; $lagSum = 0.0;
            foreach ($advances as $a) {
                $cands = $linesByD[$a->dossier_odoo_id] ?? [];
                $at = $norm($a->label);
                $best = null; $bestScore = 0;
                foreach ($cands as $l) {
                    $lt = $norm($l->label);
                    $score = count(array_intersect($at, $lt));
                    if ($score > $bestScore) { $bestScore = $score; $best = $l; }
                }
                if ($best !== null && $bestScore >= 1) {
                    $days = (int) ((strtotime((string) $best->invoice_date) - strtotime((string) $a->disbursed_date)) / 86400);
                    $matched++; $lagSum += $days;
                    $pairs[] = [
                        'client'      => (string) ($meta[$a->dossier_odoo_id] ?? '?'),
                        'file'        => (string) ($codes[$a->dossier_odoo_id] ?? $a->dossier_odoo_id),
                        'adv_label'   => (string) $a->label,
                        'adv_who'     => (string) ($a->supplier_name ?? '—'),
                        'adv_date'    => (string) $a->disbursed_date,
                        'adv_amount'  => (float) $a->amount,
                        'bill_label'  => (string) $best->label,
                        'bill_inv'    => (string) $best->inv,
                        'bill_date'   => (string) $best->invoice_date,
                        'bill_amount' => (float) $best->price_subtotal,
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
