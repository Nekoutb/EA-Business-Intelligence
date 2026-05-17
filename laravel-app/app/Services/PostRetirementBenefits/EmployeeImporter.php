<?php
declare(strict_types=1);

namespace App\Services\PostRetirementBenefits;

use App\Models\PostRetirementBenefits\Employee;
use App\Models\Tenant;
use Carbon\Carbon;

/**
 * CSV importer for the employee roster.
 *
 * Accepts a CSV with one header row and these columns (case-insensitive,
 * any order):
 *
 *   employee_code, full_name, gender (M|F|U), birth_date (YYYY-MM-DD),
 *   hire_date (YYYY-MM-DD), termination_date (optional, YYYY-MM-DD or empty),
 *   category (blue_collar|supervisor|manager),
 *   monthly_salary (number, no thousand separators),
 *   entitled_to_cnps_pension (true|false|yes|no|1|0),
 *   cba_key (string slug, e.g. 'commerce'),
 *   active (true|false|yes|no|1|0; defaults to true)
 *
 * Returns a {created, updated, skipped, errors} summary. Existing rows
 * are upserted by (tenant_id, employee_code). Errors are non-fatal —
 * the importer logs them per row and continues so the operator gets
 * one report rather than aborting on first error.
 *
 * Why not validate via the controller's Request: CSV row count can
 * easily reach hundreds and we want per-row error context, not a
 * 422 wall of laravel validation messages.
 */
final class EmployeeImporter
{
    public const REQUIRED_COLUMNS = [
        'employee_code', 'full_name', 'birth_date', 'hire_date',
        'category', 'monthly_salary',
    ];

    public const OPTIONAL_COLUMNS = [
        'gender', 'termination_date', 'entitled_to_cnps_pension',
        'cba_key', 'active', 'notes',
    ];

    /**
     * @return array{
     *   created: int,
     *   updated: int,
     *   skipped: int,
     *   errors:  list<array{row:int, code:?string, error:string}>
     * }
     */
    public function import(Tenant $tenant, string $csvPath): array
    {
        $created = 0; $updated = 0; $skipped = 0;
        $errors  = [];

        if (!file_exists($csvPath)) {
            return [
                'created' => 0, 'updated' => 0, 'skipped' => 0,
                'errors'  => [['row' => 0, 'code' => null, 'error' => "File not found: {$csvPath}"]],
            ];
        }

        $h = fopen($csvPath, 'r');
        if ($h === false) {
            return [
                'created' => 0, 'updated' => 0, 'skipped' => 0,
                'errors'  => [['row' => 0, 'code' => null, 'error' => "Could not open file"]],
            ];
        }

        // Read header. Lowercase + strip whitespace + trim BOM.
        $header = fgetcsv($h);
        if ($header === false || $header === null) {
            fclose($h);
            return [
                'created' => 0, 'updated' => 0, 'skipped' => 0,
                'errors'  => [['row' => 1, 'code' => null, 'error' => "Empty file or missing header"]],
            ];
        }
        $header = array_map(
            fn ($c) => strtolower(trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $c))),
            $header
        );

        // Check required columns are present.
        foreach (self::REQUIRED_COLUMNS as $col) {
            if (!in_array($col, $header, true)) {
                fclose($h);
                return [
                    'created' => 0, 'updated' => 0, 'skipped' => 0,
                    'errors'  => [['row' => 1, 'code' => null, 'error' => "Missing required column: {$col}"]],
                ];
            }
        }

        $rowNum = 1; // header was row 1
        while (($row = fgetcsv($h)) !== false) {
            $rowNum++;
            if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue; // skip blank lines
            }
            $assoc = @array_combine($header, array_pad($row, count($header), null));
            if (!is_array($assoc)) {
                $errors[] = ['row' => $rowNum, 'code' => null, 'error' => 'Column count mismatch'];
                $skipped++;
                continue;
            }

            try {
                $data = $this->normaliseRow($assoc);
            } catch (\Throwable $e) {
                $errors[] = ['row' => $rowNum, 'code' => $assoc['employee_code'] ?? null, 'error' => $e->getMessage()];
                $skipped++;
                continue;
            }

            $existing = Employee::where('tenant_id', $tenant->id)
                ->where('employee_code', $data['employee_code'])
                ->first();
            if ($existing) {
                $existing->fill($data);
                $existing->tenant_id = $tenant->id; // belt-and-braces
                $existing->save();
                $updated++;
            } else {
                $data['tenant_id'] = $tenant->id;
                Employee::create($data);
                $created++;
            }
        }
        fclose($h);

        return compact('created', 'updated', 'skipped', 'errors');
    }

    /**
     * Normalise + validate one row. Throws on bad input.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normaliseRow(array $row): array
    {
        $code = trim((string) ($row['employee_code'] ?? ''));
        if ($code === '') {
            throw new \InvalidArgumentException('employee_code is required');
        }

        $name = trim((string) ($row['full_name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('full_name is required');
        }

        $birth = $this->parseDate($row['birth_date'] ?? null);
        if (!$birth) {
            throw new \InvalidArgumentException('birth_date invalid (use YYYY-MM-DD)');
        }
        $hire = $this->parseDate($row['hire_date'] ?? null);
        if (!$hire) {
            throw new \InvalidArgumentException('hire_date invalid (use YYYY-MM-DD)');
        }
        if ($hire->lt($birth)) {
            throw new \InvalidArgumentException('hire_date is before birth_date');
        }

        $termination = !empty($row['termination_date'])
            ? $this->parseDate($row['termination_date'])
            : null;

        $category = strtolower(trim((string) ($row['category'] ?? '')));
        if (!in_array($category, ['blue_collar', 'supervisor', 'manager'], true)) {
            throw new \InvalidArgumentException("category must be blue_collar, supervisor or manager (got: {$category})");
        }

        $salary = (float) preg_replace('/[^\d.\-]/', '', (string) ($row['monthly_salary'] ?? '0'));
        if ($salary <= 0) {
            throw new \InvalidArgumentException('monthly_salary must be > 0');
        }

        $gender = strtoupper(trim((string) ($row['gender'] ?? 'U')));
        if (!in_array($gender, ['M', 'F', 'U'], true)) {
            $gender = 'U';
        }

        return [
            'employee_code'            => $code,
            'full_name'                => $name,
            'gender'                   => $gender,
            'birth_date'               => $birth,
            'hire_date'                => $hire,
            'termination_date'         => $termination,
            'category'                 => $category,
            'monthly_salary'           => $salary,
            'entitled_to_cnps_pension' => $this->parseBool($row['entitled_to_cnps_pension'] ?? true),
            'cba_key'                  => trim((string) ($row['cba_key'] ?? 'commerce')) ?: 'commerce',
            'active'                   => $this->parseBool($row['active'] ?? true),
            'notes'                    => $row['notes'] ?? null,
        ];
    }

    private function parseDate(mixed $v): ?Carbon
    {
        if ($v === null || $v === '') return null;
        try {
            return Carbon::parse((string) $v)->startOfDay();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function parseBool(mixed $v): bool
    {
        if (is_bool($v)) return $v;
        $s = strtolower(trim((string) $v));
        return in_array($s, ['1', 'true', 'yes', 'y', 'on', 'oui'], true);
    }
}
