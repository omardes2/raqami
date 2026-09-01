<?php

namespace App\Modules\Employees\Support;

use App\Modules\Authorization\Services\AccessService;
use App\Modules\Employees\Models\Employee;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\TeamMembership;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Translates a user's role-assignment scopes (ADR-015) into concrete access
 * over real Employee rows. This is the backend enforcement point that prevents
 * cross-scope IDOR — a branch manager can only ever see/act on employees in
 * their branch, regardless of what the UI or a crafted request asks for.
 *
 * Scope semantics:
 *  - company grant  → all employees in the tenant.
 *  - branch grant   → employees whose branch_id is in scope.
 *  - department grant → employees in that department OR any descendant department.
 *  - team grant     → employees who are members of that team.
 */
class EmployeeScopeResolver
{
    public function __construct(private readonly AccessService $access) {}

    /**
     * Constrain an employees query to what the user may access for $permission.
     * If the user has no grant for the permission, the query is forced empty.
     */
    public function applyScope(Builder $query, User $user, string $permission): Builder
    {
        $grants = $this->access->scopeGrantsFor($user, $permission);

        if ($grants->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        // Company-wide grant → tenant scope (already applied by RLS) is enough.
        if ($grants->contains(fn (array $g) => $g['scope_type'] === 'company')) {
            return $query;
        }

        [$branchIds, $departmentIds, $teamIds] = $this->scopeIds($grants);

        return $query->where(function (Builder $w) use ($branchIds, $departmentIds, $teamIds) {
            if ($branchIds->isNotEmpty()) {
                $w->orWhereIn('branch_id', $branchIds->all());
            }
            if ($departmentIds->isNotEmpty()) {
                $w->orWhereIn('department_id', $departmentIds->all());
            }
            if ($teamIds->isNotEmpty()) {
                $w->orWhereIn('id', TeamMembership::query()
                    ->whereIn('team_id', $teamIds->all())
                    ->select('employee_id'));
            }
            // If somehow no concrete ids resolved, deny.
            if ($branchIds->isEmpty() && $departmentIds->isEmpty() && $teamIds->isEmpty()) {
                $w->whereRaw('1 = 0');
            }
        });
    }

    /** Whether the user may access a specific employee for $permission. */
    public function canAccess(User $user, Employee $employee, string $permission): bool
    {
        return $this->applyScope(Employee::query()->whereKey($employee->getKey()), $user, $permission)->exists();
    }

    /**
     * Whether the user may view SENSITIVE fields of a specific employee — i.e.
     * holds employees.view_sensitive within an organizational scope that covers
     * this employee. This is scope-aware per employee (NB-1): view_sensitive in
     * Branch A does NOT expose an employee in Branch B, even when the viewer can
     * otherwise see Branch B through a separate employees.view grant.
     */
    public function canViewSensitive(User $user, Employee $employee): bool
    {
        return $this->canAccess($user, $employee, 'employees.view_sensitive');
    }

    /** Whether the user holds $permission company-wide (unscoped). */
    public function isCompanyWide(User $user, string $permission): bool
    {
        return $this->access->scopeGrantsFor($user, $permission)
            ->contains(fn (array $g) => $g['scope_type'] === 'company');
    }

    /**
     * Whether the user may place/keep an employee in the given branch/department
     * for $permission (used on create, where there is no existing row yet).
     */
    public function canPlaceInScope(User $user, string $permission, ?string $branchId, ?string $departmentId): bool
    {
        $grants = $this->access->scopeGrantsFor($user, $permission);

        if ($grants->isEmpty()) {
            return false;
        }
        if ($grants->contains(fn (array $g) => $g['scope_type'] === 'company')) {
            return true;
        }

        [$branchIds, $departmentIds] = $this->scopeIds($grants);

        return ($branchId !== null && $branchIds->contains($branchId))
            || ($departmentId !== null && $departmentIds->contains($departmentId));
    }

    /**
     * @param  Collection<int, array{scope_type:string, scope_id:?string}>  $grants
     * @return array{0:Collection,1:Collection,2:Collection} branch, department, team ids
     */
    private function scopeIds(Collection $grants): array
    {
        $branchIds = $grants->where('scope_type', 'branch')->pluck('scope_id')->filter()->unique()->values();
        $teamIds = $grants->where('scope_type', 'team')->pluck('scope_id')->filter()->unique()->values();

        $rootDeptIds = $grants->where('scope_type', 'department')->pluck('scope_id')->filter()->unique()->values();
        $departmentIds = $rootDeptIds->isEmpty()
            ? collect()
            : $this->expandDepartmentSubtree($rootDeptIds);

        return [$branchIds, $departmentIds, $teamIds];
    }

    /** Expand department scope ids to include all descendant departments. */
    private function expandDepartmentSubtree(Collection $rootIds): Collection
    {
        // Load the tenant's department tree once (parent -> children map).
        $childrenByParent = Department::query()
            ->select(['id', 'parent_department_id'])
            ->get()
            ->groupBy('parent_department_id');

        $result = collect($rootIds->all());
        $queue = collect($rootIds->all());

        while ($queue->isNotEmpty()) {
            $current = $queue->shift();
            foreach ($childrenByParent->get($current, collect()) as $child) {
                if (! $result->contains($child->id)) {
                    $result->push($child->id);
                    $queue->push($child->id);
                }
            }
        }

        return $result->unique()->values();
    }
}
