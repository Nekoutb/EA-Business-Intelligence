<?php

namespace App\Http\Controllers\PostRetirementBenefits;

use App\Http\Controllers\Controller;
use App\Models\PostRetirementBenefits\Employee;
use App\Services\PostRetirementBenefits\CbaRegistry;
use App\Services\PostRetirementBenefits\EmployeeImporter;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Employee CRUD + CSV import for the Post-Retirement Benefits module.
 *
 * Tenant-scoped via TenantContext (the route group middleware has
 * already resolved the tenant). All persisted rows carry tenant_id.
 *
 * The page surfaces 3 things:
 *   - Headcount + payroll roll-up (sticky at top)
 *   - Searchable + sortable employee table
 *   - One-click CSV import (with downloadable template)
 *
 * Per-employee edits use a single modal-free page so the operator
 * can keep the table in view while editing.
 */
class EmployeeController extends Controller
{
    public function index(Request $request)
    {
        $tenant = app(TenantContext::class)->tenant();

        $query = Employee::where('tenant_id', $tenant->id);

        // Search by name or code
        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('full_name', 'like', "%{$q}%")
                  ->orWhere('employee_code', 'like', "%{$q}%");
            });
        }

        // Filter by category
        $category = $request->query('category');
        if (in_array($category, ['blue_collar', 'supervisor', 'manager'], true)) {
            $query->where('category', $category);
        }

        // Filter by status
        $status = $request->query('status', 'active');
        if ($status === 'active') {
            $query->where('active', true);
        } elseif ($status === 'inactive') {
            $query->where('active', false);
        }
        // 'all' = no filter

        $employees = $query->orderBy('full_name')->paginate(50)->withQueryString();

        // Roll-up across the full unfiltered set so totals are stable
        // regardless of the active filter.
        $rollup = Employee::where('tenant_id', $tenant->id)
            ->where('active', true)
            ->selectRaw('category, count(*) as n, sum(monthly_salary) as p')
            ->groupBy('category')
            ->get()
            ->keyBy('category');
        $headcount = ['blue_collar' => 0, 'supervisor' => 0, 'manager' => 0];
        $payroll   = ['blue_collar' => 0, 'supervisor' => 0, 'manager' => 0];
        foreach ($rollup as $cat => $row) {
            $headcount[$cat] = (int) $row->n;
            $payroll[$cat]   = (float) $row->p;
        }

        return view('post-retirement-benefits.employees.index', [
            'employees'       => $employees,
            'headcount'       => $headcount,
            'payroll'         => $payroll,
            'totalHeadcount'  => array_sum($headcount),
            'totalPayroll'    => array_sum($payroll),
            'q'               => $q,
            'category'        => $category,
            'status'          => $status,
            'active'          => 'post_retirement_benefits',
        ]);
    }

    public function create(Request $request)
    {
        $tenant = app(TenantContext::class)->tenant();
        return view('post-retirement-benefits.employees.edit', [
            'employee' => null,
            'cbas'     => CbaRegistry::listForTenant($tenant),
            'active'   => 'post_retirement_benefits',
        ]);
    }

    public function store(Request $request)
    {
        $tenant = app(TenantContext::class)->tenant();
        $data = $this->validateEmployee($request, null, $tenant->id);
        $data['tenant_id'] = $tenant->id;
        Employee::create($data);

        return redirect()->route('post-retirement-benefits.employees.index', ['tenant' => $tenant->slug])
            ->with('flash', ['type' => 'success', 'msg' => "Employee {$data['full_name']} added."]);
    }

    public function edit(Request $request, $tenant, int $id)
    {
        $tenantModel = app(TenantContext::class)->tenant();
        $employee = Employee::where('tenant_id', $tenantModel->id)->findOrFail($id);
        return view('post-retirement-benefits.employees.edit', [
            'employee' => $employee,
            'cbas'     => CbaRegistry::listForTenant($tenantModel),
            'active'   => 'post_retirement_benefits',
        ]);
    }

    public function update(Request $request, $tenant, int $id)
    {
        $tenantModel = app(TenantContext::class)->tenant();
        $employee = Employee::where('tenant_id', $tenantModel->id)->findOrFail($id);
        $data = $this->validateEmployee($request, $employee->id, $tenantModel->id);
        $employee->update($data);

        return redirect()->route('post-retirement-benefits.employees.index', ['tenant' => $tenantModel->slug])
            ->with('flash', ['type' => 'success', 'msg' => "Employee {$employee->full_name} updated."]);
    }

    public function destroy(Request $request, $tenant, int $id)
    {
        $tenantModel = app(TenantContext::class)->tenant();
        $employee = Employee::where('tenant_id', $tenantModel->id)->findOrFail($id);
        $name = $employee->full_name;
        $employee->delete();
        return redirect()->route('post-retirement-benefits.employees.index', ['tenant' => $tenantModel->slug])
            ->with('flash', ['type' => 'success', 'msg' => "Removed {$name}."]);
    }

    public function importForm(Request $request)
    {
        return view('post-retirement-benefits.employees.import', [
            'active' => 'post_retirement_benefits',
        ]);
    }

    public function importStore(Request $request, EmployeeImporter $importer)
    {
        $tenant = app(TenantContext::class)->tenant();
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]);

        $path = $request->file('file')->getRealPath();
        $result = $importer->import($tenant, $path);

        $msg = "Import complete: {$result['created']} created, {$result['updated']} updated";
        if ($result['skipped'] > 0) {
            $msg .= ", {$result['skipped']} skipped (see errors below)";
        }

        return redirect()->route('post-retirement-benefits.employees.import', ['tenant' => $tenant->slug])
            ->with('flash', [
                'type' => $result['skipped'] > 0 ? 'warning' : 'success',
                'msg'  => $msg,
            ])
            ->with('import_result', $result);
    }

    /**
     * Stream a downloadable CSV template so operators don't have to
     * guess the column shape. Includes 3 demo rows showing the
     * three categories and a CNPS-yes/no example.
     */
    public function importTemplate(Request $request)
    {
        $filename = 'post-retirement-employees-template.csv';
        $columns  = [
            'employee_code', 'full_name', 'gender',
            'birth_date', 'hire_date', 'termination_date',
            'category', 'monthly_salary',
            'entitled_to_cnps_pension', 'cba_key', 'active', 'notes',
        ];
        $samples = [
            ['E001', 'Jean Marie Tchatchoua', 'M', '1985-03-12', '2015-06-01', '', 'manager',    '850000', 'true',  'commerce', 'true', 'Example manager'],
            ['E002', 'Aïcha Mbarga',          'F', '1992-08-22', '2019-02-15', '', 'supervisor', '380000', 'true',  'commerce', 'true', ''],
            ['E003', 'Paul Kameni',           'M', '1978-11-05', '2010-01-10', '', 'blue_collar','180000', 'false', 'commerce', 'true', 'Pre-CNPS-reform'],
        ];

        return response()->streamDownload(function () use ($columns, $samples) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $columns);
            foreach ($samples as $r) {
                fputcsv($out, $r);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * Validation rules shared by store + update. Employee codes are
     * unique within a tenant (not globally).
     */
    private function validateEmployee(Request $request, ?int $existingId, int $tenantId): array
    {
        $data = $request->validate([
            'employee_code'            => ['required', 'string', 'max:64',
                Rule::unique('post_retirement_employees', 'employee_code')
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId))
                    ->ignore($existingId)],
            'full_name'                => ['required', 'string', 'max:191'],
            'gender'                   => ['required', Rule::in(['M', 'F', 'U'])],
            'birth_date'               => ['required', 'date', 'before:today'],
            'hire_date'                => ['required', 'date', 'after_or_equal:birth_date', 'before_or_equal:today'],
            'termination_date'         => ['nullable', 'date', 'after_or_equal:hire_date'],
            'category'                 => ['required', Rule::in(['blue_collar', 'supervisor', 'manager'])],
            'monthly_salary'           => ['required', 'numeric', 'min:0'],
            'entitled_to_cnps_pension' => ['nullable'],
            'cba_key'                  => ['required', 'string', 'max:64'],
            'active'                   => ['nullable'],
            'notes'                    => ['nullable', 'string', 'max:2000'],
        ]);
        $data['entitled_to_cnps_pension'] = $request->boolean('entitled_to_cnps_pension');
        $data['active']                   = $request->boolean('active', true);
        return $data;
    }
}
