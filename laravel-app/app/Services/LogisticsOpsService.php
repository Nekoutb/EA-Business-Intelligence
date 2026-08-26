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
