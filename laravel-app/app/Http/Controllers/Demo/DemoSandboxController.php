<?php

namespace App\Http\Controllers\Demo;

use App\Http\Controllers\Controller;
use App\Services\Demo\DemoFactsService;
use App\Services\Demo\DemoTenantSeeder;
use Illuminate\Http\Request;

/**
 * Demo sandbox surfaces — dashboard + 3 BMS modules (Revenue,
 * Receivables, P&L) running against demo_* tables for the current
 * demo tenant. ResolveDemoSession has already bound the tenant
 * to TenantContext by the time we get here.
 */
class DemoSandboxController extends Controller
{
    public function dashboard(Request $request, DemoFactsService $facts)
    {
        $tenant = $request->attributes->get('demo_tenant');
        return view('demo.sandbox.dashboard', [
            'kpi'    => $facts->dashboard($tenant),
            'tenant' => $tenant,
            'active' => 'dashboard',
        ]);
    }

    public function revenue(Request $request, DemoFactsService $facts)
    {
        $tenant = $request->attributes->get('demo_tenant');
        $days   = max(7, min(365, (int) $request->query('days', 30)));
        return view('demo.sandbox.revenue', [
            'data'   => $facts->revenueDetail($tenant, $days),
            'days'   => $days,
            'tenant' => $tenant,
            'active' => 'revenue',
        ]);
    }

    public function receivables(Request $request, DemoFactsService $facts)
    {
        $tenant = $request->attributes->get('demo_tenant');
        return view('demo.sandbox.receivables', [
            'data'   => $facts->receivables($tenant),
            'tenant' => $tenant,
            'active' => 'receivables',
        ]);
    }

    public function profitLoss(Request $request, DemoFactsService $facts)
    {
        $tenant = $request->attributes->get('demo_tenant');
        $days   = max(30, min(365, (int) $request->query('days', 90)));
        return view('demo.sandbox.profit-loss', [
            'data'   => $facts->profitLoss($tenant, $days),
            'days'   => $days,
            'tenant' => $tenant,
            'active' => 'profit_loss',
        ]);
    }

    public function trialBalance(Request $request, DemoFactsService $facts)
    {
        $tenant = $request->attributes->get('demo_tenant');
        return view('demo.sandbox.trial-balance', [
            'data'   => $facts->trialBalance($tenant),
            'tenant' => $tenant,
            'active' => 'trial_balance',
        ]);
    }

    public function glQuality(Request $request, DemoFactsService $facts)
    {
        $tenant = $request->attributes->get('demo_tenant');
        return view('demo.sandbox.gl-quality', [
            'data'   => $facts->glQuality($tenant),
            'tenant' => $tenant,
            'active' => 'gl_quality',
        ]);
    }

    public function workingCapital(Request $request, DemoFactsService $facts)
    {
        $tenant = $request->attributes->get('demo_tenant');
        return view('demo.sandbox.working-capital', [
            'data'   => $facts->workingCapital($tenant),
            'tenant' => $tenant,
            'active' => 'working_capital',
        ]);
    }

    public function billing(Request $request, DemoFactsService $facts)
    {
        $tenant = $request->attributes->get('demo_tenant');
        $days   = max(7, min(365, (int) $request->query('days', 90)));
        return view('demo.sandbox.billing', [
            'data'   => $facts->billing($tenant, $days),
            'days'   => $days,
            'tenant' => $tenant,
            'active' => 'billing',
        ]);
    }

    public function customerInsights(Request $request, DemoFactsService $facts)
    {
        $tenant = $request->attributes->get('demo_tenant');
        return view('demo.sandbox.customer-insights', [
            'data'   => $facts->customerInsights($tenant),
            'tenant' => $tenant,
            'active' => 'customer_insights',
        ]);
    }

    public function taxReturn(Request $request, DemoFactsService $facts)
    {
        $tenant = $request->attributes->get('demo_tenant');
        $month  = $request->query('month');
        $monthStart = $month && preg_match('/^\d{4}-\d{2}$/', $month)
            ? \Carbon\Carbon::createFromFormat('Y-m', $month)->startOfMonth()
            : null;
        return view('demo.sandbox.tax-return', [
            'data'   => $facts->taxReturn($tenant, $monthStart),
            'tenant' => $tenant,
            'active' => 'tax_return',
        ]);
    }

    /** Reset the seed data on demand. */
    public function reset(Request $request, DemoTenantSeeder $seeder)
    {
        $tenant = $request->attributes->get('demo_tenant');
        try {
            $seeder->reseed($tenant);
            \App\Models\Demo\DemoCommandAudit::create([
                'tenant_id'         => $tenant->id,
                'source'            => \App\Models\Demo\DemoCommandAudit::SOURCE_SEED_RESET,
                'parsed_operations' => ['action' => 'reseed'],
                'status'            => \App\Models\Demo\DemoCommandAudit::STATUS_SUCCESS,
                'applied_at'        => now(),
            ]);
            return redirect('/demo/sandbox')->with('flash', [
                'type' => 'success',
                'msg'  => 'Demo data reset to seed. All your edits have been replaced with the original dataset.',
            ]);
        } catch (\Throwable $e) {
            return redirect('/demo/sandbox')->with('flash', [
                'type' => 'error',
                'msg'  => 'Reset failed: ' . $e->getMessage(),
            ]);
        }
    }
}
