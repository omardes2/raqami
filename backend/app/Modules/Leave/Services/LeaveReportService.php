<?php

namespace App\Modules\Leave\Services;

use App\Modules\Employees\Models\Employee;
use App\Modules\Leave\Models\LeaveBalance;
use App\Modules\Leave\Models\LeaveRequest;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Neutral leave reporting in MINUTES (UI converts to display days). No monetary
 * liability — balance exposure is time only. All queries respect tenant RLS and
 * are further scope-constrained by the caller (EmployeeScopeResolver).
 */
class LeaveReportService
{
    /**
     * Employee balance summary across leave types for the period containing $date.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function employeeBalances(Employee $employee, ?CarbonImmutable $date = null): Collection
    {
        $date ??= CarbonImmutable::now();

        return LeaveBalance::query()
            ->where('employee_id', $employee->getKey())
            ->whereHas('period', fn ($q) => $q
                ->whereDate('starts_on', '<=', $date->toDateString())
                ->whereDate('ends_on', '>=', $date->toDateString()))
            ->with('period')
            ->get()
            ->map(fn (LeaveBalance $b) => [
                'leave_type_id' => (string) $b->leave_type_id,
                'entitlement_period_id' => (string) $b->entitlement_period_id,
                'granted_minutes' => (int) $b->granted_minutes,
                'accrued_minutes' => (int) $b->accrued_minutes,
                'carried_minutes' => (int) $b->carried_minutes,
                'adjusted_minutes' => (int) $b->adjusted_minutes,
                'used_minutes' => (int) $b->used_minutes,
                'reserved_minutes' => (int) $b->reserved_minutes,
                'expired_minutes' => (int) $b->expired_minutes,
                'available_minutes' => (int) $b->available_minutes,
            ]);
    }

    /**
     * Management summary: leave minutes grouped by type over a date range, for a
     * pre-scoped set of employees (the caller applies EmployeeScopeResolver).
     *
     * @return array<string, mixed>
     */
    public function summary(Builder $scopedEmployees, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $employeeIds = $scopedEmployees->pluck('id');

        $rows = LeaveRequest::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('status', 'approved')
            ->whereDate('starts_on', '<=', $to->toDateString())
            ->whereDate('ends_on', '>=', $from->toDateString())
            ->selectRaw('leave_type_id, COUNT(*) as requests, COALESCE(SUM(requested_consumption_minutes),0) as consumption_minutes')
            ->groupBy('leave_type_id')
            ->get();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'by_type' => $rows->map(fn ($r) => [
                'leave_type_id' => (string) $r->leave_type_id,
                'requests' => (int) $r->requests,
                'consumption_minutes' => (int) $r->consumption_minutes,
            ])->all(),
            'total_consumption_minutes' => (int) $rows->sum('consumption_minutes'),
        ];
    }

    /**
     * Upcoming approved leave for a scoped set of employees (team calendar feed).
     * Privacy: no reason, no attachment, no medical detail — dates + type only.
     *
     * @return array<int, array<string, mixed>>
     */
    public function calendar(Builder $scopedEmployees, CarbonImmutable $from, CarbonImmutable $to, bool $includePending = false): array
    {
        $employeeIds = $scopedEmployees->pluck('id');
        $statuses = $includePending ? ['approved', 'cancellation_pending', 'pending'] : ['approved', 'cancellation_pending'];

        return LeaveRequest::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereIn('status', $statuses)
            ->whereDate('starts_on', '<=', $to->toDateString())
            ->whereDate('ends_on', '>=', $from->toDateString())
            ->orderBy('starts_on')
            ->get(['id', 'employee_id', 'leave_type_id', 'starts_on', 'ends_on', 'request_kind', 'status'])
            ->map(fn (LeaveRequest $r) => [
                'id' => (string) $r->getKey(),
                'employee_id' => (string) $r->employee_id,
                'leave_type_id' => (string) $r->leave_type_id,
                'starts_on' => $r->starts_on->toDateString(),
                'ends_on' => $r->ends_on->toDateString(),
                'request_kind' => $r->request_kind->value,
                'status' => $r->status->value,
            ])->all();
    }
}
