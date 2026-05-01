<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\BudgetParser;
use App\Services\BudgetService;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
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
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Budget upload — front-end-driven CRUD over per-tenant P&L budgets.
 *
 * Routes:
 *   GET  /budget-upload                      — view current state, upload form
 *   POST /budget-upload                      — accept .xlsx/.csv, parse, save
 *   GET  /budget-upload/template.xlsx        — download blank template
 *   POST /budget-upload/delete               — delete the budget for a year
 *
 * The parsed budget is stored line-level (Revenue / Direct op costs /
 * OpEx / D&A / Net financial / Income tax) for 12 months. Read by
 * PLForecastService when assembling the Budget column on the
 * MTD / YTD / Full-Year P&L views.
 */
class BudgetUploadController extends Controller
{
    public function index(Request $request, BudgetService $budgets)
    {
        $tenant = $this->resolveTenant();
        $today  = new \DateTimeImmutable();
        $year   = (int) $request->query('year', $today->format('Y'));
        $year   = max(2018, min(((int) $today->format('Y')) + 2, $year));

        $summary = $budgets->summary($tenant->id, $year);

        return view('budget-upload', [
            'active'  => 'pl_forecast', // sit under P&L Forecast nav-wise
            'tenant'  => $tenant,
            'year'    => $year,
            'summary' => $summary,
            'lineLabels' => BudgetService::LINE_LABELS,
        ]);
    }

    public function upload(Request $request, BudgetParser $parser, BudgetService $budgets)
    {
        $tenant = $this->resolveTenant();
        $validator = Validator::make($request->all(), [
            'year' => ['required', 'integer', 'min:2018', 'max:' . (((int) date('Y')) + 2)],
            'file' => ['required', 'file', 'max:5120', 'mimes:xlsx,csv'],
        ]);
        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->with('flash', [
                'type' => 'error',
                'msg'  => 'Could not accept the file. Check the year and that it\'s a .xlsx or .csv up to 5 MB.',
            ]);
        }

        $year = (int) $request->input('year');
        $file = $request->file('file');
        try {
            $result = $parser->parse($file->getRealPath(), $file->getClientOriginalExtension());
        } catch (\Throwable $e) {
            return redirect()->back()->with('flash', [
                'type' => 'error',
                'msg'  => 'Could not parse the file: ' . $e->getMessage(),
            ]);
        }

        // Clear per-tenant P&L caches so the new budget shows up
        // immediately (vs waiting for the session-scoped cache to lapse).
        ea_cache_clear();

        $userId = optional($request->user())->id;
        $budgets->save($tenant, $year, $result['data'], $file->getClientOriginalName(), $userId);

        $msg = "Budget for {$year} saved.";
        if (!empty($result['warnings'])) {
            $msg .= ' Notes: ' . implode(' · ', $result['warnings']);
        }
        return redirect()->to(tenant_url('/budget-upload?year=' . $year))->with('flash', [
            'type' => 'success',
            'msg'  => $msg,
        ]);
    }

    public function delete(Request $request, BudgetService $budgets)
    {
        $tenant = $this->resolveTenant();
        $year   = (int) $request->input('year');
        $budgets->delete($tenant->id, $year);
        ea_cache_clear();
        return redirect()->to(tenant_url('/budget-upload?year=' . $year))->with('flash', [
            'type' => 'success',
            'msg'  => "Budget for {$year} cleared.",
        ]);
    }

    /**
     * Streamed .xlsx template the user downloads, fills in, and uploads
     * back. Pre-filled with the canonical line labels so they can't be
     * mistyped.
     */
    public function template(Request $request)
    {
        $year = (int) $request->query('year', date('Y'));
        $filename = "pl_budget_template_{$year}.xlsx";

        return new StreamedResponse(function () use ($year) {
            $writer = new XlsxWriter();
            $writer->openToFile('php://output');

            $headerStyle = (new Style())
                ->withFontBold(true)->withFontSize(11)
                ->withFontColor(Color::WHITE)
                ->withBackgroundColor('1E3D44')
                ->withCellAlignment(CellAlignment::CENTER)
                ->withBorder(new Border(new BorderPart(BorderName::BOTTOM, '000000', BorderWidth::THIN, BorderStyle::SOLID)));
            $labelStyle = (new Style())->withFontBold(true)->withFontSize(10);
            $titleStyle = (new Style())->withFontBold(true)->withFontSize(13);
            $noteStyle  = (new Style())->withFontSize(9)->withFontColor('666666')->withShouldWrapText(true);

            $writer->addRow(new Row([Cell::fromValue("P&L Budget Template — {$year}", $titleStyle)]));
            $writer->addRow(new Row([Cell::fromValue('Fill in the Jan..Dec cells for each line. Values can be in millions XAF (e.g. 45) or in absolute XAF (e.g. 45000000) — the parser detects both. Cost lines may use negatives or parentheses.', $noteStyle)]));
            $writer->addRow(Row::fromValues(['']));

            $months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            $headerCells = [Cell::fromValue('P&L Line', $headerStyle)];
            foreach ($months as $m) $headerCells[] = Cell::fromValue($m, $headerStyle);
            $writer->addRow(new Row($headerCells));

            foreach (\App\Services\BudgetService::LINE_LABELS as $label) {
                $cells = [Cell::fromValue($label, $labelStyle)];
                for ($i = 0; $i < 12; $i++) $cells[] = Cell::fromValue(0);
                $writer->addRow(new Row($cells));
            }

            $writer->addRow(Row::fromValues(['']));
            $writer->addRow(new Row([Cell::fromValue(
                'Sign convention: Revenue positive. Direct operating costs / OpEx / D&A / Income tax / Net financial result negative (or in parentheses). Save and upload to /budget-upload.',
                $noteStyle
            )]));

            $writer->close();
        }, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control'       => 'no-store, max-age=0',
        ]);
    }

    private function resolveTenant(): Tenant
    {
        $ctx = app(TenantContext::class);
        if (!$ctx->has()) abort(404);
        return $ctx->tenant();
    }
}
