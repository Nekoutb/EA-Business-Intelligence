<?php

namespace App\Http\Controllers\Tax;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\LineDonationCategory;
use App\Models\PartnerDonationCategory;
use App\Odoo\OdooFactory;
use App\Services\Tax\DonationsComplianceService;
use App\Services\Tax\IncomeTaxComplianceService;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
     * Set or clear a line-level donation category override. POSTed
     * from the line table dropdown via fetch().
     *
     * Returns JSON:
     *   { ok: true, category: 1|2|3|null, source: 'line_override'|... }
     *
     * Audit-log: action='donation.line_category.set', payload includes
     * old + new + line meta. Cache cleared so the next render reflects.
     */
    public function setLineCategory(Request $request, TenantContext $ctx, int $moveLineId)
    {
        $tenant = $ctx->tenant();
        abort_unless($tenant, 404);

        $data = $request->validate([
            'category'      => ['nullable', 'integer', 'in:1,2,3'],
            'note'          => ['nullable', 'string', 'max:500'],
            // Denormalised line metadata — we trust the client here only
            // for the convenience of populating the audit-trail columns,
            // since round-tripping Odoo on every dropdown change would
            // be slow. The values on the line are read-only from the
            // auditor's perspective (they come from the GL).
            'odoo_move_id'    => ['required', 'integer', 'min:1'],
            'odoo_partner_id' => ['nullable', 'integer'],
            'account_code'    => ['required', 'string', 'max:16'],
            'line_date'       => ['required', 'date'],
            'amount'          => ['required', 'numeric'],
        ]);

        $oldCat = LineDonationCategory::where('tenant_id', $tenant->id)
            ->where('odoo_move_line_id', $moveLineId)
            ->value('category');

        $svc = new DonationsComplianceService(OdooFactory::fromConfig(), $tenant);
        $result = $svc->setLineCategory(
            moveLineId:       $moveLineId,
            moveId:           (int) $data['odoo_move_id'],
            partnerId:        isset($data['odoo_partner_id']) ? (int) $data['odoo_partner_id'] : null,
            accountCode:      (string) $data['account_code'],
            lineDate:         new \DateTimeImmutable($data['line_date']),
            amount:           (float) $data['amount'],
            category:         isset($data['category']) ? (int) $data['category'] : null,
            note:             $data['note'] ?? null,
            assignedByUserId: (int) $request->user()->id,
        );

        AuditLog::record('donation.line_category.set', [
            'tenant_id' => $tenant->id,
            'target'    => 'move_line:' . $moveLineId,
            'payload'   => [
                'odoo_move_line_id' => $moveLineId,
                'odoo_move_id'      => (int) $data['odoo_move_id'],
                'odoo_partner_id'   => $data['odoo_partner_id'] ?? null,
                'account_code'      => $data['account_code'],
                'line_date'         => $data['line_date'],
                'amount'            => $data['amount'],
                'old_category'      => $oldCat !== null ? (int) $oldCat : null,
                'new_category'      => isset($data['category']) ? (int) $data['category'] : null,
                'note'              => $data['note'] ?? null,
            ],
        ]);

        ea_cache_clear();

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'ok'         => true,
                'line_id'    => $moveLineId,
                'category'   => $result?->category,
                'cleared'    => $result === null,
            ]);
        }

        return back()->with('flash', [
            'type' => 'success',
            'msg'  => $result === null ? 'Line category cleared.' : 'Line category saved.',
        ]);
    }

    /**
     * Bulk-set a line category for many lines at once. Used by the
     * "Confirm all as Cat 3" button (default) and the bulk-action
     * footer of the line table when ≥1 row is checked.
     *
     * Body:
     *   {
     *     category: 1|2|3,            // null not allowed in bulk (use single endpoint to clear)
     *     note?: string,              // optional, applied to all
     *     lines: [
     *       { odoo_move_line_id, odoo_move_id, odoo_partner_id?, account_code, line_date, amount },
     *       ...
     *     ]
     *   }
     *
     * Single transaction → single batch audit-log entry.
     */
    public function setLineCategoriesBulk(Request $request, TenantContext $ctx)
    {
        $tenant = $ctx->tenant();
        abort_unless($tenant, 404);

        $data = $request->validate([
            'category'                       => ['required', 'integer', 'in:1,2,3'],
            'note'                           => ['nullable', 'string', 'max:500'],
            'lines'                          => ['required', 'array', 'min:1', 'max:5000'],
            'lines.*.odoo_move_line_id'      => ['required', 'integer', 'min:1'],
            'lines.*.odoo_move_id'           => ['required', 'integer', 'min:1'],
            'lines.*.odoo_partner_id'        => ['nullable', 'integer'],
            'lines.*.account_code'           => ['required', 'string', 'max:16'],
            'lines.*.line_date'              => ['required', 'date'],
            'lines.*.amount'                 => ['required', 'numeric'],
        ]);

        $cat       = (int) $data['category'];
        $note      = $data['note'] ?? null;
        $userId    = (int) $request->user()->id;
        $now       = now();
        $lineIds   = array_column($data['lines'], 'odoo_move_line_id');

        // Pre-load existing categories for diff in audit log.
        $existing = LineDonationCategory::where('tenant_id', $tenant->id)
            ->whereIn('odoo_move_line_id', $lineIds)
            ->pluck('category', 'odoo_move_line_id');

        $rows = [];
        foreach ($data['lines'] as $l) {
            $rows[] = [
                'tenant_id'         => $tenant->id,
                'odoo_move_line_id' => (int) $l['odoo_move_line_id'],
                'odoo_move_id'      => (int) $l['odoo_move_id'],
                'odoo_partner_id'   => isset($l['odoo_partner_id']) ? (int) $l['odoo_partner_id'] : null,
                'account_code'      => (string) $l['account_code'],
                'line_date'         => $l['line_date'],
                'amount'            => (float) $l['amount'],
                'category'          => $cat,
                'note'              => $note,
                'assigned_by'       => $userId,
                'created_at'        => $now,
                'updated_at'        => $now,
            ];
        }

        DB::transaction(function () use ($rows) {
            // Upsert by (tenant_id, odoo_move_line_id) — composite unique.
            // Using upsert here keeps it to a single statement.
            LineDonationCategory::upsert(
                $rows,
                ['tenant_id', 'odoo_move_line_id'],
                ['odoo_move_id', 'odoo_partner_id', 'account_code', 'line_date',
                 'amount', 'category', 'note', 'assigned_by', 'updated_at']
            );
        });

        // Diff for audit log: count how many were new vs changed vs unchanged.
        $newCount = $changedCount = $sameCount = 0;
        foreach ($lineIds as $lid) {
            $old = $existing->get($lid);
            if ($old === null)            $newCount++;
            elseif ((int) $old === $cat)  $sameCount++;
            else                          $changedCount++;
        }

        AuditLog::record('donation.line_category.bulk_set', [
            'tenant_id' => $tenant->id,
            'target'    => 'move_lines:' . count($lineIds),
            'payload'   => [
                'category'       => $cat,
                'note'           => $note,
                'line_count'     => count($lineIds),
                'new_overrides'  => $newCount,
                'changed'        => $changedCount,
                'unchanged'      => $sameCount,
                'sample_line_ids'=> array_slice($lineIds, 0, 25),  // bound payload size
            ],
        ]);

        ea_cache_clear();

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'ok'             => true,
                'category'       => $cat,
                'line_count'     => count($lineIds),
                'new_overrides'  => $newCount,
                'changed'        => $changedCount,
                'unchanged'      => $sameCount,
            ]);
        }

        return back()->with('flash', [
            'type' => 'success',
            'msg'  => sprintf('%d lines categorised as Cat %d.', count($lineIds), $cat),
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
        $lines = $don['lines'] ?? [];
        $cur   = (string) ($analysis['currency'] ?? 'XAF');
        $asOf  = (string) ($analysis['as_of'] ?? date('Y-m-d'));
        $cap   = (float)  ($don['ceiling_xaf'] ?? 0);

        // NIU lookup so the line-level export carries partner NIU without
        // round-tripping Odoo (NIU was already resolved during analyze()).
        $niuByPartner = [];
        foreach ($bens as $b) {
            $niuByPartner[(int) $b['partner_id']] = (string) ($b['niu'] ?? '');
        }

        $filename = "income-tax-compliance_donations_{$asOf}.xlsx";

        return new StreamedResponse(function () use ($bens, $lines, $niuByPartner, $cur, $asOf, $don, $cap) {
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

            // Header — line-level layout including effective category +
            // resolution source so the auditor can immediately see which
            // lines were explicitly categorised vs falling through the
            // Cat 3 fallback.
            $writer->addRow($styledRow([
                'Date', 'Move ID', 'Line ID', 'Account', 'Partner', 'NIU',
                'Non-resident', 'Narration',
                'Effective category', 'Source', 'Override?',
                'Amount', 'Deductible', 'Reintegrated', 'Exposure (CIT+IRCM)', 'Exposure (TSR)', 'Exposure total',
            ], $headerStyle));

            foreach ($lines as $l) {
                $cat        = (int) ($l['effective_category'] ?? 3);
                $src        = (string) ($l['category_source'] ?? 'cat3_fallback');
                $catLabel   = match ($cat) { 1 => 'Cat 1', 2 => 'Cat 2', 3 => 'Cat 3', default => 'Cat 3' };
                $sourceLabel = match ($src) {
                    'line_override'   => 'Line override',
                    'partner_default' => 'Partner default',
                    'cat3_fallback'   => 'Cat 3 fallback (no override + no partner default)',
                    default           => $src,
                };
                $isOverride = $src === 'line_override';
                $rowStyle = $src === 'cat3_fallback' ? $unresStyle : $cellStyle;
                $pid    = $l['partner_id'] ?? null;
                $niu    = $pid !== null ? ($niuByPartner[(int) $pid] ?? '') : '';

                $writer->addRow($styledRow([
                    (string) ($l['date'] ?? ''),
                    (int)    ($l['move_id'] ?? 0),
                    (int)    ($l['id'] ?? 0),
                    (string) ($l['account'] ?? ''),
                    (string) ($l['partner_name'] ?? ($pid !== null ? '' : '— No partner —')),
                    $niu,
                    ($l['is_non_resident'] ?? false) ? 'Yes' : 'No',
                    (string) ($l['narration'] ?? ''),
                    $catLabel,
                    $sourceLabel,
                    $isOverride ? 'Yes' : 'No',
                    (float) ($l['amount'] ?? 0),
                    (float) ($l['deductible'] ?? 0),
                    (float) ($l['reintegrated'] ?? 0),
                    (float) ($l['exposure_stack'] ?? 0),
                    (float) ($l['exposure_tsr'] ?? 0),
                    (float) ($l['exposure_total'] ?? 0),
                ], $rowStyle));
            }

            $writer->addRow(Row::fromValues(['']));
            $writer->addRow($styledRow([
                'Methodology: posted move lines on accounts 6582XX (Dons) and 6583XX (Mécenat) in YTD. '
                . 'Per-line category resolved by precedence: line override → partner default → Cat 3 fallback (conservative; non-deductible until proven otherwise). '
                . 'Categories per Article 7 CGI: Cat 1 fully deductible; Cat 2 + Cat 3 collectively tested against the 0.5 % × CA_net ceiling, with the excess apportioned pro-rata; CA_net = |Σ 70 ex 709| − |Σ 709| YTD. '
                . 'Stacked exposure on the reintegrated portion = 49.5 % (33 % CIT, Article 17; 16.5 % IRCM on revenus réputés distribués, Articles 26, 91). '
                . 'Additional 15 % TSR (Article 225 CGI) on Cat 3 reintegrated amounts paid to non-resident beneficiaries. '
                . 'Lines flagged "Cat 3 fallback" did not have either a line-level override or a partner default at export time — treated as Cat 3 by the engine. '
                . 'Account scope corrected (May 2026) — was previously reading 6713 / 6588 / 638 (currency-loss + misc-charge buckets); re-export anything dated before this change.'
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
