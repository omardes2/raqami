<?php

namespace App\Modules\Payroll\Calculation\Resolvers;

use App\Modules\Attendance\Models\OvertimeApproval;
use App\Modules\Employees\Models\Employee;

/**
 * Reads ONLY authoritative approved overtime (overtime_approvals, status=approved,
 * approved_minutes) for the period. Never touches raw punches, attendance events,
 * or attendance_records.overtime_minutes.
 */
class PayrollOvertimeInputResolver
{
    /**
     * @return array<int, array{approval_id:string, work_date:string, approved_minutes:int}>
     */
    public function resolve(Employee $employee, string $periodStart, string $periodEnd): array
    {
        return OvertimeApproval::query()
            ->where('employee_id', $employee->getKey())
            ->where('status', 'approved')
            ->whereBetween('work_date', [$periodStart, $periodEnd])
            ->orderBy('work_date')
            ->orderBy('id')
            ->get()
            ->map(fn (OvertimeApproval $o) => [
                'approval_id' => (string) $o->getKey(),
                'work_date' => $o->work_date->toDateString(),
                'approved_minutes' => (int) ($o->approved_minutes ?? 0),
            ])
            ->filter(fn (array $o) => $o['approved_minutes'] > 0)
            ->values()
            ->all();
    }
}
