<?php
declare(strict_types=1);

namespace App\Services;

use App\Odoo\OdooClient;

/**
 * Operations service — container & logistics analytics.
 *
 * Sourced from the customer's Odoo Logistique module (custom models
 * exposing container records, scan events, manifestes, EDI documents).
 *
 *  ┌─────────────────────────────────────────────────────────────────┐
 *  │ STATUS: schema-discovery phase                                  │
 *  │                                                                 │
 *  │ The actual model and field names are unknown until the          │
 *  │ /admin/tenants/{id}/odoo-probe diagnostic returns. Every Odoo   │
 *  │ call below is therefore stubbed and marked `pending => true`.   │
 *  │ The view renders "Pending data" placeholders when it sees that  │
 *  │ flag, so the page is functional today.                          │
 *  │                                                                 │
 *  │ When the probe results land, integration is localised to this   │
 *  │ file: each `*Pending()` helper becomes a real searchRead /      │
 *  │ read_group call, the `pending` flag flips to false, and the     │
 *  │ view immediately shows live data without further changes.       │
 *  └─────────────────────────────────────────────────────────────────┘
 *
 * KPI shape contract — each helper returns:
 *   [
 *     'value'   => mixed,    // formatted number, ratio, or breakdown array
 *     'sub'     => string,   // optional sub-text (denominator detail)
 *     'pending' => bool,     // true while we don't have schema yet
 *     'vsPy'    => ?float,   // % vs prior-year same window (optional)
 *     'vsPlan'  => ?float,   // % vs plan (optional, no plan today)
 *   ]
 *
 * The shape mirrors what RevenueService's helpers produce so the
 * <x-kpi> component can consume Operations KPIs without modification.
 */
final class OperationsService
{
    private OdooClient $odoo;

    public function __construct(OdooClient $odoo)
    {
        $this->odoo = $odoo;
    }

    public function analyze(?\DateTimeImmutable $today = null, array $period = []): array
    {
        $today = $today ?? new \DateTimeImmutable('today');
        [$cyFrom, $cyTo, $periodLabel] = ea_resolve_period($today, $period);

        $pyFrom = $cyFrom->modify('-1 year');
        $pyTo   = $cyTo->modify('-1 year');

        $cyWorkingDays = $this->workingDays($cyFrom, $cyTo);
        $pyWorkingDays = $this->workingDays($pyFrom, $pyTo);

        // KPI map. Each entry is consumed by operations.blade.php and
        // rendered through <x-kpi>. While we're in schema-discovery the
        // helpers all return `pending => true` and the view shows the
        // placeholder UI; flipping a single helper to live data is a
        // local change.
        $kpis = [
            'containers_scanned'  => $this->containersScannedPending($cyFrom, $cyTo, $pyFrom, $pyTo),
            'containers_per_day'  => $this->containersPerDayPending($cyFrom, $cyTo, $cyWorkingDays, $pyWorkingDays),
            'avg_dwell_hours'     => $this->avgDwellHoursPending($cyFrom, $cyTo, $pyFrom, $pyTo),
            'mix_by_type'         => $this->mixByTypePending($cyFrom, $cyTo),
            'reefer_share'        => $this->reeferSharePending($cyFrom, $cyTo, $pyFrom, $pyTo),
            'revenue_per_container' => $this->revenuePerContainerPending($cyFrom, $cyTo, $pyFrom, $pyTo),
            'top_customers'       => $this->topCustomersPending($cyFrom, $cyTo, 5),
            'top_destinations'    => $this->topDestinationsPending($cyFrom, $cyTo, 5),
        ];

        return [
            'currency' => $this->detectCurrency(),
            'period'   => [
                'from'  => $cyFrom->format('Y-m-d'),
                'to'    => $cyTo->format('Y-m-d'),
                'label' => $periodLabel,
                'cy_working_days' => $cyWorkingDays,
                'py_working_days' => $pyWorkingDays,
            ],
            'kpis'     => $kpis,
            'pending_overall' => collect($kpis)->every(fn ($k) => !empty($k['pending'])),
        ];
    }

    /* ── KPI helpers (pending — to be replaced once schema is known) ──── */

    private function containersScannedPending(\DateTimeImmutable $cyFrom, \DateTimeImmutable $cyTo, \DateTimeImmutable $pyFrom, \DateTimeImmutable $pyTo): array
    {
        // TARGET (post-probe):
        //   $cy = $this->odoo->searchCount('<scan_model>', [
        //       ['<scan_type_field>', '=', 'sortie'],
        //       ['<datetime_field>', '>=', $cyFrom->format('Y-m-d')],
        //       ['<datetime_field>', '<=', $cyTo->format('Y-m-d 23:59:59')],
        //   ]);
        //   $py = …same with pyFrom/pyTo
        //   return ['value' => $cy, 'sub' => 'Containers shipped', 'pending' => false,
        //           'vsPy' => $this->pct($cy, $py)];
        return ['value' => null, 'sub' => 'Period count (entrée + sortie)', 'pending' => true];
    }

    private function containersPerDayPending(\DateTimeImmutable $cyFrom, \DateTimeImmutable $cyTo, int $cyWd, int $pyWd): array
    {
        // TARGET: containers_scanned / cyWd
        return ['value' => null, 'sub' => 'Throughput rate · 6-day basis', 'pending' => true];
    }

    private function avgDwellHoursPending(\DateTimeImmutable $cyFrom, \DateTimeImmutable $cyTo, \DateTimeImmutable $pyFrom, \DateTimeImmutable $pyTo): array
    {
        // TARGET: pair entrée + sortie scans by container_id, compute avg
        // (sortie_dt - entrée_dt) in hours. Will likely need read_group on
        // <scan_model> with groupby=container_id to find paired rows.
        return ['value' => null, 'sub' => 'Hours between entrée & sortie', 'pending' => true];
    }

    private function mixByTypePending(\DateTimeImmutable $cyFrom, \DateTimeImmutable $cyTo): array
    {
        // TARGET: read_group on container model groupby=container_type.
        // Returns ['20ft' => N, '40ft' => N, '40hc' => N, 'reefer' => N].
        return ['value' => null, 'sub' => "20'/40'/40HC/Reefer share", 'pending' => true];
    }

    private function reeferSharePending(\DateTimeImmutable $cyFrom, \DateTimeImmutable $cyTo, \DateTimeImmutable $pyFrom, \DateTimeImmutable $pyTo): array
    {
        // TARGET: count(reefer) / count(all)
        return ['value' => null, 'sub' => 'Refrigerated as % of total', 'pending' => true];
    }

    private function revenuePerContainerPending(\DateTimeImmutable $cyFrom, \DateTimeImmutable $cyTo, \DateTimeImmutable $pyFrom, \DateTimeImmutable $pyTo): array
    {
        // TARGET: sum(invoice.amount_total via container.invoice_id) / count(container)
        return ['value' => null, 'sub' => 'Invoice value ÷ containers', 'pending' => true];
    }

    private function topCustomersPending(\DateTimeImmutable $cyFrom, \DateTimeImmutable $cyTo, int $limit): array
    {
        // TARGET: read_group groupby=partner_id, sort desc, take $limit
        return ['value' => null, 'sub' => 'Containers per partner', 'pending' => true];
    }

    private function topDestinationsPending(\DateTimeImmutable $cyFrom, \DateTimeImmutable $cyTo, int $limit): array
    {
        // TARGET: read_group groupby=destination_country_id, sort desc
        return ['value' => null, 'sub' => 'Containers per export country', 'pending' => true];
    }

    /* ── Shared helpers (already real today) ───────────────────────────── */

    /**
     * Mon-Sat working days in [from, to]. Matches RevenueService basis so
     * any "per day" KPI is consistent with the Dashboard's RpD/Run-Rate.
     */
    private function workingDays(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        if ($to < $from) return 0;
        $days = 0;
        for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) {
            // format('w'): Sunday = 0 → skip; everything else (Mon-Sat) counts.
            if ((int) $d->format('w') === 0) continue;
            $days++;
        }
        return max(1, $days);
    }

    private function detectCurrency(): string
    {
        try {
            $user = $this->odoo->read('res.users', [$this->odoo->authenticate()], ['company_id']);
            $companyId = isset($user[0]['company_id'][0]) ? (int) $user[0]['company_id'][0] : 1;
            $company = $this->odoo->read('res.company', [$companyId], ['currency_id']);
            $curId = isset($company[0]['currency_id'][0]) ? (int) $company[0]['currency_id'][0] : 0;
            if ($curId > 0) {
                $cur = $this->odoo->read('res.currency', [$curId], ['name']);
                if (isset($cur[0]['name'])) return (string) $cur[0]['name'];
            }
        } catch (\Throwable $e) {
            // fall through
        }
        return (string) (config_env('CURRENCY', 'XAF') ?: 'XAF');
    }

    private function pct(?float $a, ?float $b): ?float
    {
        if (!is_numeric($a) || !is_numeric($b)) return null;
        if ((float) $b === 0.0) return ((float) $a === 0.0) ? 0.0 : null;
        return (((float) $a - (float) $b) / abs((float) $b)) * 100.0;
    }
}
