<?php

namespace App\Modules\Tasks\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Employees\Models\Employee;
use App\Modules\Identity\Models\User;
use App\Modules\Tasks\Enums\ScopeType;
use App\Modules\Tasks\Enums\TaskActivityType;
use App\Modules\Tasks\Models\Project;
use App\Modules\Tasks\Models\ProjectMembership;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskAssignee;
use App\Modules\Tasks\Support\TaskActivityRecorder;
use App\Modules\Tasks\Support\TaskAuthorizer;
use App\Modules\Tasks\Support\TaskScopeResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Task assignment (Correction D, §7). Multiple assignees, at most one primary
 * (DB-enforced). A broad (company/scoped) tasks.assign actor may assign employees
 * within the task's scope; a project-local owner/manager without a broad grant
 * may assign only existing project participants — bring a cross-org employee in
 * as a project member first. New assignment to an inactive employee is rejected;
 * existing historical assignments are preserved.
 */
class TaskAssignmentService
{
    public function __construct(
        private readonly TaskAuthorizer $authorizer,
        private readonly TaskScopeResolver $scopes,
        private readonly TaskWatcherService $watchers,
        private readonly TaskActivityRecorder $activity,
        private readonly AuditLogger $audit,
    ) {}

    public function assign(User $actor, Task $task, string $employeeId, bool $isPrimary = false): TaskAssignee
    {
        if ($task->isArchived()) {
            $this->fail(__('tasks.task_archived_readonly'));
        }
        if (! $this->authorizer->canAssign($actor, $task)) {
            $this->fail(__('tasks.assign_forbidden'));
        }

        $employee = Employee::query()->find($employeeId);
        if ($employee === null) {
            $this->fail(__('tasks.employee_invalid'));
        }
        if ($employee->employment_status !== 'active') {
            $this->fail(__('tasks.assignee_inactive'));
        }
        $this->assertLegitimateTarget($actor, $task, $employee);

        return DB::transaction(function () use ($actor, $task, $employee, $isPrimary) {
            $locked = Task::query()->lockForUpdate()->findOrFail($task->getKey());

            if ($isPrimary) {
                TaskAssignee::query()->where('task_id', $locked->getKey())
                    ->where('is_primary', true)->update(['is_primary' => false]);
            }

            $assignee = TaskAssignee::query()->updateOrCreate(
                ['task_id' => $locked->getKey(), 'employee_id' => $employee->getKey()],
                ['is_primary' => $isPrimary, 'assigned_by_user_id' => (string) $actor->getKey(), 'assigned_at' => now()],
            );

            $locked->forceFill(['version' => (int) $locked->version + 1])->save();

            $this->watchers->autoWatchAssignee($locked, $employee);
            $this->audit->log('tasks.task_assigned', [
                'actor' => $actor, 'subject' => $locked,
                'metadata' => ['employee_id' => $employee->getKey(), 'is_primary' => $isPrimary],
            ]);
            $this->activity->record(TaskActivityType::TaskAssigned, $actor, $locked->getKey(), $locked->project_id, [
                'employee_id' => $employee->getKey(), 'is_primary' => $isPrimary,
            ]);

            return $assignee->fresh();
        });
    }

    public function unassign(User $actor, Task $task, string $employeeId): void
    {
        if ($task->isArchived()) {
            $this->fail(__('tasks.task_archived_readonly'));
        }
        if (! $this->authorizer->canAssign($actor, $task)) {
            $this->fail(__('tasks.assign_forbidden'));
        }

        DB::transaction(function () use ($actor, $task, $employeeId) {
            $locked = Task::query()->lockForUpdate()->findOrFail($task->getKey());
            $deleted = TaskAssignee::query()
                ->where('task_id', $locked->getKey())->where('employee_id', $employeeId)->delete();
            if ($deleted === 0) {
                return;
            }
            $locked->forceFill(['version' => (int) $locked->version + 1])->save();

            // NB (§15): a former assignee's watcher is intentionally NOT removed here
            // (it never grants visibility; Sprint 8 re-checks visibility on delivery).
            $this->audit->log('tasks.task_unassigned', [
                'actor' => $actor, 'subject' => $locked, 'metadata' => ['employee_id' => $employeeId],
            ]);
            $this->activity->record(TaskActivityType::TaskUnassigned, $actor, $locked->getKey(), $locked->project_id, [
                'employee_id' => $employeeId,
            ]);
        });
    }

    /** Verify the employee is a legitimate assignment target for this task. */
    private function assertLegitimateTarget(User $actor, Task $task, Employee $employee): void
    {
        $broad = $this->authorizer->hasBroadAssignGrant($actor, $task);

        if ($task->project_id !== null) {
            $project = Project::query()->find($task->project_id);
            if ($project === null) {
                $this->fail(__('tasks.assign_forbidden'));
            }
            $isParticipant = (string) $project->owner_employee_id === (string) $employee->getKey()
                || ProjectMembership::query()->where('project_id', $project->getKey())
                    ->where('employee_id', $employee->getKey())->exists();

            if ($isParticipant) {
                return; // participants are always legitimate targets
            }
            if (! $broad) {
                // Project-local authority may only assign existing participants.
                $this->fail(__('tasks.assignee_not_project_participant'));
            }
            // Broad grant: employee must also fall within the project scope.
            if ($project->scope_type === null
                || ! $this->scopes->employeeInScope($employee, $project->scope_type, $project->scope_id)) {
                $this->fail(__('tasks.assignee_scope_forbidden'));
            }

            return;
        }

        // Standalone: employee must belong to the task's stable scope.
        $scopeType = $task->scope_type ?? ScopeType::Company;
        if (! $this->scopes->employeeInScope($employee, $scopeType, $task->scope_id)) {
            $this->fail(__('tasks.assignee_scope_forbidden'));
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['assignee' => [$message]]);
    }
}
