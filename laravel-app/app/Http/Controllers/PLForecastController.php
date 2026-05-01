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

        // Load saved commentary for this (year, view, month) so the
        // textarea populates with whatever the user last saved.
        $commentary = null;
        if ($tenantId && in_array($view, ['mtd', 'ytd'], true)) {
            $monthKey = $view === 'mtd' ? $month : 0;
            $row = PLCommentary::with('saver')
                ->where('tenant_id', $tenantId)
                ->where('year', $year)
                ->where('month', $monthKey)
                ->where('view', $view)
                ->first();
            if ($row) {
                $commentary = [
                    'text'         => (string) ($row->commentary ?? ''),
                    'saved_by'     => $row->saver?->name ?? '—',
                    'saved_at_iso' => $row->updated_at?->toIso8601String(),
                ];
            }
        }

        return view('pl-forecast', [
            'active'     => 'pl_forecast',
            'year'       => $year,
            'month'      => $month,
            'view'       => $view,
            'data'       => $data,
            'error'      => $error,
            'commentary' => $commentary,
        ]);
    }

    /**
     * POST /pl-forecast/commentary — save the commentary textarea
     * against the current (year, view, month) tuple. Upsert by the
     * unique (tenant_id, year, month, view).
     */
    public function saveCommentary(Request $request)
    {
        $ctx = app(TenantContext::class);
        if (!$ctx->has()) abort(404);
        $tenantId = $ctx->tenant()->id;

        $data = $request->validate([
            'year'       => ['required', 'integer'],
            'month'      => ['required', 'integer', 'min:0', 'max:12'],
            'view'       => ['required', 'in:mtd,ytd'],
            'commentary' => ['nullable', 'string', 'max:8000'],
        ]);

        PLCommentary::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'year'      => (int) $data['year'],
                'month'     => (int) $data['month'],
                'view'      => $data['view'],
            ],
            [
                'commentary' => $data['commentary'] ?? '',
                'saved_by'   => optional($request->user())->id,
            ]
        );

        $back = tenant_url('/pl-forecast?view=' . $data['view'] . '&year=' . $data['year']
              . ($data['view'] === 'mtd' ? '&month=' . $data['month'] : ''));

        return redirect()->to($back)->with('flash', [
            'type' => 'success',
            'msg'  => 'Commentary saved.',
        ]);
    }
}
