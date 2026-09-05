<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Models\OvertimeApproval;
use App\Modules\Employees\Models\Employee;
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

    /**
     * Full status breakdown over the scoped/filtered set. Includes the Sprint 4
     * derived statuses (weekend/holiday/incomplete). Counts only — never GPS.
     *
     * @param  array{from?:?string, to?:?string, employee_id?:?string, status?:?string}  $filters
     * @return array<string, int>
     */
    public function statusBreakdown(User $user, array $filters = []): array
    {
        $rows = $this->scopedRecords($user, $filters)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $breakdown = [];
        foreach (['present', 'late', 'absent', 'incomplete', 'holiday', 'weekend', 'on_leave', 'pending_review'] as $status) {
            $breakdown[$status] = (int) ($rows[$status] ?? 0);
        }

        return $breakdown;
    }

    /**
     * Compliance metrics (neutral — attendance & punctuality RATES, never a
     * "performance score"). Rates are fractions in [0,1], null when undefined
     * (no applicable days), so the client can render "—" rather than a fake 0/100%.
     *
     * @param  array{from?:?string, to?:?string, employee_id?:?string, status?:?string}  $filters
     * @return array{present:int, late:int, absent:int, scheduled_days:int, attendance_rate:?float, punctuality_rate:?float}
     */
    public function compliance(User $user, array $filters = []): array
    {
        $base = $this->scopedRecords($user, $filters);
        $present = (clone $base)->where('status', 'present')->count();
        $late = (clone $base)->where('status', 'late')->count();
        $absent = (clone $base)->where('status', 'absent')->count();

        $scheduled = $present + $late + $absent;      // days the employee was expected
        $attended = $present + $late;

        return [
            'present' => $present,
            'late' => $late,
            'absent' => $absent,
            'scheduled_days' => $scheduled,
            'attendance_rate' => $scheduled > 0 ? round($attended / $scheduled, 4) : null,
            'punctuality_rate' => $attended > 0 ? round($present / $attended, 4) : null,
        ];
    }

    /**
     * Overtime rollup over the scoped set: raw calculated vs approved minutes,
     * kept distinct (only approved overtime feeds any future payroll). No money.
     *
     * @param  array{from?:?string, to?:?string, employee_id?:?string}  $filters
     * @return array{requests:int, pending:int, approved:int, rejected:int, calculated_minutes:int, approved_minutes:int}
     */
    public function overtime(User $user, array $filters = []): array
    {
        $base = OvertimeApproval::query()
            ->whereHas('employee', function (Builder $q) use ($user) {
                $this->scope->applyScope($q, $user, 'attendance.view');
            });

        if (! empty($filters['employee_id'])) {
            $base->where('employee_id', $filters['employee_id']);
        }
        if (! empty($filters['from'])) {
            $base->whereDate('work_date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $base->whereDate('work_date', '<=', $filters['to']);
        }

        return [
            'requests' => (clone $base)->count(),
            'pending' => (clone $base)->where('status', 'pending')->count(),
            'approved' => (clone $base)->where('status', 'approved')->count(),
            'rejected' => (clone $base)->where('status', 'rejected')->count(),
            'calculated_minutes' => (int) (clone $base)->sum('calculated_minutes'),
            'approved_minutes' => (int) (clone $base)->sum('approved_minutes'),
        ];
    }

    /**
     * Organization rollup: scoped records grouped by the employee's branch or
     * department (Sprint 8A gap). Same authoritative source as the other reports
     * (attendance_records — never sessions), counts + minutes only, no GPS. A null
     * unit (unassigned) is preserved as null so gaps are visible without leaking.
     *
     * @param  array{from?:?string, to?:?string, employee_id?:?string, status?:?string}  $filters
     * @param  'branch'|'department'  $groupBy
     * @return array<int, array<string, mixed>>
     */
    public function byUnit(User $user, array $filters, string $groupBy): array
    {
        $column = $groupBy === 'department' ? 'employees.department_id' : 'employees.branch_id';

        // Scope via a scoped employee-id subquery (not whereHas) so the join to
        // `employees` below cannot collide with a correlated relation subquery.
        $scopedIds = $this->scope->applyScope(Employee::query(), $user, 'attendance.view')->select('id');

        $query = AttendanceRecord::query()
            ->whereIn('attendance_records.employee_id', $scopedIds);

        if (! empty($filters['employee_id'])) {
            $query->where('attendance_records.employee_id', $filters['employee_id']);
        }
        if (! empty($filters['status'])) {
            $query->where('attendance_records.status', $filters['status']);
        }
        if (! empty($filters['from'])) {
            $query->whereDate('attendance_records.work_date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('attendance_records.work_date', '<=', $filters['to']);
        }

        return $query
            ->join('employees', 'employees.id', '=', 'attendance_records.employee_id')
            ->selectRaw("{$column} as unit_id")
            ->selectRaw("count(*) filter (where attendance_records.status = 'present') as present")
            ->selectRaw("count(*) filter (where attendance_records.status = 'late') as late")
            ->selectRaw("count(*) filter (where attendance_records.status = 'absent') as absent")
            ->selectRaw('count(*) as records')
            ->selectRaw('coalesce(sum(attendance_records.worked_minutes),0) as worked_minutes')
            ->selectRaw('coalesce(sum(attendance_records.overtime_minutes),0) as overtime_minutes')
            ->groupBy($column)
            ->get()
            ->map(fn ($row) => [
                'unit_id' => $row->unit_id !== null ? (string) $row->unit_id : null,
                'records' => (int) $row->records,
                'present' => (int) $row->present,
                'late' => (int) $row->late,
                'absent' => (int) $row->absent,
                'worked_minutes' => (int) $row->worked_minutes,
                'overtime_minutes' => (int) $row->overtime_minutes,
            ])
            ->all();
    }

    /**
     * Per-employee rollup (counts + server-computed minutes). No GPS is selected,
     * so the report can be shared without exposing raw location data.
     *
     * @param  array{from?:?string, to?:?string, employee_id?:?string, status?:?string}  $filters
     * @return array<int, array<string, mixed>>
     */
    public function byEmployee(User $user, array $filters = []): array
    {
        return $this->scopedRecords($user, $filters)
            ->selectRaw('employee_id')
            ->selectRaw("count(*) filter (where status = 'present') as present")
            ->selectRaw("count(*) filter (where status = 'late') as late")
            ->selectRaw("count(*) filter (where status = 'absent') as absent")
            ->selectRaw('count(*) as records')
            ->selectRaw('coalesce(sum(worked_minutes),0) as worked_minutes')
            ->selectRaw('coalesce(sum(late_minutes),0) as late_minutes')
            ->selectRaw('coalesce(sum(overtime_minutes),0) as overtime_minutes')
            ->groupBy('employee_id')
            ->get()
            ->map(fn ($row) => [
                'employee_id' => (string) $row->employee_id,
                'records' => (int) $row->records,
                'present' => (int) $row->present,
                'late' => (int) $row->late,
                'absent' => (int) $row->absent,
                'worked_minutes' => (int) $row->worked_minutes,
                'late_minutes' => (int) $row->late_minutes,
                'overtime_minutes' => (int) $row->overtime_minutes,
            ])
            ->all();
    }
}
