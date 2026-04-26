<?php

namespace App\Http\Controllers;

use App\Odoo\OdooFactory;
use App\Services\GLQualityService;
use Illuminate\Http\Request;

/**
 * GL Quality — sub-ledger vs trial balance reconciliations
 * (Accounts Receivable + Inventory). YTD-only by design, just like the
 * inventory page; no period parameter is read.
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
}
