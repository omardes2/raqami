<?php

namespace App\Modules\Leave\Services;

use App\Modules\Employees\Models\Employee;
use App\Modules\Leave\Enums\LeavePolicyStatus;
use App\Modules\Leave\Models\LeavePolicy;
use App\Modules\Leave\Models\LeavePolicyAssignment;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\TeamMembership;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The single deterministic authority for "which leave policy applies to this
 * employee for this leave type on this date". Precedence mirrors ScheduleResolver
 * exactly (most specific wins): employee > team > department (deepest ancestor
 * first) > branch > company. Within one level the tie-break is priority desc,
 * effective_from desc, created_at desc, id desc — never row order. All policy
 * selection flows through here; controllers/services never re-implement it.
 */
class LeavePolicyResolver
{
    /**
     * Resolve the effective policy for an employee + leave type on a local date,
     * or null when no active assignment covers them that day.
     */
    public function resolve(Employee $employee, string $leaveTypeId, CarbonInterface $date): ?LeavePolicy
    {
        $day = CarbonImmutable::parse($date)->toDateString();

        $candidates = $this->candidateAssignments($employee, $leaveTypeId, $day);
        if ($candidates->isEmpty()) {
            return null;
        }

        foreach ($this->orderedScopeSelectors($employee) as $selector) {
            $matches = $candidates->filter(
                fn (LeavePolicyAssignment $a) => $a->scope_type === $selector['type']
                    && $a->scope_id === $selector['id']
            );

            if ($matches->isNotEmpty()) {
                return $this->pickBest($matches)->policy;
            }
        }

        return null;
    }

    /**
     * @return Collection<int, LeavePolicyAssignment>
     */
    private function candidateAssignments(Employee $employee, string $leaveTypeId, string $day): Collection
    {
        $selectors = $this->orderedScopeSelectors($employee);

        return LeavePolicyAssignment::query()
            ->with('policy')
            ->where('leave_type_id', $leaveTypeId)
            ->whereHas('policy', fn ($q) => $q->where('status', LeavePolicyStatus::Active->value))
            ->whereDate('effective_from', '<=', $day)
            ->where(function ($q) use ($day) {
                $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $day);
            })
            ->where(function ($q) use ($selectors) {
                foreach ($selectors as $s) {
                    $q->orWhere(function ($inner) use ($s) {
                        $inner->where('scope_type', $s['type']);
                        $s['id'] === null
                            ? $inner->whereNull('scope_id')
                            : $inner->where('scope_id', $s['id']);
                    });
                }
            })
            ->get()
            // A policy whose own effective window excludes the day is not a candidate.
            ->filter(function (LeavePolicyAssignment $a) use ($day) {
                $policy = $a->policy;
                if ($policy === null) {
                    return false;
                }
                $from = $policy->effective_from?->toDateString();
                $until = $policy->effective_until?->toDateString();

                return ($from === null || $from <= $day) && ($until === null || $until >= $day);
            })
            ->values();
    }

    /**
     * Ordered scope selectors, most specific first. Department ancestors deepest
     * first. Mirrors ScheduleResolver so leave + schedule precedence match.
     *
     * @return array<int, array{type:string, id:?string}>
     */
    private function orderedScopeSelectors(Employee $employee): array
    {
        $selectors = [];
        $selectors[] = ['type' => 'employee', 'id' => (string) $employee->getKey()];

        foreach ($this->teamIds($employee) as $teamId) {
            $selectors[] = ['type' => 'team', 'id' => $teamId];
        }

        foreach ($this->departmentChain($employee->department_id) as $deptId) {
            $selectors[] = ['type' => 'department', 'id' => $deptId];
        }

        if ($employee->branch_id) {
            $selectors[] = ['type' => 'branch', 'id' => (string) $employee->branch_id];
        }

        $selectors[] = ['type' => 'company', 'id' => null];

        return $selectors;
    }

    /** @return array<int, string> */
    private function teamIds(Employee $employee): array
    {
        return TeamMembership::query()
            ->where('employee_id', $employee->getKey())
            ->pluck('team_id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    /**
     * The employee's department id followed by each ancestor, deepest first.
     *
     * @return array<int, string>
     */
    private function departmentChain(?string $departmentId): array
    {
        if ($departmentId === null) {
            return [];
        }

        $parents = Department::query()
            ->select(['id', 'parent_department_id'])
            ->get()
            ->keyBy('id');

        $chain = [];
        $current = $departmentId;
        while ($current !== null && ! in_array($current, $chain, true)) {
            $chain[] = (string) $current;
            $current = $parents->get($current)?->parent_department_id;
        }

        return $chain;
    }

    /**
     * Deterministic tie-break within one precedence level.
     *
     * @param  Collection<int, LeavePolicyAssignment>  $matches
     */
    private function pickBest(Collection $matches): LeavePolicyAssignment
    {
        return $matches->sort(function (LeavePolicyAssignment $a, LeavePolicyAssignment $b) {
            return [$b->priority, $b->effective_from->timestamp, $b->created_at->timestamp, $b->id]
                <=> [$a->priority, $a->effective_from->timestamp, $a->created_at->timestamp, $a->id];
        })->first();
    }
}
