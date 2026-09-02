<?php

namespace App\Modules\Tasks\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Employees\Models\Employee;
use App\Modules\Identity\Models\User;
use App\Modules\Tasks\Enums\ProjectStatus;
use App\Modules\Tasks\Enums\ProjectVisibility;
use App\Modules\Tasks\Enums\ScopeType;
use App\Modules\Tasks\Models\Project;
use App\Modules\Tasks\Support\ProjectAuthorizer;
use App\Modules\Tasks\Support\TaskScopeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Project lifecycle. Scope target is same-tenant validated; creation requires the
 * actor to hold projects.create covering the scope. Governance changes (scope,
 * visibility, owner) and archive/unarchive require governance authority
 * (owner / projects.manage), never project-local manager membership.
 */
class ProjectService
{
    public function __construct(
        private readonly ProjectAuthorizer $authorizer,
        private readonly TaskScopeResolver $scopes,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{name:string, code?:?string, description?:?string,
     *     visibility?:string, scope_type:string, scope_id?:?string,
     *     owner_employee_id?:?string, start_on?:?string, due_on?:?string}  $data
     */
    public function create(User $actor, array $data): Project
    {
        $scopeType = ScopeType::from($data['scope_type']);
        $scopeId = $scopeType === ScopeType::Company ? null : ($data['scope_id'] ?? null);
        $this->assertScopeTarget($scopeType, $scopeId);

        if (! $this->scopes->actorCoversScope($actor, 'projects.create', $scopeType, $scopeId)) {
            $this->fail(__('tasks.project_scope_forbidden'));
        }

        $ownerEmployeeId = $data['owner_employee_id'] ?? null;
        if ($ownerEmployeeId !== null) {
            $this->assertSameTenantEmployee($ownerEmployeeId);
        }

        return DB::transaction(function () use ($actor, $data, $scopeType, $scopeId, $ownerEmployeeId) {
            $project = Project::query()->create([
                'name' => $data['name'],
                'code' => $data['code'] ?? null,
                'description' => $data['description'] ?? null,
                'status' => ProjectStatus::Active,
                'visibility' => ProjectVisibility::from($data['visibility'] ?? 'scoped'),
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'owner_employee_id' => $ownerEmployeeId,
                'start_on' => $data['start_on'] ?? null,
                'due_on' => $data['due_on'] ?? null,
                'created_by_user_id' => (string) $actor->getKey(),
                'version' => 1,
            ]);

            $this->audit->log('tasks.project_created', [
                'actor' => $actor,
                'subject' => $project,
                'metadata' => ['scope_type' => $scopeType->value, 'visibility' => $project->visibility->value],
            ]);

            return $project->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, Project $project, array $data, ?int $expectedVersion = null): Project
    {
        return DB::transaction(function () use ($actor, $project, $data, $expectedVersion) {
            $project = Project::query()->lockForUpdate()->findOrFail($project->getKey());
            $this->assertVersion($project, $expectedVersion);
            $this->assertNotArchived($project);

            // Governance-only fields.
            $governanceKeys = ['visibility', 'scope_type', 'scope_id', 'owner_employee_id'];
            $touchesGovernance = array_intersect($governanceKeys, array_keys($data)) !== [];
            if ($touchesGovernance && ! $this->authorizer->canGovern($actor, $project)) {
                $this->fail(__('tasks.project_governance_forbidden'));
            }
            if (! $touchesGovernance && ! $this->authorizer->canManageProjectTasks($actor, $project)) {
                $this->fail(__('tasks.project_forbidden'));
            }

            if (array_key_exists('scope_type', $data)) {
                $scopeType = ScopeType::from($data['scope_type']);
                $scopeId = $scopeType === ScopeType::Company ? null : ($data['scope_id'] ?? null);
                $this->assertScopeTarget($scopeType, $scopeId);
                $project->scope_type = $scopeType;
                $project->scope_id = $scopeId;
            }
            if (array_key_exists('visibility', $data)) {
                $project->visibility = ProjectVisibility::from($data['visibility']);
            }
            if (array_key_exists('owner_employee_id', $data)) {
                if ($data['owner_employee_id'] !== null) {
                    $this->assertSameTenantEmployee($data['owner_employee_id']);
                }
                $project->owner_employee_id = $data['owner_employee_id'];
            }
            foreach (['name', 'code', 'description', 'start_on', 'due_on'] as $field) {
                if (array_key_exists($field, $data)) {
                    $project->{$field} = $data[$field];
                }
            }
            if (array_key_exists('status', $data)) {
                $this->applyStatus($project, ProjectStatus::from($data['status']));
            }

            $project->version = (int) $project->version + 1;
            $project->save();

            $this->audit->log('tasks.project_updated', [
                'actor' => $actor,
                'subject' => $project,
                'metadata' => ['fields' => array_keys($data), 'governance' => $touchesGovernance],
            ]);

            return $project->fresh();
        });
    }

    public function archive(User $actor, Project $project, ?int $expectedVersion = null): Project
    {
        return DB::transaction(function () use ($actor, $project, $expectedVersion) {
            $project = Project::query()->lockForUpdate()->findOrFail($project->getKey());
            $this->assertVersion($project, $expectedVersion);
            if (! $this->authorizer->canGovern($actor, $project)) {
                $this->fail(__('tasks.project_governance_forbidden'));
            }
            if ($project->isArchived()) {
                $this->fail(__('tasks.project_already_archived'));
            }

            $project->forceFill([
                'archived_at' => CarbonImmutable::now()->utc(),
                'version' => (int) $project->version + 1,
            ])->save();

            $this->audit->log('tasks.project_archived', ['actor' => $actor, 'subject' => $project]);

            return $project->fresh();
        });
    }

    public function unarchive(User $actor, Project $project, ?int $expectedVersion = null): Project
    {
        return DB::transaction(function () use ($actor, $project, $expectedVersion) {
            $project = Project::query()->lockForUpdate()->findOrFail($project->getKey());
            $this->assertVersion($project, $expectedVersion);
            if (! $this->authorizer->canGovern($actor, $project)) {
                $this->fail(__('tasks.project_governance_forbidden'));
            }
            if (! $project->isArchived()) {
                $this->fail(__('tasks.project_not_archived'));
            }

            $project->forceFill([
                'archived_at' => null,
                'version' => (int) $project->version + 1,
            ])->save();

            $this->audit->log('tasks.project_unarchived', ['actor' => $actor, 'subject' => $project]);

            return $project->fresh();
        });
    }

    private function applyStatus(Project $project, ProjectStatus $status): void
    {
        $project->status = $status;
        if ($status === ProjectStatus::Completed) {
            $project->completed_at ??= CarbonImmutable::now()->utc();
        } else {
            $project->completed_at = null;
        }
    }

    private function assertScopeTarget(ScopeType $scopeType, ?string $scopeId): void
    {
        if ($scopeType === ScopeType::Company) {
            if ($scopeId !== null) {
                $this->fail(__('tasks.scope_target_invalid'));
            }

            return;
        }
        if ($scopeId === null) {
            $this->fail(__('tasks.scope_target_invalid'));
        }
        $table = match ($scopeType) {
            ScopeType::Branch => 'branches',
            ScopeType::Department => 'departments',
            ScopeType::Team => 'teams',
            default => null,
        };
        // Same-tenant existence (RLS already scopes; be explicit).
        if ($table === null || ! DB::table($table)->where('id', $scopeId)->exists()) {
            $this->fail(__('tasks.scope_target_invalid'));
        }
    }

    private function assertSameTenantEmployee(string $employeeId): void
    {
        if (! Employee::query()->whereKey($employeeId)->exists()) {
            $this->fail(__('tasks.employee_invalid'));
        }
    }

    private function assertVersion(Project $project, ?int $expectedVersion): void
    {
        if ($expectedVersion !== null && (int) $project->version !== $expectedVersion) {
            throw new ConflictHttpException(__('tasks.stale'));
        }
    }

    private function assertNotArchived(Project $project): void
    {
        if ($project->isArchived()) {
            $this->fail(__('tasks.project_archived_readonly'));
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['project' => [$message]]);
    }
}
