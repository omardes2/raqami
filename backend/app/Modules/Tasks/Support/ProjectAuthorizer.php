<?php

namespace App\Modules\Tasks\Support;

use App\Modules\Authorization\Services\AccessService;
use App\Modules\Identity\Models\User;
use App\Modules\Tasks\Enums\ProjectMembershipRole;
use App\Modules\Tasks\Enums\ProjectStatus;
use App\Modules\Tasks\Models\Project;
use App\Modules\Tasks\Models\ProjectMembership;

/**
 * Project-local authorization intersected with global RBAC (§7, §34, §37).
 *
 *  - GOVERNANCE (membership, owner transfer, visibility/scope change,
 *    archive/unarchive) requires the canonical owner OR company/scoped
 *    projects.manage — NEVER project-local manager membership alone.
 *  - PROJECT TASK WORK (create/manage/assign tasks inside the project) is granted
 *    by the owner, a project-local manager membership, OR the matching scoped
 *    global permission. A plain member never creates arbitrary project tasks.
 *
 * Project-local authority is bounded to that single project and never leaks to
 * tenant task settings, other projects, or company reports.
 */
class ProjectAuthorizer
{
    public function __construct(
        private readonly AccessService $access,
        private readonly TaskScopeResolver $scopes,
        private readonly TaskVisibilityResolver $visibility,
    ) {}

    public function isOwner(User $user, Project $project): bool
    {
        $employeeId = $this->visibility->actorEmployeeId($user);

        return $employeeId !== null && (string) $project->owner_employee_id === $employeeId;
    }

    public function membershipRole(User $user, Project $project): ?ProjectMembershipRole
    {
        $employeeId = $this->visibility->actorEmployeeId($user);
        if ($employeeId === null) {
            return null;
        }

        return ProjectMembership::query()
            ->where('project_id', $project->getKey())
            ->where('employee_id', $employeeId)
            ->value('role');
    }

    /** Governance authority (owner or projects.manage covering the scope). */
    public function canGovern(User $user, Project $project): bool
    {
        if ($this->isOwner($user, $project)) {
            return true;
        }
        if ($this->access->hasCompanyWide($user, 'projects.manage')) {
            return true;
        }

        return $project->scope_type !== null
            && $this->scopes->actorCoversScope($user, 'projects.manage', $project->scope_type, $project->scope_id);
    }

    /** May create/manage/assign tasks inside this project (not governance). */
    public function canManageProjectTasks(User $user, Project $project): bool
    {
        if ($this->canGovern($user, $project)) {
            return true;
        }
        if ($this->membershipRole($user, $project) === ProjectMembershipRole::Manager) {
            return true;
        }

        return $project->scope_type !== null
            && $this->scopes->actorCoversScope($user, 'tasks.manage', $project->scope_type, $project->scope_id);
    }

    /** May create a task inside this project (project must be open). */
    public function canCreateProjectTask(User $user, Project $project): bool
    {
        if ($project->isArchived() || $project->status === ProjectStatus::Completed) {
            return false;
        }
        if ($this->canManageProjectTasks($user, $project)) {
            return true;
        }

        return $project->scope_type !== null
            && $this->scopes->actorCoversScope($user, 'tasks.create', $project->scope_type, $project->scope_id);
    }
}
