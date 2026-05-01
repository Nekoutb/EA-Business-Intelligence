<?php

namespace App\Http\Controllers;

use App\Odoo\OdooFactory;
use App\Services\BillingService;
use Illuminate\Http\Request;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\BorderName;
use OpenSpout\Common\Entity\Style\BorderStyle;
use OpenSpout\Common\Entity\Style\BorderWidth;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
     * Excel (.xlsx) export of the reconciliation details — one row per
     * invoice in the period with HT / Tax / TTC / residual / collected
     * payment state / days overdue. Streamed via openspout so large
     * windows don't OOM. Numeric columns are written as real numbers
     * (not pre-formatted strings) so Excel formulas / pivots work
     * directly without "text-to-columns" gymnastics.
     */
    public function reconciliationExcel(Request $request)
    {
        $today  = new \DateTimeImmutable();
        $type   = strtolower((string) $request->query('period', 'ytd'));
        if (!in_array($type, ['ytd', 'mtd', 'month'], true)) $type = 'ytd';
        $year   = (int) $request->query('year',  $today->format('Y'));
        $month  = (int) $request->query('month', $today->format('m'));
        $period = ['type' => $type, 'year' => $year, 'month' => $month];

        [$cyFrom, $cyTo, $periodLabel] = ea_resolve_period($today, $period);

        $client  = OdooFactory::fromConfig();
        $service = new BillingService($client);
        $details = $service->reconciliationDetails(null, $period);
        $recon   = $service->reconciliationOnly(null, $period);

        $filename = "billing_reconciliation_{$type}_{$year}_{$month}.xlsx";

        return new StreamedResponse(function () use ($details, $recon, $periodLabel, $cyFrom, $cyTo) {
            $writer = new Writer();
            $writer->openToFile('php://output');

            // ── Style definitions (openspout v5 immutable API) ───────────
            $headerBorder = new Border(
                new BorderPart(BorderName::BOTTOM, '000000', BorderWidth::THIN, BorderStyle::SOLID)
            );
            $headerStyle = (new Style())
                ->withFontBold(true)->withFontSize(11)
                ->withFontColor(Color::WHITE)
                ->withBackgroundColor('1E3D44')
                ->withCellAlignment(CellAlignment::LEFT)
                ->withBorder($headerBorder);
            $titleStyle = (new Style())->withFontBold(true)->withFontSize(13);
            $metaStyle  = (new Style())->withFontSize(10)->withFontColor('888888');
            $cellBorder = new Border(
                new BorderPart(BorderName::BOTTOM, 'E5E5E5', BorderWidth::THIN, BorderStyle::SOLID)
            );
            $cellStyle    = (new Style())->withFontSize(10)->withBorder($cellBorder);
            $numCellStyle = (new Style())->withFontSize(10)->withBorder($cellBorder)
                                         ->withFormat('#,##0;[Red]-#,##0');
            $pctCellStyle = (new Style())->withFontSize(10)->withBorder($cellBorder)
                                         ->withFormat('0.0"%"');
            $overdueStyle = (new Style())->withFontSize(10)->withBorder($cellBorder)
                                         ->withFontColor('B00020')->withFontBold(true);
            $sumStyle     = (new Style())->withFontBold(true)->withFontSize(11)
                                         ->withBackgroundColor('F5F0E5')
                                         ->withFormat('#,##0;[Red]-#,##0');
            $noteStyle = (new Style())->withFontSize(9)->withFontColor('666666')->withShouldWrapText(true);

            $styledRow = static function (array $values, Style $style): Row {
                $cells = array_map(fn ($v) => Cell::fromValue($v, $style), $values);
                return new Row($cells);
            };

            // ── Title block ──────────────────────────────────────────────
            $writer->addRow($styledRow(['Billing — Revenue Reconciliation Details'], $titleStyle));
            $writer->addRow($styledRow([
                'Period: ' . $periodLabel
                . '   ·   ' . $cyFrom->format('Y-m-d') . ' to ' . $cyTo->format('Y-m-d')
                . '   ·   Generated: ' . (new \DateTimeImmutable())->format('Y-m-d H:i')
            ], $metaStyle));

            // Summary numbers (from headline reconciliation)
            $h = $recon['headline'] ?? [];
            if (!empty($h)) {
                $writer->addRow($styledRow([
                    'Headline (XAF HT): '
                    . 'Expected ' . number_format((float)($h['expected']  ?? 0), 0, '.', ',')
                    . '   ·   Billed '    . number_format((float)($h['billed']    ?? 0), 0, '.', ',')
                    . '   ·   Collected ' . number_format((float)($h['collected'] ?? 0), 0, '.', ',')
                    . '   ·   Billing gap '    . number_format((float)($h['billing_gap']    ?? 0), 0, '.', ',')
                    . '   ·   Collection gap ' . number_format((float)($h['collection_gap'] ?? 0), 0, '.', ',')
                ], $metaStyle));
            }
            $writer->addRow(Row::fromValues(['']));

            // ── Header row ──────────────────────────────────────────────
            $writer->addRow($styledRow([
                'Invoice', 'Customer Ref', 'Invoice Date', 'Due Date', 'Customer',
                'Amount Untaxed (HT)', 'Tax', 'Amount Total (TTC)',
                'Residual', 'Collected (HT)', '% Collected',
                'Payment State', 'Days Overdue',
            ], $headerStyle));

            // ── Data rows — numeric cells written as real numbers ───────
            $rows = $details['rows'] ?? [];
            $sumHt = 0.0; $sumTax = 0.0; $sumTotal = 0.0; $sumResid = 0.0; $sumColl = 0.0;
            foreach ($rows as $r) {
                $ht    = (float) $r['amount_untaxed'];
                $tax   = (float) $r['amount_tax'];
                $total = (float) $r['amount_total'];
                $resid = (float) $r['amount_residual'];
                $coll  = (float) $r['collected_ht'];
                $pct   = (float) $r['collection_pct'];
                $od    = (int)   $r['days_overdue'];

                $sumHt += $ht; $sumTax += $tax; $sumTotal += $total;
                $sumResid += $resid; $sumColl += $coll;

                $writer->addRow(new Row([
                    Cell::fromValue((string) $r['name'],          $cellStyle),
                    Cell::fromValue((string) $r['ref'],           $cellStyle),
                    Cell::fromValue((string) $r['invoice_date'],  $cellStyle),
                    Cell::fromValue((string) $r['due_date'],      $cellStyle),
                    Cell::fromValue((string) $r['partner'],       $cellStyle),
                    Cell::fromValue($ht,    $numCellStyle),
                    Cell::fromValue($tax,   $numCellStyle),
                    Cell::fromValue($total, $numCellStyle),
                    Cell::fromValue($resid, $numCellStyle),
                    Cell::fromValue($coll,  $numCellStyle),
                    Cell::fromValue($pct,   $pctCellStyle),
                    Cell::fromValue((string) $r['payment_state'], $cellStyle),
                    Cell::fromValue($od,    $od > 0 ? $overdueStyle : $cellStyle),
                ]));
            }

            // ── Totals row ───────────────────────────────────────────────
            if (count($rows) > 0) {
                $writer->addRow(new Row([
                    Cell::fromValue('Totals (' . count($rows) . ' invoices)', $sumStyle),
                    Cell::fromValue('', $sumStyle),
                    Cell::fromValue('', $sumStyle),
                    Cell::fromValue('', $sumStyle),
                    Cell::fromValue('', $sumStyle),
                    Cell::fromValue($sumHt,    $sumStyle),
                    Cell::fromValue($sumTax,   $sumStyle),
                    Cell::fromValue($sumTotal, $sumStyle),
                    Cell::fromValue($sumResid, $sumStyle),
                    Cell::fromValue($sumColl,  $sumStyle),
                    Cell::fromValue('', $sumStyle),
                    Cell::fromValue('', $sumStyle),
                    Cell::fromValue('', $sumStyle),
                ]));
            }

            $writer->addRow(Row::fromValues(['']));
            $writer->addRow($styledRow([
                'Methodology: One row per posted out_invoice with invoice_date in the period. '
                . 'HT = amount_untaxed_signed. Tax = amount_tax_signed. TTC = amount_total_signed. '
                . 'Residual = amount_residual_signed (open balance, TTC). '
                . 'Collected (HT) = HT × payment_progress, where payment_progress = (TTC − Residual) / TTC. '
                . 'Days Overdue = today − due_date when due_date is past and residual > 0; 0 otherwise. '
                . 'Headline figures match /billing Reconciliation panel exactly.'
            ], $noteStyle));

            $writer->close();
        }, 200, [
            'Content-Type'           => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition'    => 'attachment; filename="' . $filename . '"',
            'Cache-Control'          => 'no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
