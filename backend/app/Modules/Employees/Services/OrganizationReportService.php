<?php

namespace App\Modules\Employees\Services;

use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Support\EmployeeScopeResolver;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\TeamMembership;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-side organization / workforce reporting (Sprint 8A). Every aggregate is
 * built over the caller's authorized employee population via EmployeeScopeResolver
 * (permission employees.reports.view), so a scoped manager can never count outside
 * their slice; RLS keeps it in the tenant. Aggregates only — never salary, national
 * id, contact, medical, or any sensitive HR field. All counting is done in SQL.
 */
class OrganizationReportService
{
    public function __construct(private readonly EmployeeScopeResolver $scope) {}

    /** Scoped, non-soft-deleted employee population the caller may report on. */
    private function scopedEmployees(User $user): Builder
    {
        return $this->scope->applyScope(Employee::query(), $user, 'employees.reports.view');
    }

    /**
     * Headcount summary: total, active, and breakdowns by employment_status,
     * branch, department, and team. Counts are over current (non-archived) rows.
     *
     * @return array<string, mixed>
     */
    public function summary(User $user): array
    {
        $total = (int) $this->scopedEmployees($user)->count();
        $active = (int) $this->scopedEmployees($user)->where('employment_status', 'active')->count();

        return [
            'total' => $total,
            'active' => $active,
            'inactive' => $total - $active,
            'by_employment_status' => $this->countBy($user, 'employment_status'),
            'by_branch' => $this->countBy($user, 'branch_id'),
            'by_department' => $this->countBy($user, 'department_id'),
            'by_team' => $this->countByTeam($user),
        ];
    }

    /**
     * Grouped count over a direct employee column. Returns [{key, count}], with a
     * null key rendered as the literal "unassigned" so branch/department gaps are
     * visible without leaking anything.
     *
     * @return array<int, array{key:?string, count:int}>
     */
    private function countBy(User $user, string $column): array
    {
        return $this->scopedEmployees($user)
            ->selectRaw("{$column} as k, count(*) as c")
            ->groupBy($column)
            ->get()
            ->map(fn ($row) => ['key' => $row->k !== null ? (string) $row->k : null, 'count' => (int) $row->c])
            ->all();
    }

    /**
     * Headcount by team via team_memberships, constrained to the scoped employee
     * set. An employee in multiple teams is counted once per team (membership),
     * which is the correct interpretation of "employees per team".
     *
     * @return array<int, array{key:string, count:int}>
     */
    private function countByTeam(User $user): array
    {
        $employeeIds = $this->scopedEmployees($user)->select('id');

        return TeamMembership::query()
            ->whereIn('employee_id', $employeeIds)
            ->selectRaw('team_id as k, count(distinct employee_id) as c')
            ->groupBy('team_id')
            ->get()
            ->map(fn ($row) => ['key' => (string) $row->k, 'count' => (int) $row->c])
            ->all();
    }

    /**
     * Turnover over a bounded window: joiners (hire_date in range) and leavers
     * (termination_date in range), grouped by calendar month (YYYY-MM). Source of
     * truth is the authoritative employees.hire_date / employees.termination_date
     * columns — NOT employee_history_events, which may be incomplete for imported
     * employees. Leaver counting includes rows whose termination_date falls in the
     * window regardless of soft-delete state, so terminations are not undercounted.
     *
     * @return array<string, mixed>
     */
    public function turnover(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();

        $joinersByMonth = $this->scopedEmployees($user)
            ->whereNotNull('hire_date')
            ->whereBetween('hire_date', [$fromDate, $toDate])
            ->selectRaw("to_char(hire_date, 'YYYY-MM') as m, count(*) as c")
            ->groupBy('m')
            ->pluck('c', 'm');

        $leaversByMonth = $this->scope->applyScope(Employee::withTrashed(), $user, 'employees.reports.view')
            ->whereNotNull('termination_date')
            ->whereBetween('termination_date', [$fromDate, $toDate])
            ->selectRaw("to_char(termination_date, 'YYYY-MM') as m, count(*) as c")
            ->groupBy('m')
            ->pluck('c', 'm');

        $months = collect($joinersByMonth->keys())
            ->merge($leaversByMonth->keys())
            ->unique()
            ->sort()
            ->values();

        // Data-quality signal (scoped, non-sensitive): current employees missing a
        // hire_date cannot appear as joiners, so surfacing the count lets a reader
        // judge how complete the joiner figure is. Termination has no equivalent
        // gap in the CURRENT population — a 'terminated' status change always stamps
        // termination_date (EmployeeService::changeStatus), and administratively
        // archived rows are soft-deleted and out of this population by design.
        $missingHireDate = (int) $this->scopedEmployees($user)->whereNull('hire_date')->count();

        return [
            'from' => $fromDate,
            'to' => $toDate,
            // Explicit, truthful semantics: these are RECORDED-date counts, not a
            // guarantee of every hire/departure. "leavers" counts employees whose
            // termination_date falls in the window; an administrative archive that
            // did not set termination_date is intentionally not counted here.
            'source' => 'employees.hire_date / employees.termination_date',
            'joiners_total' => (int) $joinersByMonth->sum(),
            'leavers_total' => (int) $leaversByMonth->sum(),
            'by_month' => $months->map(fn ($m) => [
                'month' => (string) $m,
                'joiners' => (int) ($joinersByMonth[$m] ?? 0),
                'leavers' => (int) ($leaversByMonth[$m] ?? 0),
            ])->all(),
            'data_quality' => [
                'missing_hire_date' => $missingHireDate,
            ],
        ];
    }
}
