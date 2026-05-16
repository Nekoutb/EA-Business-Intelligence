<?php

namespace App\Http\Controllers\Demo;

use App\Http\Controllers\Controller;
use App\Models\Demo\DemoSession;
use App\Models\Tenant;
use App\Services\Demo\DemoTenantSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Demo sandbox entry flow.
 *
 *   GET  /demo/enter      → email-gated form (name + email + company + role)
 *   POST /demo/start      → validates the form, creates a fresh Tenant
 *                           with is_demo=true, seeds it via DemoTenantSeeder,
 *                           binds it to the session, redirects to the
 *                           sandbox dashboard.
 *   GET  /demo/exit       → tears down the demo session (delete row),
 *                           does NOT delete the tenant — the daily reaper
 *                           handles that based on expires_at.
 *
 * Why we create a fresh Tenant per visitor: it gives us complete
 * isolation for free via the existing TenantContext + per-tenant
 * data scoping. Two visitors logging in simultaneously each get their
 * own sandbox with their own copy of every demo_* row, scoped by
 * tenant_id. No risk of one prospect's edits being visible to another.
 *
 * Lead capture: name + email + company + role go to demo_sessions
 * and stay there even after the tenant is reaped. Use the admin
 * interface to query demo_sessions for follow-up leads.
 */
class DemoEntryController extends Controller
{
    /** Default sandbox TTL — 2 hours from creation. */
    private const SANDBOX_TTL_HOURS = 2;

    public function showEnterForm(Request $request)
    {
        // If they already have a live demo session, send them straight in.
        $existing = DemoSession::where('session_id', $request->session()->getId())->first();
        if ($existing && !$existing->isExpired() && $existing->tenant?->is_demo) {
            return redirect('/demo/sandbox');
        }

        return view('demo.enter', [
            'oldName'    => old('name'),
            'oldEmail'   => old('email'),
            'oldCompany' => old('company'),
            'oldRole'    => old('role'),
        ]);
    }

    public function start(Request $request, DemoTenantSeeder $seeder)
    {
        $data = $request->validate([
            'name'    => ['required', 'string', 'max:120'],
            'email'   => ['required', 'email', 'max:191'],
            'company' => ['nullable', 'string', 'max:191'],
            'role'    => ['nullable', 'string', 'max:120'],
        ]);

        // Spawn a fresh demo Tenant. The slug is unique + opaque so
        // it doesn't collide with real tenants. Brand uses the visitor's
        // company name so the demo header shows their org as the "client".
        $slug = 'demo-' . strtolower(Str::random(10));
        $brand = trim($data['company'] ?? '') !== ''
            ? "Demo · " . $data['company']
            : "Demo · " . explode('@', $data['email'])[0];

        $tenant = Tenant::create([
            'slug'             => $slug,
            'name'             => $brand,
            'brand'            => $brand,
            'status'           => Tenant::STATUS_ACTIVE,
            'plan'             => 'demo',
            'is_demo'          => true,
            'demo_expires_at'  => now()->addHours(self::SANDBOX_TTL_HOURS),
        ]);

        // Seed the demo dataset for this tenant — full reseed wipes any
        // pre-existing rows (there shouldn't be any on a brand-new tenant
        // but the call is idempotent so this is harmless).
        try {
            $seeder->reseed($tenant);
        } catch (\Throwable $e) {
            Log::error('Demo seed failed for tenant ' . $tenant->id . ': ' . $e->getMessage());
            $tenant->delete();
            return back()->withInput()->with('flash', [
                'type' => 'error',
                'msg'  => 'Could not provision the demo sandbox. Please try again.',
            ]);
        }

        // Persist the session row tying this browser to the demo tenant.
        DemoSession::create([
            'session_id'   => $request->session()->getId(),
            'tenant_id'    => $tenant->id,
            'name'         => $data['name'],
            'email'        => $data['email'],
            'company'      => $data['company'] ?? null,
            'role'         => $data['role'] ?? null,
            'ip_address'   => $request->ip(),
            'user_agent'   => substr((string) $request->userAgent(), 0, 512),
            'started_at'   => now(),
            'last_seen_at' => now(),
            'expires_at'   => $tenant->demo_expires_at,
        ]);

        return redirect('/demo/sandbox')->with('flash', [
            'type' => 'success',
            'msg'  => "Welcome, {$data['name']}. Your demo sandbox is ready — explore, modify the data, and watch the BMS recompute everything live.",
        ]);
    }

    public function exit(Request $request)
    {
        $sid = $request->session()->getId();
        $demoSession = DemoSession::where('session_id', $sid)->first();

        if ($demoSession) {
            // Optional: delete the tenant immediately rather than waiting
            // for the reaper. Cleaner from a "the visitor explicitly
            // left" perspective.
            $tenant = $demoSession->tenant;
            $demoSession->delete();
            if ($tenant && $tenant->is_demo) {
                $tenant->delete();
            }
        }

        return redirect('/demo')->with('flash', [
            'type' => 'success',
            'msg'  => 'Demo session ended. Your sandbox data has been deleted.',
        ]);
    }
}
