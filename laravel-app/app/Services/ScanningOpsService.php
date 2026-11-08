<?php
declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Container-scanning operations, built from the warehouse.
 *
 * The existing OperationsService reads aw_cargo.* (vessel calls, cargo,
 * equipment) live from Odoo. Transatlantic's ERP_TSA_SIP_PRODUCTION does
 * NOT have those models — profiled 2026-08-28, all 15 aw_cargo probes came
 * back "not installed". What it does have is the scanning business encoded
 * as invoice lines against container products (20'/40', import/export/
 * transbo) plus a Type_voyage field (domestique/transit) on every move.
 *
 * So this service reconstructs throughput, mix and yield from
 * wh_fact_gl_line × wh_dim_product × wh_fact_invoice — warehouse only,
 * never live Odoo, and every section fails soft.
 */
final class ScanningOpsService
{
    public function __construct(private int $tenantId) {}

    /** Invoice lines that carry a container product, in the window. */
    private function lines(\DateTimeImmutable $from, \DateTimeImmutable $to)
    {
        return ea_tdb()->table('wh_fact_gl_line as l')
            ->join('wh_dim_product as p', function ($j) {
                $j->on('p.tenant_id', '=', 'l.tenant_id')->on('p.odoo_id', '=', 'l.product_odoo_id');
            })
            ->where('l.tenant_id', $this->tenantId)
            ->whereNotNull('l.product_odoo_id')
            ->whereBetween('l.invoice_date', [$from->format('Y-m-d'), $to->format('Y-m-d')]);
    }

    public function analyze(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $out = [
            'containers' => 0, 'revenue' => 0.0, 'per_container' => null, 'lines' => 0,
            'by_category' => [], 'by_month' => [], 'by_voyage' => [], 'top_customers' => [],
            'notes' => [],
        ];
        try {
            $agg = $this->lines($from, $to)
                ->selectRaw('SUM(l.quantity) qty, SUM(l.price_subtotal) rev, COUNT(*) n')->first();
            $out['containers'] = (int) round((float) ($agg->qty ?? 0));
            $out['revenue']    = (float) ($agg->rev ?? 0);
            $out['lines']      = (int) ($agg->n ?? 0);
            $out['per_container'] = $out['containers'] > 0 ? $out['revenue'] / $out['containers'] : null;

            // Container mix — the category name carries size + direction,
            // e.g. "20''/import", "40''/Export".
            $out['by_category'] = $this->lines($from, $to)
                ->selectRaw("COALESCE(p.category_name,'—') k, SUM(l.quantity) qty,
                             SUM(l.price_subtotal) rev, COUNT(*) n")
                ->groupBy('k')->orderByDesc('qty')->limit(15)->get()->all();

            $out['by_month'] = $this->lines($from, $to)
                ->selectRaw("DATE_FORMAT(l.invoice_date,'%Y-%m') m, SUM(l.quantity) qty,
                             SUM(l.price_subtotal) rev")
                ->groupBy('m')->orderBy('m')->get()->all();

            // Voyage type lives on the invoice header, not the line.
            $out['by_voyage'] = ea_tdb()->table('wh_fact_gl_line as l')
                ->join('wh_fact_invoice as i', function ($j) {
                    $j->on('i.tenant_id', '=', 'l.tenant_id')->on('i.odoo_id', '=', 'l.move_odoo_id');
                })
                ->where('l.tenant_id', $this->tenantId)
                ->whereNotNull('l.product_odoo_id')
                ->whereBetween('l.invoice_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
                ->selectRaw("COALESCE(NULLIF(i.voyage_type,''),'unspecified') k,
                             SUM(l.quantity) qty, SUM(l.price_subtotal) rev, COUNT(*) n")
                ->groupBy('k')->orderByDesc('qty')->get()->all();

            $out['top_customers'] = ea_tdb()->table('wh_fact_gl_line as l')
                ->join('wh_dim_product as p', function ($j) {
                    $j->on('p.tenant_id', '=', 'l.tenant_id')->on('p.odoo_id', '=', 'l.product_odoo_id');
                })
                ->join('wh_fact_invoice as i', function ($j) {
                    $j->on('i.tenant_id', '=', 'l.tenant_id')->on('i.odoo_id', '=', 'l.move_odoo_id');
                })
                ->leftJoin('wh_dim_partner as pt', function ($j) {
                    $j->on('pt.tenant_id', '=', 'i.tenant_id')->on('pt.odoo_id', '=', 'i.partner_odoo_id');
                })
                ->where('l.tenant_id', $this->tenantId)
                ->whereNotNull('l.product_odoo_id')
                ->whereBetween('l.invoice_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
                ->selectRaw("COALESCE(pt.name,'—') k, SUM(l.quantity) qty, SUM(l.price_subtotal) rev")
                ->groupBy('k')->orderByDesc('rev')->limit(12)->get()->all();

            if ($out['containers'] === 0) {
                $out['notes'][] = 'No container lines found in this period. Container throughput is '
                    . 'derived from invoice lines carrying a product; if the warehouse product sync '
                    . 'has not finished, this fills in on the next cycle.';
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('scanning ops analysis failed', ['error' => $e->getMessage()]);
        }
        return $out;
    }

    /** True when this tenant has container products synced. */
    public static function available(?int $tenantId): bool
    {
        if (!$tenantId) return false;
        try {
            return ea_tdb()->table('wh_dim_product')->where('tenant_id', $tenantId)->limit(1)->exists();
        } catch (\Throwable) {
            return false;
        }
    }
}
