<?php

namespace App\Modules\Tasks\Support;

use App\Modules\Authorization\Services\AccessService;
use App\Modules\Employees\Models\Employee;
use App\Modules\Identity\Models\User;
use App\Modules\Tasks\Enums\ProjectVisibility;
use App\Modules\Tasks\Enums\ScopeType;
use App\Modules\Tasks\Models\Project;
use App\Modules\Tasks\Models\ProjectMembership;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskAssignee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The single authority for intra-tenant task/project visibility (Correction B).
 * EVERY consumer — detail, list, My Tasks, board, comments, mentions, watchers,
 * attachments, activity, workload, reports — goes through here; no controller
 * writes ad-hoc visibility SQL. RLS guards the tenant boundary; this guards
 * authorization inside the tenant. Authorization is by actual RBAC grants +
 * project-local ACL, never by role display name.
 *
 * Visibility layers (most permissive first):
 *   - company task authority (company-wide tasks/projects view|manage) → all;
 *   - the actor is an assignee of the task;
 *   - the actor created the task (standalone especially);
 *   - project task → the project is visible (members_only ignores org scope);
 *   - standalone task → the actor's scoped tasks.view grant covers the task scope.
 */
class TaskVisibilityResolver
{
    public function __construct(
        private readonly AccessService $access,
        private readonly TaskScopeResolver $scopes,
    ) {}

    // ---- Single-row authority (used for detail/actions; drives scope-safe 404) ----

    public function canViewProject(User $user, Project $project): bool
    {
        if ($this->hasCompanyTaskAuthority($user)) {
            return true;
        }
        $employeeId = $this->actorEmployeeId($user);
        if ($employeeId !== null && (string) $project->owner_employee_id === $employeeId) {
            return true;
        }
        if ($employeeId !== null && $this->isProjectMember($project->getKey(), $employeeId)) {
            return true;
        }
        // members_only never revealed by ordinary org scope.
        if ($project->visibility === ProjectVisibility::MembersOnly) {
            return false;
        }

        return $this->actorCoversProjectScope($user, $project);
    }

    public function canViewTask(User $user, Task $task): bool
    {
        if ($this->hasCompanyTaskAuthority($user)) {
            return true;
        }
        $employeeId = $this->actorEmployeeId($user);
        if ($employeeId !== null && $this->isAssignee($task->getKey(), $employeeId)) {
            return true;
        }
        if ((string) $task->created_by_user_id === (string) $user->getKey()) {
            return true;
        }

        if ($task->project_id !== null) {
            $project = $task->relationLoaded('project') ? $task->project : Project::query()->find($task->project_id);

            return $project !== null && $this->canViewProject($user, $project);
        }

        // Standalone: actor's scoped tasks.view grant must cover the stable scope.
        return $task->scope_type !== null
            && $this->scopes->actorCoversScope($user, 'tasks.view', $task->scope_type, $task->scope_id);
    }

    // ---- Query builders (lists/reports/workload — no leakage, no N+1 by id) ----

    /** All tasks the user may see, as a constrained builder. */
    public function visibleTaskQuery(User $user): Builder
    {
        $query = Task::query();
        if ($this->hasCompanyTaskAuthority($user)) {
            return $query;
        }

        $employeeId = $this->actorEmployeeId($user);
        $viewScopes = $this->viewScopeSets($user);
        $visibleProjectIds = $this->visibleProjectIds($user, $employeeId, $viewScopes);

        return $query->where(function (Builder $w) use ($user, $employeeId, $viewScopes, $visibleProjectIds) {
            // Assigned to me.
            if ($employeeId !== null) {
                $w->orWhereIn('id', TaskAssignee::query()->where('employee_id', $employeeId)->select('task_id'));
            }
            // Created by me.
            $w->orWhere('created_by_user_id', $user->getKey());
            // Standalone tasks within my covered scope.
            $w->orWhere(function (Builder $s) use ($viewScopes) {
                $s->whereNull('project_id')->where(fn (Builder $x) => $this->applyScopePredicate($x, $viewScopes));
            });
            // Tasks inside a project I can see.
            if ($visibleProjectIds->isNotEmpty()) {
                $w->orWhereIn('project_id', $visibleProjectIds->all());
            }
        });
    }

    /** All projects the user may see, as a constrained builder. */
    public function visibleProjectQuery(User $user): Builder
    {
        $query = Project::query();
        if ($this->hasCompanyTaskAuthority($user)) {
            return $query;
        }
        $employeeId = $this->actorEmployeeId($user);

        return $query->whereIn('id', $this->visibleProjectIds($user, $employeeId, $this->viewScopeSets($user)));
    }

    // ---- Internals ----

    public function hasCompanyTaskAuthority(User $user): bool
    {
        foreach (['tasks.view', 'tasks.manage', 'projects.view', 'projects.manage'] as $perm) {
            if ($this->access->hasCompanyWide($user, $perm)) {
                return true;
            }
        }

        return false;
    }

    public function actorEmployeeId(User $user): ?string
    {
        $id = Employee::query()->where('user_id', $user->getKey())->value('id');

        return $id === null ? null : (string) $id;
    }

    private function isProjectMember(string $projectId, string $employeeId): bool
    {
        return ProjectMembership::query()
            ->where('project_id', $projectId)->where('employee_id', $employeeId)->exists();
    }

    private function isAssignee(string $taskId, string $employeeId): bool
    {
        return TaskAssignee::query()
            ->where('task_id', $taskId)->where('employee_id', $employeeId)->exists();
    }

    private function actorCoversProjectScope(User $user, Project $project): bool
    {
        if ($project->scope_type === null) {
            return false;
        }
        foreach (['tasks.view', 'projects.view'] as $perm) {
            if ($this->scopes->actorCoversScope($user, $perm, $project->scope_type, $project->scope_id)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Merged, expanded VIEW scope id sets from tasks.view + projects.view grants.
     *
     * @return array{company:bool, branch:Collection<int,string>, department:Collection<int,string>, team:Collection<int,string>}
     */
    private function viewScopeSets(User $user): array
    {
        $grants = collect();
        foreach (['tasks.view', 'projects.view'] as $perm) {
            $grants = $grants->merge($this->access->scopeGrantsFor($user, $perm));
        }
        $company = $grants->contains(fn (array $g) => $g['scope_type'] === 'company');
        $branch = $grants->where('scope_type', 'branch')->pluck('scope_id')->filter()->unique()->values();
        $team = $grants->where('scope_type', 'team')->pluck('scope_id')->filter()->unique()->values();
        $deptRoots = $grants->where('scope_type', 'department')->pluck('scope_id')->filter();
        $department = $deptRoots->isEmpty() ? collect() : $this->scopes->departmentSubtreeIds($deptRoots->all());

        return ['company' => $company, 'branch' => $branch, 'department' => $department, 'team' => $team];
    }

    /** @param array{company:bool, branch:Collection, department:Collection, team:Collection} $sets */
    private function applyScopePredicate(Builder $query, array $sets): Builder
    {
        $matched = false;
        $query->where(function (Builder $w) use ($sets, &$matched) {
            if ($sets['company']) {
                $w->orWhere('scope_type', ScopeType::Company->value);
                $matched = true;
            }
            if ($sets['branch']->isNotEmpty()) {
                $w->orWhere(fn (Builder $x) => $x->where('scope_type', ScopeType::Branch->value)->whereIn('scope_id', $sets['branch']->all()));
                $matched = true;
            }
            if ($sets['department']->isNotEmpty()) {
                $w->orWhere(fn (Builder $x) => $x->where('scope_type', ScopeType::Department->value)->whereIn('scope_id', $sets['department']->all()));
                $matched = true;
            }
            if ($sets['team']->isNotEmpty()) {
                $w->orWhere(fn (Builder $x) => $x->where('scope_type', ScopeType::Team->value)->whereIn('scope_id', $sets['team']->all()));
                $matched = true;
            }
            if (! $matched) {
                $w->whereRaw('1 = 0');
            }
        });

        return $query;
    }

    /**
     * Project ids the (non-company-authority) user may see: owned + member +
     * scoped-and-covered. members_only projects appear only via owner/membership.
     *
     * @param  array{company:bool, branch:Collection, department:Collection, team:Collection}  $viewScopes
     * @return Collection<int, string>
     */
    private function visibleProjectIds(User $user, ?string $employeeId, array $viewScopes): Collection
    {
        $ids = collect();

        if ($employeeId !== null) {
            $ids = $ids
                ->merge(Project::query()->where('owner_employee_id', $employeeId)->pluck('id'))
                ->merge(ProjectMembership::query()->where('employee_id', $employeeId)->pluck('project_id'));
        }

        // scoped projects whose scope the actor covers.
        $scoped = Project::query()
            ->where('visibility', ProjectVisibility::Scoped->value)
            ->where(fn (Builder $x) => $this->applyScopePredicate($x, $viewScopes))
            ->pluck('id');

        return $ids->merge($scoped)->map(fn ($id) => (string) $id)->unique()->values();
    }
}
