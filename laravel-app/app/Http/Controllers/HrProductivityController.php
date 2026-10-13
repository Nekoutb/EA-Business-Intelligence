<?php

namespace App\Http\Controllers;

use App\Odoo\OdooFactory;
use App\Services\HrProductivityService;
use Illuminate\Http\Request;

/** Human Resources › Productivity — monthly people-cost and activity metrics. */
class HrProductivityController extends Controller
{
    public function index(Request $request)
    {
        $thisYear = (int) date('Y');
        $year = max(2018, min($thisYear, (int) $request->query('year', $thisYear)));
        $data = null; $error = null;
        try {
            $data = ea_cached('hr_productivity_v2_' . $year . '_' . \App\Services\ProductivityOverrides::fingerprint() . (\App\Services\FileCounts::clearanceMode() ? 'c' : ''),
                fn () => (new HrProductivityService(OdooFactory::fromConfig()))->analyze($year));
        } catch (\Throwable $e) {
            $error = ea_safe_error($e, 'hr.productivity', 'Could not load productivity data.');
        }
        return view('hr-productivity', [
            'data' => $data, 'error' => $error, 'year' => $year,
            'active' => 'hr_productivity',
        ]);
    }
}
