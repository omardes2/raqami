<?php

namespace App\Modules\Employees\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Employees\Models\Employee;
use App\Modules\Organization\Models\Branch;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\JobTitle;
use App\Modules\Organization\Models\Team;
use App\Modules\Organization\Models\TeamMembership;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Controlled organizational changes for an employee: branch/department/job-title
 * transfers, manager changes, and team membership updates. Each meaningful
 * change validates tenant isolation + target validity, updates the employee,
 * writes an HR history event and a security audit entry — all in one
 * transaction (no partial state).
 */
class EmployeeTransferService
{
    public function __construct(
        private readonly EmployeeHistoryRecorder $history,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{branch_id?:?string, department_id?:?string, job_title_id?:?string,
     *     direct_manager_employee_id?:?string, team_ids?:array, effective_date?:string}  $changes
     */
    public function apply(Employee $employee, array $changes, mixed $actor = null): Employee
    {
        return DB::transaction(function () use ($employee, $changes, $actor) {
            $effective = $changes['effective_date'] ?? now()->toDateString();

            if (array_key_exists('branch_id', $changes)) {
                $this->changeField($employee, 'branch_id', $changes['branch_id'], Branch::class, 'branch_changed', $effective, $actor);
            }
            if (array_key_exists('department_id', $changes)) {
                $this->changeField($employee, 'department_id', $changes['department_id'], Department::class, 'department_changed', $effective, $actor);
            }
            if (array_key_exists('job_title_id', $changes)) {
                $this->changeField($employee, 'job_title_id', $changes['job_title_id'], JobTitle::class, 'job_title_changed', $effective, $actor);
            }
            if (array_key_exists('direct_manager_employee_id', $changes)) {
                $this->changeManager($employee, $changes['direct_manager_employee_id'], $effective, $actor);
            }
            if (array_key_exists('team_ids', $changes)) {
                $this->syncTeams($employee, (array) $changes['team_ids'], $effective, $actor);
            }

            return $employee->refresh();
        });
    }

    private function changeField(Employee $employee, string $field, ?string $value, string $modelClass, string $eventType, string $effective, mixed $actor): void
    {
        if ($value !== null && ! $modelClass::query()->whereKey($value)->exists()) {
            throw ValidationException::withMessages([$field => ['The selected value is invalid for this tenant.']]);
        }

        $from = $employee->{$field};
        if ($from === $value) {
            return;
        }

        $employee->{$field} = $value;
        $employee->save();

        $this->history->record($employee, $eventType, ['from' => $from, 'to' => $value], $effective);
        $this->audit->log('employee.transferred', [
            'actor' => $actor,
            'subject' => $employee,
            'metadata' => ['change' => $eventType, 'from' => $from, 'to' => $value],
        ]);
    }

    private function changeManager(Employee $employee, ?string $managerId, string $effective, mixed $actor): void
    {
        if ($managerId !== null) {
            if ($managerId === $employee->getKey()) {
                throw ValidationException::withMessages([
                    'direct_manager_employee_id' => ['An employee cannot manage themselves.'],
                ]);
            }
            $manager = Employee::query()->find($managerId);
            if ($manager === null) {
                throw ValidationException::withMessages([
                    'direct_manager_employee_id' => ['The selected manager is invalid.'],
                ]);
            }
            if ($this->wouldCreateCycle($employee, $manager)) {
                throw ValidationException::withMessages([
                    'direct_manager_employee_id' => ['This assignment would create a circular reporting relationship.'],
                ]);
            }
        }

        $from = $employee->direct_manager_employee_id;
        if ($from === $managerId) {
            return;
        }

        $employee->direct_manager_employee_id = $managerId;
        $employee->save();

        $this->history->record($employee, 'manager_changed', ['from' => $from, 'to' => $managerId], $effective);
        $this->audit->log('employee.manager_changed', [
            'actor' => $actor,
            'subject' => $employee,
            'metadata' => ['from' => $from, 'to' => $managerId],
        ]);
    }

    /** Walk up the prospective manager's chain; a cycle exists if we reach $employee. */
    private function wouldCreateCycle(Employee $employee, Employee $manager): bool
    {
        $cursor = $manager;
        $guard = 0;

        while ($cursor !== null && $guard++ < 1000) {
            if ($cursor->getKey() === $employee->getKey()) {
                return true;
            }
            $cursor = $cursor->direct_manager_employee_id
                ? Employee::query()->find($cursor->direct_manager_employee_id)
                : null;
        }

        return false;
    }

    private function syncTeams(Employee $employee, array $teamIds, string $effective, mixed $actor): void
    {
        $teamIds = array_values(array_unique(array_filter($teamIds)));

        // All target teams must belong to the active tenant (RLS-scoped lookup).
        $valid = Team::query()->whereKey($teamIds)->pluck('id')->all();
        if (count($valid) !== count($teamIds)) {
            throw ValidationException::withMessages(['team_ids' => ['One or more teams are invalid for this tenant.']]);
        }

        $before = $employee->teamMemberships()->pluck('team_id')->all();

        // Rebuild membership rows (BelongsToTenant stamps tenant_id on create).
        $employee->teamMemberships()->delete();
        foreach ($teamIds as $teamId) {
            TeamMembership::create(['team_id' => $teamId, 'employee_id' => $employee->getKey()]);
        }

        if (array_values($before) !== array_values($teamIds)) {
            $this->history->record($employee, 'team_changed', ['from' => $before, 'to' => $teamIds], $effective);
            $this->audit->log('employee.transferred', [
                'actor' => $actor,
                'subject' => $employee,
                'metadata' => ['change' => 'team_changed', 'from' => $before, 'to' => $teamIds],
            ]);
        }
    }
}
