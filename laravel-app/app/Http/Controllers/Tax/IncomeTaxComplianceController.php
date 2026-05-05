<?php

namespace App\Http\Controllers\Tax;

use App\Http\Controllers\Controller;
use App\Models\PartnerDonationCategory;
use App\Odoo\OdooFactory;
use App\Services\Tax\DonationsComplianceService;
use App\Services\Tax\IncomeTaxComplianceService;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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
    public function index(TenantContext $ctx)
    {
        $analysis  = null;
        $donations = null;
        $error     = null;
        try {
            $analysis = ea_cached('income_tax_compliance', function () {
                return (new IncomeTaxComplianceService(OdooFactory::fromConfig()))->analyze();
            });
            $tenant = $ctx->tenant();
            if ($tenant) {
                $donations = ea_cached('income_tax_donations', function () use ($tenant) {
                    return (new DonationsComplianceService(OdooFactory::fromConfig(), $tenant))->analyze();
                });
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return view('tax.income-tax-compliance', [
            'analysis'  => $analysis,
            'donations' => $donations,
            'error'     => $error,
            'active'    => 'income_tax_compliance',
        ]);
    }

    /**
     * Resolve a beneficiary's donation category and metadata. POST.
     * Best-effort write-back to res.partner.x_donation_category_cm.
     */
    public function setCategory(Request $request, TenantContext $ctx)
    {
        $tenant = $ctx->tenant();
        abort_unless($tenant, 404);

        $data = $request->validate([
            'odoo_partner_id'           => ['required', 'integer'],
            'partner_name'              => ['nullable', 'string', 'max:191'],
            'partner_niu'               => ['nullable', 'string', 'max:64'],
            'category'                  => ['nullable', 'integer', 'in:1,2,3'],
            'is_non_resident'           => ['nullable', 'boolean'],
            'supporting_doc_type'       => ['nullable', 'string', 'max:64'],
            'supporting_doc_reference'  => ['nullable', 'string', 'max:191'],
            'notes'                     => ['nullable', 'string'],
        ]);

        $row = PartnerDonationCategory::firstOrNew([
            'tenant_id'       => $tenant->id,
            'odoo_partner_id' => (int) $data['odoo_partner_id'],
        ]);
        $row->fill([
            'partner_name'             => $data['partner_name'] ?? $row->partner_name,
            'partner_niu'              => $data['partner_niu']  ?? $row->partner_niu,
            'category'                 => $data['category']     ?? null,
            'is_non_resident'          => (bool) ($data['is_non_resident'] ?? false),
            'supporting_doc_type'      => $data['supporting_doc_type']      ?? $row->supporting_doc_type,
            'supporting_doc_reference' => $data['supporting_doc_reference'] ?? $row->supporting_doc_reference,
            'notes'                    => $data['notes'] ?? $row->notes,
        ]);

        // Best-effort Odoo sync.
        try {
            $svc = new DonationsComplianceService(OdooFactory::fromConfig(), $tenant);
            $err = $svc->syncCategoryToOdoo((int) $data['odoo_partner_id'], $data['category'] ?? null);
            if ($err === null) {
                $row->odoo_synced_at = now();
                $row->odoo_sync_error = null;
            } else {
                $row->odoo_sync_error = $err;
            }
        } catch (\Throwable $e) {
            $row->odoo_sync_error = $e->getMessage();
        }
        $row->save();

        // Bust the donations cache so the page reflects the new resolution.
        ea_cache_clear();

        return back()->with('flash', [
            'type' => 'success',
            'msg'  => 'Beneficiary category saved.',
        ]);
    }

    /**
     * Upload supporting documentation for one beneficiary.
     */
    public function uploadDoc(Request $request, TenantContext $ctx)
    {
        $tenant = $ctx->tenant();
        abort_unless($tenant, 404);

        $data = $request->validate([
            'odoo_partner_id' => ['required', 'integer'],
            'document'        => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx'],
        ]);

        $row = PartnerDonationCategory::firstOrNew([
            'tenant_id'       => $tenant->id,
            'odoo_partner_id' => (int) $data['odoo_partner_id'],
        ]);

        // Replace any prior file.
        if ($row->supporting_doc_path && Storage::exists($row->supporting_doc_path)) {
            Storage::delete($row->supporting_doc_path);
        }
        $path = $request->file('document')->store(
            "tenants/{$tenant->id}/donation_docs",
            'local'
        );
        $row->supporting_doc_path = $path;
        $row->save();

        ea_cache_clear();

        return back()->with('flash', [
            'type' => 'success',
            'msg'  => 'Supporting document uploaded.',
        ]);
    }

    /**
     * Stream an XLSX of every individual donation line, with category
     * apportionment (deductible / reintegrated / exposure).
     */
    public function exportDonations(TenantContext $ctx): StreamedResponse
    {
        $tenant = $ctx->tenant();
        abort_unless($tenant, 404);

        $analysis = ea_cached('income_tax_donations', function () use ($tenant) {
            return (new DonationsComplianceService(OdooFactory::fromConfig(), $tenant))->analyze();
        });

        $don   = $analysis['donations'] ?? [];
        $bens  = $don['beneficiaries'] ?? [];
        $cur   = (string) ($analysis['currency'] ?? 'XAF');
        $asOf  = (string) ($analysis['as_of'] ?? date('Y-m-d'));
        $cap   = (float)  ($don['ceiling_xaf'] ?? 0);

        $filename = "income-tax-compliance_donations_{$asOf}.xlsx";

        return new StreamedResponse(function () use ($bens, $cur, $asOf, $don, $cap) {
            $writer = new Writer();
            $writer->openToFile('php://output');

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
            $cellStyle = (new Style())->withFontSize(10)->withBorder($cellBorder);
            $unresStyle = (new Style())->withFontSize(10)->withFontColor('B00020')->withFontBold(true)->withBorder($cellBorder);
            $noteStyle = (new Style())->withFontSize(9)->withFontColor('666666')->withShouldWrapText(true);

            $styledRow = static function (array $values, Style $style): Row {
                $cells = array_map(fn ($v) => Cell::fromValue($v, $style), $values);
                return new Row($cells);
            };

            // Title block.
            $writer->addRow($styledRow(['Income Tax Compliance — Donations & Libéralités'], $titleStyle));
            $writer->addRow($styledRow([
                'As of: ' . $asOf .
                '   ·   CA_net YTD: ' . number_format((float)($don['ca_net_ytd'] ?? 0), 0, '.', ',') . ' ' . $cur .
                '   ·   Ceiling (0.5 %): ' . number_format($cap, 0, '.', ',') . ' ' . $cur
            ], $metaStyle));
            $writer->addRow($styledRow([
                'Total: ' . number_format((float)($don['total_donations'] ?? 0), 0, '.', ',') . ' ' . $cur .
                '   ·   Deductible: ' . number_format((float)($don['total_deductible'] ?? 0), 0, '.', ',') . ' ' . $cur .
                '   ·   Reintegrated: ' . number_format((float)($don['total_reintegrated'] ?? 0), 0, '.', ',') . ' ' . $cur .
                '   ·   Exposure: ' . number_format((float)($don['exposure_total'] ?? 0), 0, '.', ',') . ' ' . $cur
            ], $metaStyle));
            $writer->addRow($styledRow(['References: Articles 7, 17, 26, 91, 225 CGI'], $metaStyle));
            $writer->addRow(Row::fromValues(['']));

            // Header.
            $writer->addRow($styledRow([
                'Date', 'Beneficiary', 'NIU', 'Doc type', 'Doc reference',
                'Category', 'Non-resident', 'Account', 'Journal', 'Narration',
                'Amount', 'Deductible', 'Reintegrated', 'Exposure',
            ], $headerStyle));

            foreach ($bens as $b) {
                $cat        = (int) ($b['category'] ?? 0);
                $catLabel   = $cat === 1 ? 'Cat 1' : ($cat === 2 ? 'Cat 2' : ($cat === 3 ? 'Cat 3' : 'Unresolved'));
                $nonRes     = (bool) ($b['is_non_resident'] ?? false);
                $beneTotal  = (float) ($b['total'] ?? 0);
                $deductible = (float) ($b['deductible'] ?? 0);
                $reintRatio = $beneTotal > 0 ? (((float)$b['reintegrated']) / $beneTotal) : 0.0;
                $expRatio   = $beneTotal > 0 ? (((float)$b['exposure_total']) / $beneTotal) : 0.0;
                $dedRatio   = $beneTotal > 0 ? ($deductible / $beneTotal) : 0.0;

                foreach (($b['lines'] ?? []) as $l) {
                    $amount = (float) ($l['amount'] ?? 0);
                    $writer->addRow($styledRow([
                        (string) ($l['date'] ?? ''),
                        (string) ($b['name'] ?? ''),
                        (string) ($b['niu']  ?? ''),
                        (string) ($b['supporting_doc_type'] ?? ''),
                        (string) ($b['supporting_doc_reference'] ?? ''),
                        $catLabel,
                        $nonRes ? 'Yes' : 'No',
                        (string) ($l['account']   ?? ''),
                        (string) ($l['journal']   ?? ''),
                        (string) ($l['narration'] ?? ''),
                        $amount,
                        round($amount * $dedRatio, 2),
                        round($amount * $reintRatio, 2),
                        round($amount * $expRatio, 2),
                    ], $cat === 0 ? $unresStyle : $cellStyle));
                }
            }

            $writer->addRow(Row::fromValues(['']));
            $writer->addRow($styledRow([
                'Methodology: posted move lines on accounts 6713, 6588, 638 in YTD, grouped by partner_id. '
                . 'Category resolved per Article 7 CGI (Cat 1 fully deductible; Cat 2 collectively capped at 0.5 % of CA_net = |70 ex 709| − |709|; Cat 3 fully reintegrated). '
                . 'Stacked exposure on reintegrated portion = 49.5 % (33 % CIT, Article 17; 16.5 % IRCM, Articles 26, 91). '
                . 'Additional 15 % TSR (Article 225 CGI) on Catégorie 3 amounts paid to non-resident beneficiaries. '
                . 'Unresolved beneficiaries are treated as Cat 3 conservatively until documentation is uploaded.'
            ], $noteStyle));

            $writer->close();
        }, 200, [
            'Content-Type'           => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition'    => 'attachment; filename="' . $filename . '"',
            'Cache-Control'          => 'no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
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
        $moves  = $hv['moves'] ?? [];           // NEW: per-journal payload (rework 2026-05)
        $cur    = (string) ($analysis['currency'] ?? 'XAF');
        $asOf   = (string) ($analysis['as_of'] ?? date('Y-m-d'));
        $thresh = (float)  ($hv['threshold_xaf'] ?? 100000);

        $filename = "income-tax-compliance_high-value-cash_{$asOf}.xlsx";

        return new StreamedResponse(function () use ($moves, $cur, $asOf, $thresh, $hv) {
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

            // ── Style for the JE-header row that separates moves ────────
            $jeHeaderStyle = (new Style())
                ->withFontBold(true)
                ->withFontSize(11)
                ->withBackgroundColor('E2E8F0')
                ->withFontColor('1E3D44')
                ->withBorder($cellBorder);

            // ── Title block ─────────────────────────────────────────────
            $writer->addRow($styledRow(['Income Tax Compliance — High-Value Cash Expenditures'], $titleStyle));
            $writer->addRow($styledRow([
                'As of: ' . $asOf .
                '   ·   Threshold: ' . number_format($thresh, 0, '.', ',') . ' ' . $cur . ' per JOURNAL (sum of 57-credits)' .
                '   ·   ' . (string) ($hv['threshold_reference'] ?? 'LF current')
            ], $metaStyle));
            $writer->addRow($styledRow([
                'Journals flagged: ' . (int) ($hv['count'] ?? 0) .
                '   ·   Total cash paid: ' . number_format((float) ($hv['total_amount'] ?? 0), 0, '.', ',') . ' ' . $cur .
                '   ·   Tax exposure (49.5 %): ' . number_format((float) ($hv['total_impact'] ?? 0), 0, '.', ',') . ' ' . $cur
            ], $metaStyle));
            $writer->addRow($styledRow(['References: Articles 7-A, 17, 26, 91 CGI'], $metaStyle));
            $writer->addRow(Row::fromValues([''])); // spacer

            // ── Header row ──────────────────────────────────────────────
            $writer->addRow($styledRow([
                'Side',
                'Date',
                'Account · Description',
                'Counterparty',
                'Journal',
                'Debit (' . $cur . ')',
                'Credit (' . $cur . ')',
                'Move ID',
                'Supporting doc',
            ], $headerStyle));

            // ── Body: one JE-header row per move + every line of that
            // move (full debit + credit). Lines from the same journal
            // appear contiguously; the JE header summarises cash_paid +
            // risk + supporting-doc status so a tax inspector can read
            // the whole entry without cross-referencing back to Odoo.
            foreach ($moves as $m) {
                $missing = (bool) ($m['missing_supporting'] ?? false);
                $cashPaid = (float) ($m['cash_paid']   ?? 0);
                $risk     = (float) ($m['risk_amount'] ?? 0);
                $moveName = (string) ($m['move_name'] ?? '—');
                $moveId   = (int)    ($m['move_id']   ?? 0);
                $jeHeader = sprintf(
                    'JE · %s · cash paid %s %s · risk 49.5%% = %s %s · doc %s',
                    $moveName,
                    number_format($cashPaid, 0, '.', ','), $cur,
                    number_format($risk,     0, '.', ','), $cur,
                    $missing ? 'MISSING' : 'on file'
                );
                $writer->addRow($styledRow([
                    'JE',
                    (string) ($m['date'] ?? ''),
                    $jeHeader,
                    (string) ($m['partner'] ?? '— unrecorded —'),
                    (string) ($m['journal'] ?? ''),
                    '',
                    '',
                    $moveId,
                    $missing ? 'MISSING' : 'On file',
                ], $jeHeaderStyle));

                foreach (($m['lines'] ?? []) as $l) {
                    $debit   = (float) ($l['debit']  ?? 0);
                    $credit  = (float) ($l['credit'] ?? 0);
                    $writer->addRow($styledRow([
                        (string) ($l['side'] ?? ''),
                        (string) ($m['date'] ?? ''),
                        ((string) ($l['account_label'] ?? '')) . (
                            !empty($l['description']) ? ' · ' . $l['description'] : ''
                        ),
                        (string) ($l['partner'] ?? ($m['partner'] ?? '')),
                        (string) ($m['journal'] ?? ''),
                        $debit  > 0 ? $debit  : '',
                        $credit > 0 ? $credit : '',
                        $moveId,
                        '',
                    ], $missing ? $missingStyle : $cellStyle));
                }
            }

            // ── Methodology footer ──────────────────────────────────────
            $writer->addRow(Row::fromValues([''])); // spacer
            $writer->addRow($styledRow([
                'Methodology (rework 2026-05): per-journal aggregation. Qualifying moves are posted account.move records that ' .
                '(a) have at least one line crediting account.code starting with "57" (Caisse, SYSCOHADA), AND ' .
                '(b) have at least one line debiting account.code starting with 6 (charges) OR 1, 2, 3, 4 (balance-sheet items). ' .
                'cash_paid = SUM of credit on all 57-lines of the move. The journal qualifies when cash_paid > LF threshold (100,000 XAF). ' .
                'Tax exposure stacked at 49.5% = 33% CIT (Article 17 CGI) + 16.5% IRCM (Articles 26, 91 CGI) on cash_paid under Article 7-A CGI. ' .
                'Stacking assumes the DGI requalifies the disallowed amount in full as a hidden distribution (revenus réputés distribués). ' .
                'Each qualifying journal is exported with EVERY debit and credit line so the entry is readable without going back to Odoo. ' .
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
