<?php

namespace App\Http\Controllers;

use App\Odoo\OdooFactory;
use App\Services\BillingService;
use Illuminate\Http\Request;

/**
 * Billing module — invoice-issuance lens (issuance volume, time-to-invoice,
 * credit-note rate). Mirrors OperationsController/RevenueController:
 *   - period filter (YTD/MTD) parsed from query string
 *   - per-period cache key in session ("billing_ytd" / "billing_mtd")
 *   - service call wrapped in try/catch so an Odoo failure renders an
 *     error banner instead of a 500
 */
class BillingController extends Controller
{
    public function index(Request $request)
    {
        $today  = new \DateTimeImmutable();
        $type   = strtolower((string) $request->query('period', 'ytd'));
        if (!in_array($type, ['ytd', 'mtd', 'month'], true)) {
            $type = 'ytd';
        }

        // Year/month optional — only ytd/month consume year, only month
        // consumes month. ea_resolve_period clamps to safe ranges.
        $year  = (int) $request->query('year',  $today->format('Y'));
        $month = (int) $request->query('month', $today->format('m'));
        $period = ['type' => $type, 'year' => $year, 'month' => $month];

        [, , $periodLabel] = ea_resolve_period($today, $period);

        $billing = null;
        $error   = null;

        try {
            $client = OdooFactory::fromConfig();
            $cacheKey = "billing_{$type}_{$year}_{$month}";
            $billing  = ea_cached(
                $cacheKey,
                fn () => (new BillingService($client))->analyze(null, $period)
            );

            // Reconciliation details (per-invoice rows for the expandable
            // panel + CSV export). Cached alongside the headline so the
            // first paint of /billing only does Odoo work once.
            $details = ea_cached(
                "billing_details_{$type}_{$year}_{$month}",
                fn () => (new BillingService($client))->reconciliationDetails(null, $period)
            );
            if (is_array($billing) && is_array($details)) {
                $billing['reconciliation_details'] = $details;
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return view('billing', [
            'active'      => 'billing',
            'period'      => $period,
            'periodLabel' => $periodLabel,
            'billing'     => $billing,
            'error'       => $error,
        ]);
    }

    /**
     * CSV export of the reconciliation details — one row per invoice in
     * the period with HT / Tax / TTC / residual / collected / payment
     * state / days overdue. Streamed so large windows don't OOM.
     */
    public function reconciliationCsv(Request $request)
    {
        $today  = new \DateTimeImmutable();
        $type   = strtolower((string) $request->query('period', 'ytd'));
        if (!in_array($type, ['ytd', 'mtd', 'month'], true)) $type = 'ytd';
        $year   = (int) $request->query('year',  $today->format('Y'));
        $month  = (int) $request->query('month', $today->format('m'));
        $period = ['type' => $type, 'year' => $year, 'month' => $month];

        $client  = OdooFactory::fromConfig();
        $details = (new BillingService($client))->reconciliationDetails(null, $period);

        $filename = "billing_reconciliation_{$type}_{$year}_{$month}.csv";

        return response()->streamDownload(function () use ($details) {
            $out = fopen('php://output', 'w');
            // BOM so Excel opens UTF-8 cleanly
            fputs($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'Invoice', 'Customer Ref', 'Invoice Date', 'Due Date', 'Customer',
                'Amount Untaxed (HT)', 'Tax', 'Amount Total (TTC)',
                'Residual', 'Collected (HT)', '% Collected',
                'Payment State', 'Days Overdue',
            ]);
            foreach (($details['rows'] ?? []) as $r) {
                fputcsv($out, [
                    $r['name'], $r['ref'], $r['invoice_date'], $r['due_date'], $r['partner'],
                    number_format((float) $r['amount_untaxed'], 2, '.', ''),
                    number_format((float) $r['amount_tax'],     2, '.', ''),
                    number_format((float) $r['amount_total'],   2, '.', ''),
                    number_format((float) $r['amount_residual'], 2, '.', ''),
                    number_format((float) $r['collected_ht'],   2, '.', ''),
                    number_format((float) $r['collection_pct'], 1, '.', ''),
                    $r['payment_state'],
                    (int) $r['days_overdue'],
                ]);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
