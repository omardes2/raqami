<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Employees\Support\EmployeeScopeResolver;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-side attendance reporting. Every query is constrained to the caller's
 * organizational scope (via EmployeeScopeResolver over the related employee), so
 * a branch manager's report can never include another branch — RLS keeps it in
 * the tenant, scope keeps it in the caller's slice of that tenant.
 */
class AttendanceReportService
{
    public function __construct(private readonly EmployeeScopeResolver $scope) {}

    /**
     * Base records query limited to the employees the user may view.
     *
     * @param  array{from?:?string, to?:?string, employee_id?:?string, status?:?string}  $filters
     */
    public function scopedRecords(User $user, array $filters = []): Builder
    {
        $query = AttendanceRecord::query()
            ->whereHas('employee', function (Builder $q) use ($user) {
                $this->scope->applyScope($q, $user, 'attendance.view');
            });

        if (! empty($filters['employee_id'])) {
            $query->where('employee_id', $filters['employee_id']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['from'])) {
            $query->whereDate('work_date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('work_date', '<=', $filters['to']);
        }

        return $query;
    }

    /**
     * Aggregate totals over the scoped/filtered set (server-computed minutes).
     *
     * @param  array{from?:?string, to?:?string, employee_id?:?string, status?:?string}  $filters
     * @return array<string, int>
     */
    public function summary(User $user, array $filters = []): array
    {
        $base = $this->scopedRecords($user, $filters);

        return [
            'records' => (clone $base)->count(),
            'present' => (clone $base)->where('status', 'present')->count(),
            'late' => (clone $base)->where('status', 'late')->count(),
            'absent' => (clone $base)->where('status', 'absent')->count(),
            'worked_minutes' => (int) (clone $base)->sum('worked_minutes'),
            'late_minutes' => (int) (clone $base)->sum('late_minutes'),
            'overtime_minutes' => (int) (clone $base)->sum('overtime_minutes'),
            'early_leave_minutes' => (int) (clone $base)->sum('early_leave_minutes'),
        ];
    }
}
