<?php

namespace App\Modules\Tasks\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Employees\Models\Employee;
use App\Modules\Identity\Models\User;
use App\Modules\Tasks\Enums\ProjectMembershipRole;
use App\Modules\Tasks\Models\Project;
use App\Modules\Tasks\Models\ProjectMembership;
use App\Modules\Tasks\Support\ProjectAuthorizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Project membership governance (D1, §5). Add/remove/role changes require
 * governance authority (owner / projects.manage) — a project-local manager
 * membership alone can NEVER manage membership (prevents local privilege
 * escalation). The canonical owner is projects.owner_employee_id and is never a
 * membership row.
 */
class ProjectMembershipService
{
    public function __construct(
        private readonly ProjectAuthorizer $authorizer,
        private readonly AuditLogger $audit,
    ) {}

    public function add(User $actor, Project $project, string $employeeId, ProjectMembershipRole $role): ProjectMembership
    {
        $this->assertGovern($actor, $project);
        $this->assertSameTenantEmployee($employeeId);

        if ((string) $project->owner_employee_id === $employeeId) {
            $this->fail(__('tasks.member_is_owner'));
        }

        return DB::transaction(function () use ($actor, $project, $employeeId, $role) {
            $membership = ProjectMembership::query()->updateOrCreate(
                ['project_id' => $project->getKey(), 'employee_id' => $employeeId],
                ['role' => $role, 'added_by_user_id' => (string) $actor->getKey(), 'created_at' => now()],
            );

            $this->audit->log('tasks.project_member_added', [
                'actor' => $actor,
                'subject' => $project,
                'metadata' => ['employee_id' => $employeeId, 'role' => $role->value],
            ]);

            return $membership;
        });
    }

    public function changeRole(User $actor, Project $project, string $employeeId, ProjectMembershipRole $role): ProjectMembership
    {
        $this->assertGovern($actor, $project);

        return DB::transaction(function () use ($actor, $project, $employeeId, $role) {
            $membership = ProjectMembership::query()
                ->where('project_id', $project->getKey())
                ->where('employee_id', $employeeId)
                ->firstOrFail();
            $membership->forceFill(['role' => $role])->save();

            $this->audit->log('tasks.project_member_role_changed', [
                'actor' => $actor,
                'subject' => $project,
                'metadata' => ['employee_id' => $employeeId, 'role' => $role->value],
            ]);

            return $membership;
        });
    }

    public function remove(User $actor, Project $project, string $employeeId): void
    {
        $this->assertGovern($actor, $project);

        DB::transaction(function () use ($actor, $project, $employeeId) {
            ProjectMembership::query()
                ->where('project_id', $project->getKey())
                ->where('employee_id', $employeeId)
                ->delete();

            $this->audit->log('tasks.project_member_removed', [
                'actor' => $actor,
                'subject' => $project,
                'metadata' => ['employee_id' => $employeeId],
            ]);
        });
    }

    private function assertGovern(User $actor, Project $project): void
    {
        if ($project->isArchived()) {
            $this->fail(__('tasks.project_archived_readonly'));
        }
        if (! $this->authorizer->canGovern($actor, $project)) {
            $this->fail(__('tasks.project_governance_forbidden'));
        }
    }

    private function assertSameTenantEmployee(string $employeeId): void
    {
        if (! Employee::query()->whereKey($employeeId)->exists()) {
            $this->fail(__('tasks.employee_invalid'));
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['membership' => [$message]]);
    }
}
