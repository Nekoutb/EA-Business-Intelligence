<?php

namespace App\Http\Controllers\Tax;

use App\Http\Controllers\Controller;
use App\Odoo\OdooFactory;
use App\Services\Tax\MonthlyVatReturnService;
use Illuminate\Http\Request;

class MonthlyVatReturnController extends Controller
{
    public function index(Request $request)
    {
        $analysis = null;
        $error    = null;
        $month    = (string) $request->query('month', '');
        // Sanitize the month query param to YYYY-MM only.
        if ($month !== '' && !preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = '';
        }

        $cacheKey = 'monthly_vat_return_' . ($month ?: 'current');

        try {
            $analysis = ea_cached($cacheKey, function () use ($month) {
                return (new MonthlyVatReturnService(OdooFactory::fromConfig()))->analyze($month ?: null);
            });
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return view('tax.monthly-vat-return', [
            'analysis' => $analysis,
            'error'    => $error,
            'month'    => $month,
            'active'   => 'monthly_vat_return',
        ]);
    }
}
