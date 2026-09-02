<?php

namespace App\Modules\Tasks\Support;

use App\Modules\Authorization\Services\AccessService;
use App\Modules\Identity\Models\User;
use App\Modules\Tasks\Models\Project;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskAssignee;

/**
 * Task ACTION capability (distinct from visibility). Management edits require a
 * manager path (company/scoped tasks.manage or project-local task authority);
 * status/checklist participation is additionally granted to assignees. Assignment
 * authority follows §7. Project-local authority stays bounded to the project.
 */
class TaskAuthorizer
{
    public function __construct(
        private readonly AccessService $access,
        private readonly TaskScopeResolver $scopes,
        private readonly ProjectAuthorizer $projects,
        private readonly TaskVisibilityResolver $visibility,
    ) {}

    public function isAssignee(User $user, Task $task): bool
    {
        $employeeId = $this->visibility->actorEmployeeId($user);

        return $employeeId !== null && TaskAssignee::query()
            ->where('task_id', $task->getKey())->where('employee_id', $employeeId)->exists();
    }

    /** Full management of a task's fields (not participation). */
    public function canManage(User $user, Task $task): bool
    {
        if ($this->access->hasCompanyWide($user, 'tasks.manage')) {
            return true;
        }
        if ($task->project_id !== null) {
            $project = $this->project($task);

            return $project !== null && $this->projects->canManageProjectTasks($user, $project);
        }

        return $task->scope_type !== null
            && $this->scopes->actorCoversScope($user, 'tasks.manage', $task->scope_type, $task->scope_id);
    }

    /** Change status: assignee participation OR management. */
    public function canChangeStatus(User $user, Task $task): bool
    {
        return $this->isAssignee($user, $task) || $this->canManage($user, $task);
    }

    /** Participate on an assigned task (checklist toggles). */
    public function canParticipate(User $user, Task $task): bool
    {
        return $this->isAssignee($user, $task) || $this->canManage($user, $task);
    }

    /** Assignment authority over a task (target legitimacy handled separately). */
    public function canAssign(User $user, Task $task): bool
    {
        if ($this->access->hasCompanyWide($user, 'tasks.assign')) {
            return true;
        }
        if ($task->project_id !== null) {
            $project = $this->project($task);
            if ($project === null) {
                return false;
            }
            if ($this->projects->canManageProjectTasks($user, $project)) {
                return true;
            }

            return $project->scope_type !== null
                && $this->scopes->actorCoversScope($user, 'tasks.assign', $project->scope_type, $project->scope_id);
        }

        return $task->scope_type !== null
            && $this->scopes->actorCoversScope($user, 'tasks.assign', $task->scope_type, $task->scope_id);
    }

    /** Whether this assign authority is a broad (scoped/company) grant vs project-local only. */
    public function hasBroadAssignGrant(User $user, Task $task): bool
    {
        if ($this->access->hasCompanyWide($user, 'tasks.assign')) {
            return true;
        }
        $scopeType = $task->scope_type;
        $scopeId = $task->scope_id;
        if ($task->project_id !== null) {
            $project = $this->project($task);
            $scopeType = $project?->scope_type;
            $scopeId = $project?->scope_id;
        }

        return $scopeType !== null && $this->scopes->actorCoversScope($user, 'tasks.assign', $scopeType, $scopeId);
    }

    private function project(Task $task): ?Project
    {
        if ($task->relationLoaded('project') && $task->project !== null) {
            return $task->project;
        }

        return Project::query()->find($task->project_id);
    }
}
