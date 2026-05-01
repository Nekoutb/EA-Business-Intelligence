<?php

namespace App\Http\Controllers;

use App\Odoo\OdooFactory;
use App\Services\InvestmentAnalysisService;
use Illuminate\Http\Request;

class InvestmentAnalysisController extends Controller
{
    public function index(Request $request)
    {
        $today = new \DateTimeImmutable();
        $asOfStr = (string) $request->query('as_of', $today->format('Y-m-d'));
        try { $asOf = new \DateTimeImmutable($asOfStr); }
        catch (\Throwable $e) { $asOf = $today; }
        if ($asOf > $today) $asOf = $today;

        $data  = null;
        $error = null;
        try {
            $client = OdooFactory::fromConfig();
            $data = ea_cached(
                'investment_analysis_' . $asOf->format('Y_m_d'),
                fn () => (new InvestmentAnalysisService($client))->analyze($asOf)
            );
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return view('investment-analysis', [
            'active' => 'investment_analysis',
            'asOf'   => $asOf->format('Y-m-d'),
            'data'   => $data,
            'error'  => $error,
        ]);
    }
}
