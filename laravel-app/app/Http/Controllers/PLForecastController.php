<?php

namespace App\Http\Controllers;

use App\Models\PLCommentary;
use App\Odoo\OdooFactory;
use App\Services\BudgetService;
use App\Services\PLForecastService;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

/**
 * P&L Forecast — full-year monthly P&L with prior months as actuals
 * and remaining months as forecast (SMLY with trailing-3-mean fallback).
 *
 * Cached per fiscal year. Year selectable via ?year=2026.
 */
class PLForecastController extends Controller
{
    public function index(Request $request)
    {
        $today = new \DateTimeImmutable();
        $year  = (int) $request->query('year', $today->format('Y'));
        // Sane bounds — no point requesting decades-old data or far-future
        $thisYear = (int) $today->format('Y');
        $year = max(2018, min($thisYear + 2, $year));

        $view = strtolower((string) $request->query('view', 'full_year'));
        if (!in_array($view, ['full_year', 'mtd', 'ytd'], true)) $view = 'full_year';

        $month = (int) $request->query('month', $today->format('n'));
        $month = max(1, min(12, $month));

        $budgetGrowth = (float) (config('macro.pl_budget_growth_factor') ?? 1.0);

        // Pull the uploaded budget for this tenant + year, if any.
        $tenantId = optional(app(TenantContext::class)->has() ? app(TenantContext::class)->tenant() : null)->id;
        $uploadedBudget = $tenantId
            ? app(BudgetService::class)->load($tenantId, $year)
            : null;

        $data  = null;
        $error = null;

        try {
            $client = OdooFactory::fromConfig();
            $cacheKey = "pl_forecast_{$view}_{$year}_{$month}";
            $data = ea_cached($cacheKey, function () use ($client, $year, $month, $view, $budgetGrowth, $uploadedBudget) {
                $svc = new PLForecastService($client);
                return match ($view) {
                    'mtd' => $svc->analyzeMtd($year, $month, $budgetGrowth, $uploadedBudget),
                    'ytd' => $svc->analyzeYtd($year, $budgetGrowth, $uploadedBudget),
                    default => $svc->analyze($year),
                };
            });
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        // Load saved commentaries for this (year, view, month). Each
        // P&L leaf line gets its own row in the table now, so the
        // payload is keyed line_key → text. Most-recent-saved-at is
        // also returned so the view can show a single "last saved"
        // badge above the table.
        $commentaries = [];
        $latestSaved  = null;
        if ($tenantId && in_array($view, ['mtd', 'ytd'], true)) {
            $monthKey = $view === 'mtd' ? $month : 0;
            $rows = PLCommentary::with('saver')
                ->where('tenant_id', $tenantId)
                ->where('year', $year)
                ->where('month', $monthKey)
                ->where('view', $view)
                ->get();
            foreach ($rows as $row) {
                $commentaries[$row->line_key ?: 'overall'] = (string) ($row->commentary ?? '');
                if (!$latestSaved || ($row->updated_at && $row->updated_at->gt(\Carbon\Carbon::parse($latestSaved['at'])))) {
                    $latestSaved = [
                        'by' => $row->saver?->name ?? '—',
                        'at' => $row->updated_at?->toIso8601String(),
                    ];
                }
            }
        }

        return view('pl-forecast', [
            'active'        => 'pl_forecast',
            'year'          => $year,
            'month'         => $month,
            'view'          => $view,
            'data'          => $data,
            'error'         => $error,
            'commentaries'  => $commentaries,
            'commentary_meta' => $latestSaved,
        ]);
    }

    /**
     * POST /pl-forecast/commentary — accept a map of line_key → text and
     * upsert one row per line. Empty strings are saved (so the user can
     * clear a note).
     *
     * Allowed line keys are restricted to the leaf P&L lines that the
     * MTD/YTD comparison views render with a Commentary cell.
     */
    public function saveCommentary(Request $request)
    {
        $ctx = app(TenantContext::class);
        if (!$ctx->has()) abort(404);
        $tenantId = $ctx->tenant()->id;

        $data = $request->validate([
            'year'           => ['required', 'integer'],
            'month'          => ['required', 'integer', 'min:0', 'max:12'],
            'view'           => ['required', 'in:mtd,ytd'],
            'commentaries'   => ['nullable', 'array'],
            'commentaries.*' => ['nullable', 'string', 'max:8000'],
        ]);

        $allowedLines = ['revenue', 'direct_costs', 'opex', 'depreciation', 'net_financial', 'income_tax'];
        $userId = optional($request->user())->id;

        foreach ((array) ($data['commentaries'] ?? []) as $lineKey => $text) {
            if (!in_array($lineKey, $allowedLines, true)) continue;
            PLCommentary::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'year'      => (int) $data['year'],
                    'month'     => (int) $data['month'],
                    'view'      => $data['view'],
                    'line_key'  => $lineKey,
                ],
                [
                    'commentary' => (string) ($text ?? ''),
                    'saved_by'   => $userId,
                ]
            );
        }

        $back = tenant_url('/pl-forecast?view=' . $data['view'] . '&year=' . $data['year']
              . ($data['view'] === 'mtd' ? '&month=' . $data['month'] : ''));

        return redirect()->to($back)->with('flash', [
            'type' => 'success',
            'msg'  => 'Commentaries saved.',
        ]);
    }
}
