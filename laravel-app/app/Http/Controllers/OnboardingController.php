<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\TenantSetting;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

class OnboardingController extends Controller
{
    public function show(Request $request, TenantContext $context)
    {
        $tenant = $context->tenant();

        // If already onboarded, redirect to dashboard
        if ($tenant->onboarded_at !== null) {
            return redirect('/t/' . $tenant->slug . '/');
        }

        $step = (int) $request->query('step', 1);
        $onboardingData = session('onboarding_data', []);
        $services = config('modules.services', []);
        $modules = config('modules.modules', []);

        if ($step === 1) {
            return view('onboarding.step-1-company', [
                'tenant' => $tenant,
                'data'   => $onboardingData['company'] ?? [],
            ]);
        } elseif ($step === 2) {
            $enabledServices = $tenant->services()
                ->where('enabled', true)
                ->pluck('service_key')
                ->all();

            return view('onboarding.step-2-services', [
                'tenant'          => $tenant,
                'services'        => $services,
                'modules'         => $modules,
                'enabledServices' => $enabledServices,
            ]);
        } elseif ($step === 3) {
            $enabledServices = $tenant->services()
                ->where('enabled', true)
                ->pluck('service_key')
                ->toArray();

            $needsOdoo = in_array('business_intelligence', $enabledServices) || in_array('tax_compliance', $enabledServices);

            $currentOdoo = [
                'ODOO_URL'      => $tenant->setting('ODOO_URL', ''),
                'ODOO_DB'       => $tenant->setting('ODOO_DB', ''),
                'ODOO_USERNAME' => $tenant->setting('ODOO_USERNAME', ''),
                'ODOO_PROTOCOL' => $tenant->setting('ODOO_PROTOCOL', 'json2'),
            ];

            return view('onboarding.step-3-integration', [
                'tenant'       => $tenant,
                'needsOdoo'    => $needsOdoo,
                'data'         => $onboardingData['integration'] ?? [],
                'currentOdoo'  => $currentOdoo,
            ]);
        } elseif ($step === 4) {
            $enabledServices = $tenant->services()
                ->where('enabled', true)
                ->pluck('service_key')
                ->all();

            return view('onboarding.step-4-configuration', [
                'tenant'          => $tenant,
                'enabledServices' => $enabledServices,
                'data'            => $onboardingData['configuration'] ?? [],
            ]);
        } elseif ($step === 5) {
            $company = $onboardingData['company'] ?? [];
            $enabledServices = $tenant->services()
                ->where('enabled', true)
                ->with('service_key')
                ->pluck('service_key')
                ->all();

            return view('onboarding.step-5-review', [
                'tenant'          => $tenant,
                'company'         => $company,
                'enabledServices' => $enabledServices,
                'allServices'     => $services,
            ]);
        }

        return redirect('/t/' . $tenant->slug . '/onboarding?step=1');
    }

    public function step(Request $request, TenantContext $context)
    {
        $tenant = $context->tenant();

        if ($tenant->onboarded_at !== null) {
            return back()->with('flash', ['type' => 'info', 'msg' => 'Onboarding already completed.']);
        }

        $step = (int) $request->input('step', 1);
        $action = (string) $request->input('action', 'next');

        if ($action === 'cancel') {
            $request->session()->forget('onboarding_data');
            return redirect('/t/' . $tenant->slug . '/')->with('flash', ['type' => 'info', 'msg' => 'Onboarding cancelled. You can complete it later.']);
        }

        if ($action === 'prev') {
            return redirect('/t/' . $tenant->slug . '/onboarding?step=' . max(1, $step - 1));
        }

        if ($action === 'next') {
            // Validate and save step data
            if ($step === 1) {
                $data = $request->validate([
                    'company_name'        => ['nullable', 'string', 'max:191'],
                    'industry'            => ['nullable', 'string', 'max:191'],
                    'tax_id'              => ['nullable', 'string', 'max:191'],
                    'address'             => ['nullable', 'string', 'max:500'],
                    'country'             => ['nullable', 'string', 'max:100'],
                ]);

                $onboardingData = $request->session()->get('onboarding_data', []);
                $onboardingData['company'] = array_filter($data, fn ($v) => $v !== null && $v !== '');
                $request->session()->put('onboarding_data', $onboardingData);
            } elseif ($step === 3) {
                $rules = ['skip_integration' => ['nullable', 'boolean']];

                $enabledServices = $tenant->services()
                    ->where('enabled', true)
                    ->pluck('service_key')
                    ->toArray();

                $needsOdoo = in_array('business_intelligence', $enabledServices) || in_array('tax_compliance', $enabledServices);

                if ($needsOdoo && !$request->has('skip_integration')) {
                    $rules = [
                        'ODOO_URL'      => ['required', 'url'],
                        'ODOO_DB'       => ['required', 'string', 'max:191'],
                        'ODOO_USERNAME' => ['required', 'string', 'max:191'],
                        'ODOO_API_KEY'  => ['required', 'string'],
                        'ODOO_PROTOCOL' => ['required', 'in:json2,jsonrpc,xmlrpc'],
                    ];
                }

                $data = $request->validate($rules);
                $onboardingData = $request->session()->get('onboarding_data', []);
                $onboardingData['integration'] = $data;
                $request->session()->put('onboarding_data', $onboardingData);
            } elseif ($step === 4) {
                $data = $request->validate([
                    'tax_regime'    => ['nullable', 'string', 'max:100'],
                    'vat_number'    => ['nullable', 'string', 'max:191'],
                    'revenue_source' => ['nullable', 'string', 'max:191'],
                ]);

                $onboardingData = $request->session()->get('onboarding_data', []);
                $onboardingData['configuration'] = array_filter($data, fn ($v) => $v !== null && $v !== '');
                $request->session()->put('onboarding_data', $onboardingData);
            }

            $nextStep = min(5, $step + 1);
            return redirect('/t/' . $tenant->slug . '/onboarding?step=' . $nextStep);
        }

        return back();
    }

    public function complete(Request $request, TenantContext $context)
    {
        $tenant = $context->tenant();

        if ($tenant->onboarded_at !== null) {
            return redirect('/t/' . $tenant->slug . '/');
        }

        $request->validate(['confirm' => ['required', 'accepted']]);

        $onboardingData = session('onboarding_data', []);

        // Save company information
        if (!empty($onboardingData['company'])) {
            foreach ($onboardingData['company'] as $key => $value) {
                $settingKey = strtoupper($key);
                TenantSetting::firstOrCreate(
                    ['tenant_id' => $tenant->id, 'key' => $settingKey],
                    ['value' => $value]
                )->update(['value' => $value]);
            }
        }

        // Save integration details
        if (!empty($onboardingData['integration']) && !($onboardingData['integration']['skip_integration'] ?? false)) {
            foreach (['ODOO_URL', 'ODOO_DB', 'ODOO_USERNAME', 'ODOO_API_KEY', 'ODOO_PROTOCOL'] as $key) {
                if (!empty($onboardingData['integration'][$key])) {
                    TenantSetting::firstOrCreate(
                        ['tenant_id' => $tenant->id, 'key' => $key],
                        ['value' => $onboardingData['integration'][$key]]
                    )->update(['value' => $onboardingData['integration'][$key]]);
                }
            }
        }

        // Save service-specific configuration
        if (!empty($onboardingData['configuration'])) {
            foreach ($onboardingData['configuration'] as $key => $value) {
                $settingKey = strtoupper($key);
                TenantSetting::firstOrCreate(
                    ['tenant_id' => $tenant->id, 'key' => $settingKey],
                    ['value' => $value]
                )->update(['value' => $value]);
            }
        }

        // Mark onboarding as complete
        $tenant->update(['onboarded_at' => now()]);

        AuditLog::record('onboarding.complete', [
            'tenant_id' => $tenant->id,
            'target'    => $tenant->slug,
            'payload'   => ['settings_updated' => count(array_merge($onboardingData['company'] ?? [], $onboardingData['integration'] ?? [], $onboardingData['configuration'] ?? []))],
        ]);

        $request->session()->forget('onboarding_data');

        return redirect('/t/' . $tenant->slug . '/')->with('flash', [
            'type' => 'success',
            'msg'  => 'Workspace onboarding completed successfully!',
        ]);
    }
}
