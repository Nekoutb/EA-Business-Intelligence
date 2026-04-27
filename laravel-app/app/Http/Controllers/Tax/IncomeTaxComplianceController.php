<?php

namespace App\Http\Controllers\Tax;

use App\Http\Controllers\Controller;
use App\Odoo\OdooFactory;
use App\Services\Tax\IncomeTaxComplianceService;
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

class IncomeTaxComplianceController extends Controller
{
    public function index()
    {
        $analysis = null;
        $error    = null;
        try {
            $analysis = ea_cached('income_tax_compliance', function () {
                return (new IncomeTaxComplianceService(OdooFactory::fromConfig()))->analyze();
            });
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return view('tax.income-tax-compliance', [
            'analysis' => $analysis,
            'error'    => $error,
            'active'   => 'income_tax_compliance',
        ]);
    }

    /**
     * Stream an XLSX of every qualifying transaction, including the
     * missing-supporting-doc flag. Uses openspout v5 (with*-style API).
     */
    public function export(): StreamedResponse
    {
        $analysis = ea_cached('income_tax_compliance', function () {
            return (new IncomeTaxComplianceService(OdooFactory::fromConfig()))->analyze();
        });

        $hv     = $analysis['high_value_cash'] ?? [];
        $rows   = $hv['transactions'] ?? [];
        $cur    = (string) ($analysis['currency'] ?? 'XAF');
        $asOf   = (string) ($analysis['as_of'] ?? date('Y-m-d'));
        $thresh = (float)  ($hv['threshold_xaf'] ?? 100000);

        $filename = "income-tax-compliance_high-value-cash_{$asOf}.xlsx";

        return new StreamedResponse(function () use ($rows, $cur, $asOf, $thresh, $hv) {
            $writer = new Writer();
            $writer->openToFile('php://output');

            // ── Style definitions (openspout v5 immutable API) ───────────
            $headerBorder = new Border(
                new BorderPart(BorderName::BOTTOM, '000000', BorderWidth::THIN, BorderStyle::SOLID)
            );
            $headerStyle = (new Style())
                ->withFontBold(true)
                ->withFontSize(11)
                ->withFontColor(Color::WHITE)
                ->withBackgroundColor('1E3D44')
                ->withCellAlignment(CellAlignment::LEFT)
                ->withBorder($headerBorder);

            $titleStyle = (new Style())->withFontBold(true)->withFontSize(13);
            $metaStyle  = (new Style())->withFontSize(10)->withFontColor('888888');

            $cellBorder = new Border(
                new BorderPart(BorderName::BOTTOM, 'E5E5E5', BorderWidth::THIN, BorderStyle::SOLID)
            );
            $cellStyle = (new Style())->withFontSize(10)->withBorder($cellBorder);
            $missingStyle = (new Style())
                ->withFontSize(10)
                ->withFontColor('B00020')
                ->withFontBold(true)
                ->withBorder($cellBorder);

            $noteStyle = (new Style())
                ->withFontSize(9)
                ->withFontColor('666666')
                ->withShouldWrapText(true);

            // ── Helper: build a styled row in the v5 way ─────────────────
            $styledRow = static function (array $values, Style $style): Row {
                $cells = array_map(fn ($v) => Cell::fromValue($v, $style), $values);
                return new Row($cells);
            };

            // ── Title block ─────────────────────────────────────────────
            $writer->addRow($styledRow(['Income Tax Compliance — High-Value Cash Expenditures'], $titleStyle));
            $writer->addRow($styledRow([
                'As of: ' . $asOf .
                '   ·   Threshold: ' . number_format($thresh, 0, '.', ',') . ' ' . $cur . ' per line' .
                '   ·   ' . (string) ($hv['threshold_reference'] ?? 'LF current')
            ], $metaStyle));
            $writer->addRow($styledRow([
                'Transactions: ' . (int) ($hv['count'] ?? 0) .
                '   ·   Total: ' . number_format((float) ($hv['total_amount'] ?? 0), 0, '.', ',') . ' ' . $cur .
                '   ·   Tax exposure (49.5 %): ' . number_format((float) ($hv['total_impact'] ?? 0), 0, '.', ',') . ' ' . $cur
            ], $metaStyle));
            $writer->addRow($styledRow(['References: Articles 7-A, 17, 26, 91 CGI'], $metaStyle));
            $writer->addRow(Row::fromValues([''])); // spacer

            // ── Header row ──────────────────────────────────────────────
            $writer->addRow($styledRow([
                'Date',
                'Counterparty',
                'Narration',
                'Journal',
                'Account',
                'Amount (' . $cur . ')',
                'Supporting doc',
            ], $headerStyle));

            // ── Body rows ───────────────────────────────────────────────
            foreach ($rows as $r) {
                $missing = (bool) ($r['missing_supporting'] ?? false);
                $writer->addRow($styledRow([
                    (string) ($r['date'] ?? ''),
                    (string) ($r['partner'] ?? '— unrecorded —'),
                    (string) ($r['narration'] ?? ''),
                    (string) ($r['journal'] ?? ''),
                    (string) ($r['account'] ?? ''),
                    (float)  ($r['amount']  ?? 0),
                    $missing ? 'MISSING' : 'On file',
                ], $missing ? $missingStyle : $cellStyle));
            }

            // ── Methodology footer ──────────────────────────────────────
            $writer->addRow(Row::fromValues([''])); // spacer
            $writer->addRow($styledRow([
                'Methodology: posted move lines whose account_id.code starts with "57" (Caisse, SYSCOHADA), with line credit greater than the LF threshold, in YTD. ' .
                'Tax exposure stacked at 49.5% = 33% CIT (Article 17 CGI) + 16.5% IRCM (Articles 26, 91 CGI) on the same disallowed amount under Article 7-A CGI. ' .
                'Stacking assumes the DGI requalifies the disallowed amount in full as a hidden distribution (revenus réputés distribués). ' .
                '"MISSING" supporting doc indicates the originating account.move has zero ir.attachment records on file at export time.'
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
