<?php

namespace App\Modules\Employees\Services;

use App\Modules\Employees\Models\Employee;
use App\Modules\Tenancy\Services\TenantContext;

/**
 * Safe default employee-number generator: EMP-000123, unique per tenant.
 * Architected so a company-configurable format can replace this later; the
 * ULID remains the internal primary key regardless.
 */
class EmployeeNumberGenerator
{
    private const PREFIX = 'EMP-';

    private const PAD = 6;

    public function __construct(private readonly TenantContext $context) {}

    public function next(): string
    {
        // Highest existing EMP-###### suffix in this tenant (RLS-scoped query).
        $max = Employee::query()
            ->withTrashed()
            ->where('employee_number', 'like', self::PREFIX.'%')
            ->get(['employee_number'])
            ->map(function (Employee $e) {
                if (preg_match('/^'.preg_quote(self::PREFIX, '/').'(\d+)$/', $e->employee_number, $m)) {
                    return (int) $m[1];
                }

                return 0;
            })
            ->max() ?? 0;

        return self::PREFIX.str_pad((string) ($max + 1), self::PAD, '0', STR_PAD_LEFT);
    }
}
