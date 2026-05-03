<?php

namespace App\Http\Controllers;

use App\Odoo\OdooFactory;
use App\Services\ReceivablesService;
use App\Services\RevenueService;
use Illuminate\Http\Request;

class ReceivablesController extends Controller
{
    public function index(Request $request)
    {
        // Receivables is YTD-only by product decision (snapshot data —
        // MTD/Month/YoY are not meaningful for AR). The master sidebar
        // filter is locked to YTD on this page (see view's
        // @section('mfilter-frozen', 'mtd,month,yoy')); the year is
        // honoured so users can review prior-year snapshots.
        $today = new \DateTimeImmutable();
        $year  = (int) $request->query('year', (int) $today->format('Y'));
        // Anchor "as_of" at year-end for past years, otherwise today.
        $asOf  = $year < (int) $today->format('Y')
            ? new \DateTimeImmutable(sprintf('%04d-12-31', $year))
            : $today;

        $period = ['type' => 'ytd', 'year' => $year, 'month' => (int) $today->format('m')];
        $periodLabel = 'YTD ' . $year;

        $cacheKey = 'ar_' . md5(json_encode(['y' => $year]));
        $data = null; $error = null;
        try {
            $data = ea_cached($cacheKey, function () use ($asOf, $year) {
                $c = OdooFactory::fromConfig();
                $s = (string) config_env('REVENUE_SOURCE', 'invoice');
                $r = (new RevenueService($c, $s))->analyze(null, ['type' => 'ytd', 'year' => $year]);
                return (new ReceivablesService($c))->analyze(
                    (float) ($r['current']['total'] ?? 0),
                    (int)   ($r['period']['days']  ?? 1),
                    (string)($r['currency']         ?? 'USD'),
                    $asOf,
                );
            });
        } catch (\Throwable $e) { $error = $e->getMessage(); }

        return view('receivables', [
            'data'        => $data,
            'error'       => $error,
            'active'      => 'receivables',
            'aiPage'      => 'receivables',
            'period'      => $period,
            'periodLabel' => $periodLabel,
            'year'        => $year,
        ]);
    }
}
