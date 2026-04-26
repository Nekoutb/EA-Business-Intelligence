<?php

namespace App\Http\Controllers;

use App\Odoo\OdooFactory;
use App\Services\GLQualityService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GL Quality — sub-ledger vs trial balance reconciliations + GL
 * description-quality audit. YTD-only by design.
 */
class GLQualityController extends Controller
{
    public function index(Request $request)
    {
        $today = new \DateTimeImmutable();

        $data = ea_cached('gl_quality_ytd', function () use ($today) {
            $svc = new GLQualityService(OdooFactory::fromConfig());
            return $svc->analyze($today);
        });

        return view('general-ledger-quality', [
            'data'   => $data,
            'active' => 'gl_quality',
            'aiPage' => 'gl_quality',
        ]);
    }

    /**
     * Streaming CSV download of every GL line flagged by any of the 17
     * description-quality checks. Reads from the cache populated by
     * index() so a download right after viewing the page is free.
     */
    public function descriptionReport(Request $request): StreamedResponse
    {
        return $this->streamReport(
            $request,
            'description_quality',
            'gl-description-quality'
        );
    }

    /**
     * Streaming CSV download of every GL line flagged by any of the 8
     * manual-entry review checks.
     */
    public function manualEntryReport(Request $request): StreamedResponse
    {
        return $this->streamReport(
            $request,
            'manual_entry_review',
            'gl-manual-entry-review'
        );
    }

    /**
     * Shared CSV streamer — both audit reports use the same row shape
     * (Date | Move | Account | Partner | Move type | Amount | Ref |
     *  Narration | Failed checks).
     */
    private function streamReport(Request $request, string $reportKey, string $filenameStem): StreamedResponse
    {
        $today = new \DateTimeImmutable();

        $data = ea_cached('gl_quality_ytd', function () use ($today) {
            $svc = new GLQualityService(OdooFactory::fromConfig());
            return $svc->analyze($today);
        });

        $section  = $data[$reportKey] ?? [];
        $rows     = $section['report'] ?? [];
        $currency = $data['currency']  ?? 'XAF';
        $asOf     = $data['as_of']     ?? date('Y-m-d');

        $filename = $filenameStem . '_' . $asOf . '.csv';

        return response()->streamDownload(function () use ($rows, $currency) {
            $out = fopen('php://output', 'w');
            // UTF-8 BOM so Excel opens accents correctly
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'Date',
                'Move',
                'Account',
                'Partner',
                'Move type',
                'Amount (' . $currency . ')',
                'Reference',
                'Narration',
                'Failed checks',
            ]);

            foreach ($rows as $row) {
                $line = $row['line'] ?? [];
                fputcsv($out, [
                    $line['date']         ?? '',
                    $line['move_name']    ?? '',
                    $line['account_code'] ?? '',
                    $line['partner_name'] ?? '',
                    $line['move_type']    ?? '',
                    number_format((float)($line['amount'] ?? 0), 2, '.', ''),
                    $line['ref']          ?? '',
                    $line['name']         ?? '',
                    implode(' | ', $row['failed_checks'] ?? []),
                ]);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
