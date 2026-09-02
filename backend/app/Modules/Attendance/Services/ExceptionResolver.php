<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Models\AttendanceException;
use App\Modules\Employees\Models\Employee;
use Carbon\CarbonImmutable;

/**
 * Resolves the authorized attendance exception (if any) that covers an employee
 * on a given work_date. An exception is what makes off-day / remote / field /
 * alternate-location / schedule-override attendance legitimate — check-in never
 * silently treats unscheduled work as ordinary attendance; it requires either a
 * policy that permits it or an active exception here.
 *
 * Only ACTIVE exceptions whose [effective_from, effective_until] window contains
 * the date are considered. When several overlap, the most specific wins by a
 * deterministic order: a mode-bearing exception first, then latest effective_from,
 * then latest created_at, then id — never row order. Read-only; tenant-scoped by
 * RLS + the global tenant scope, so it can never see another tenant's rows.
 */
class ExceptionResolver
{
    /**
     * The active exception covering this employee on this date, or null.
     */
    public function resolve(Employee $employee, CarbonImmutable $date): ?AttendanceException
    {
        $dateStr = $date->toDateString();

        $matches = AttendanceException::query()
            ->where('employee_id', $employee->getKey())
            ->where('status', 'active')
            ->whereDate('effective_from', '<=', $dateStr)
            ->where(function ($q) use ($dateStr) {
                $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $dateStr);
            })
            ->get();

        if ($matches->isEmpty()) {
            return null;
        }

        return $matches->sort(function (AttendanceException $a, AttendanceException $b) {
            return [
                $b->attendance_mode !== null ? 1 : 0,
                $b->effective_from->timestamp,
                $b->created_at->timestamp,
                $b->id,
            ] <=> [
                $a->attendance_mode !== null ? 1 : 0,
                $a->effective_from->timestamp,
                $a->created_at->timestamp,
                $a->id,
            ];
        })->first();
    }

    /** True when an active exception authorizes attendance on this date. */
    public function isAuthorized(Employee $employee, CarbonImmutable $date): bool
    {
        return $this->resolve($employee, $date) !== null;
    }
}
