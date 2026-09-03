<?php

namespace App\Modules\Payroll\Calculation;

use App\Modules\Employees\Models\Employee;
use App\Modules\Payroll\Models\PayrollPeriod;
use Illuminate\Support\Collection;

/**
 * The cohort for a regular run: every employee whose employment interval overlaps
 * the period (hire_date inclusive, termination_date inclusive). Employees are NOT
 * excluded for missing compensation/schedule/leave/overtime data — those become
 * FAILED entries, still visible in Run Review, never silently dropped.
 */
class PayrollEmployeeCohortService
{
    /** @return Collection<int, Employee> */
    public function forPeriod(PayrollPeriod $period): Collection
    {
        $start = $period->period_start->toDateString();
        $end = $period->period_end->toDateString();

        return Employee::query()
            ->where(fn ($q) => $q->whereNull('hire_date')->orWhere('hire_date', '<=', $end))
            ->where(fn ($q) => $q->whereNull('termination_date')->orWhere('termination_date', '>=', $start))
            ->orderBy('id')
            ->get();
    }
}
