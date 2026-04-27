<?php

namespace App\Http\Controllers\Tax;

use App\Http\Controllers\Controller;

/**
 * VAT Compliance — VAT-specific filings, prorata, and reconciliation.
 *
 * This is a placeholder route created during the 2026-04-27 module
 * reshuffle. The detailed VAT analytics (collected/deductible reconciliation,
 * prorata computation, refund eligibility, monthly return packets) will land
 * here in a follow-up.
 */
class VATComplianceController extends Controller
{
    public function index()
    {
        return view('tax.vat-compliance', [
            'active' => 'vat_compliance',
        ]);
    }
}
