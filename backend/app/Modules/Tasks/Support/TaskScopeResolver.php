<?php

namespace App\Modules\Tasks\Support;

use App\Modules\Authorization\Services\AccessService;
use App\Modules\Employees\Models\Employee;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\TeamMembership;
use App\Modules\Tasks\Enums\ScopeType;
use Illuminate\Support\Collection;

/**
 * Organizational scope helpers for Tasks, reusing the ADR-015 grant model
 * (AccessService) and the same department-subtree semantics as
 * EmployeeScopeResolver. Two distinct questions:
 *
 *  - actorCoversScope(): does the actor hold $permission over a target scope
 *    (used to authorize creating a task/project in that scope)?
 *  - employeeInScope(): does an employee organizationally belong to a scope
 *    (used to validate assignees against a task's stable scope)?
 */
class TaskScopeResolver
{
    public function __construct(private readonly AccessService $access) {}

    /**
     * Whether the actor holds $permission at a scope that COVERS the target
     * (company grant covers all; department grant covers its whole subtree).
     */
    public function actorCoversScope(User $user, string $permission, ScopeType $scopeType, ?string $scopeId): bool
    {
        $grants = $this->access->scopeGrantsFor($user, $permission);
        if ($grants->isEmpty()) {
            return false;
        }
        // Company-wide grant covers every scope.
        if ($grants->contains(fn (array $g) => $g['scope_type'] === 'company')) {
            return true;
        }

        return match ($scopeType) {
            ScopeType::Company => false, // needs a company grant, handled above
            ScopeType::Branch => $grants->contains(fn (array $g) => $g['scope_type'] === 'branch' && $g['scope_id'] === $scopeId),
            ScopeType::Team => $grants->contains(fn (array $g) => $g['scope_type'] === 'team' && $g['scope_id'] === $scopeId),
            ScopeType::Department => $this->departmentGrantCovers($grants, $scopeId),
        };
    }

    /** Whether an employee organizationally belongs to the given scope. */
    public function employeeInScope(Employee $employee, ScopeType $scopeType, ?string $scopeId): bool
    {
        if ($employee->employment_status !== 'active') {
            // Kept for historical assignment preservation, never a new target.
            return false;
        }

        return match ($scopeType) {
            ScopeType::Company => true,
            ScopeType::Branch => (string) $employee->branch_id === (string) $scopeId,
            ScopeType::Department => $employee->department_id !== null
                && $this->departmentSubtreeIds([$scopeId])->contains((string) $employee->department_id),
            ScopeType::Team => TeamMembership::query()
                ->where('team_id', $scopeId)
                ->where('employee_id', $employee->getKey())
                ->exists(),
        };
    }

    /**
     * @param  Collection<int, array{scope_type:string, scope_id:?string}>  $grants
     */
    private function departmentGrantCovers(Collection $grants, ?string $targetDeptId): bool
    {
        $rootIds = $grants->where('scope_type', 'department')->pluck('scope_id')->filter();
        if ($rootIds->isEmpty() || $targetDeptId === null) {
            return false;
        }

        return $this->departmentSubtreeIds($rootIds->all())->contains((string) $targetDeptId);
    }

    /**
     * Expand department ids to include all descendants (self + subtree), matching
     * EmployeeScopeResolver semantics.
     *
     * @param  iterable<int, ?string>  $rootIds
     * @return Collection<int, string>
     */
    public function departmentSubtreeIds(iterable $rootIds): Collection
    {
        $roots = collect($rootIds)->filter()->map(fn ($id) => (string) $id)->unique();
        if ($roots->isEmpty()) {
            return collect();
        }

        $childrenByParent = Department::query()
            ->select(['id', 'parent_department_id'])
            ->get()
            ->groupBy('parent_department_id');

        $out = collect();
        $stack = $roots->all();
        while ($stack !== []) {
            $current = (string) array_pop($stack);
            if ($out->contains($current)) {
                continue;
            }
            $out->push($current);
            foreach ($childrenByParent->get($current, collect()) as $child) {
                $stack[] = (string) $child->id;
            }
        }

        return $out->values();
    }
}
