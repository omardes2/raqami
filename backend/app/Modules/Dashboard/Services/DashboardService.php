<?php

namespace App\Modules\Dashboard\Services;

use App\Modules\Attendance\Services\AttendanceReportService;
use App\Modules\Authorization\Services\AccessService;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Support\EmployeeScopeResolver;
use App\Modules\Identity\Models\User;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Support\PayrollAuthorizationService;
use App\Modules\Tasks\Services\TaskReportService;
use App\Modules\Tenancy\Services\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Company dashboard as a permission-scoped COMPOSITE read model (Sprint 8A Phase 2).
 * Each card is independently authorized and independently scoped by the same audited
 * resolvers the module reports use; an unauthorized card is OMITTED (never returned
 * as a zero, a flag, or a 403 over the whole dashboard). No new table/cache/snapshot;
 * "today" is the tenant-local date; no money is placed on the generic dashboard.
 */
class DashboardService
{
    public function __construct(
        private readonly AccessService $access,
        private readonly PayrollAuthorizationService $payrollAuthz,
        private readonly EmployeeScopeResolver $scope,
        private readonly AttendanceReportService $attendance,
        private readonly TaskReportService $tasks,
        private readonly TenantContext $context,
    ) {}

    /** @return array<string, mixed> only the cards the caller may lawfully see */
    public function company(User $user): array
    {
        $today = CarbonImmutable::now($this->timezone())->toDateString();
        $data = [];

        // Organization headcount (scoped) — employees.reports.view.
        if ($this->access->hasAtAnyScope($user, 'employees.reports.view')) {
            $data['organization'] = [
                'active_employees' => (int) $this->scopedEmployees($user, 'employees.reports.view')
                    ->where('employment_status', 'active')->count(),
            ];
        }

        // Attendance today (scoped, settled logical day) — attendance.reports.view.
        if ($this->access->hasAtAnyScope($user, 'attendance.reports.view')) {
            $breakdown = $this->attendance->statusBreakdown($user, ['from' => $today, 'to' => $today]);
            $data['attendance'] = [
                'date' => $today,
                'present' => (int) ($breakdown['present'] ?? 0) + (int) ($breakdown['late'] ?? 0),
                'absent' => (int) ($breakdown['absent'] ?? 0),
                'on_leave' => (int) ($breakdown['on_leave'] ?? 0),
            ];
        }

        // Leave pending (scoped) — leave.reports.view. No reason/medical data.
        if ($this->access->hasAtAnyScope($user, 'leave.reports.view')) {
            $ids = $this->scopedEmployees($user, 'leave.reports.view')->select('id');
            $data['leave'] = [
                'pending_requests' => (int) LeaveRequest::query()
                    ->whereIn('employee_id', $ids)
                    ->where('status', 'pending')
                    ->count(),
            ];
        }

        // Tasks overdue (visibility-safe) — tasks.reports.view.
        if ($this->access->hasAtAnyScope($user, 'tasks.reports.view')) {
            $data['tasks'] = [
                'overdue' => $this->tasks->overdueCount($user),
            ];
        }

        // Payroll status (COMPANY-WIDE only) — payroll.reports.view. Status only,
        // no money on the generic dashboard.
        if ($this->payrollAuthz->has($user, 'payroll.reports.view')) {
            $latestPeriod = PayrollPeriod::query()->orderByDesc('period_start')->first();
            $latestRun = PayrollRun::query()->orderByDesc('created_at')->first();
            $data['payroll'] = [
                'latest_period_label' => $latestPeriod?->label,
                'latest_period_status' => $latestPeriod?->status instanceof PayrollPeriodStatus
                    ? $latestPeriod->status->value
                    : ($latestPeriod?->status !== null ? (string) $latestPeriod->status : null),
                'latest_run_status' => $latestRun?->status instanceof \BackedEnum
                    ? $latestRun->status->value
                    : ($latestRun?->status !== null ? (string) $latestRun->status : null),
            ];
        }

        return $data;
    }

    public function timezone(): string
    {
        return $this->context->tenant()?->timezone ?: config('app.timezone', 'UTC');
    }

    private function scopedEmployees(User $user, string $permission): Builder
    {
        return $this->scope->applyScope(Employee::query(), $user, $permission);
    }
}
