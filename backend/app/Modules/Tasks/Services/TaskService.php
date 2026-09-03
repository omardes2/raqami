<?php

namespace App\Modules\Tasks\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Employees\Models\Employee;
use App\Modules\Identity\Models\User;
use App\Modules\Tasks\Enums\DueType;
use App\Modules\Tasks\Enums\ScopeType;
use App\Modules\Tasks\Enums\TaskActivityType;
use App\Modules\Tasks\Enums\TaskPriority;
use App\Modules\Tasks\Models\Project;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskStatus;
use App\Modules\Tasks\Support\ProjectAuthorizer;
use App\Modules\Tasks\Support\TaskActivityRecorder;
use App\Modules\Tasks\Support\TaskAuthorizer;
use App\Modules\Tasks\Support\TaskScopeResolver;
use App\Modules\Tasks\Support\TaskVisibilityResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Task lifecycle: standalone (stable scope) or project (inherited scope) tasks,
 * one-level subtasks, server-authoritative status/completion/overdue, project-
 * only Kanban ranking, optimistic version and creator-scoped idempotency.
 */
class TaskService
{
    private const RANK_GAP = 65536;

    public function __construct(
        private readonly TaskAuthorizer $authorizer,
        private readonly ProjectAuthorizer $projects,
        private readonly TaskScopeResolver $scopes,
        private readonly TaskWatcherService $watchers,
        private readonly TaskActivityRecorder $activity,
        private readonly AuditLogger $audit,
        private readonly TaskVisibilityResolver $visibility,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, array $data): Task
    {
        $employeeId = $this->actorEmployeeId($actor);
        $fingerprintFields = $this->creationFingerprintFields($data);

        // Idempotency (creator-scoped) — same key + same payload reuses; different payload → 409.
        if (! empty($data['client_request_id'])) {
            $existing = Task::query()
                ->where('created_by_user_id', (string) $actor->getKey())
                ->where('client_request_id', $data['client_request_id'])
                ->first();
            if ($existing !== null) {
                if ((string) $existing->client_request_hash !== $this->fingerprint($fingerprintFields)) {
                    throw new ConflictHttpException(__('tasks.idempotency_conflict'));
                }

                return $existing;
            }
        }

        $project = null;
        if (! empty($data['project_id'])) {
            $project = Project::query()->find($data['project_id']);
            // Resolve the project through the visibility boundary BEFORE creation:
            // a non-member holding ordinary scoped tasks.create that covers the
            // project scope must NOT be able to create inside a members_only
            // project. An invisible project is indistinguishable from a missing
            // one (scope-safe 404) so hidden projects never leak their existence.
            if ($project === null || ! $this->visibility->canViewProject($actor, $project)) {
                throw new NotFoundHttpException(__('tasks.task_forbidden'));
            }
            if (! $this->projects->canCreateProjectTask($actor, $project)) {
                $this->fail(__('tasks.project_closed_for_tasks'));
            }
            $scopeType = null;
            $scopeId = null;
        } else {
            // Standalone: stable scope required; actor must hold tasks.create over it.
            if (empty($data['scope_type'])) {
                $this->fail(__('tasks.task_scope_required'));
            }
            $scopeType = ScopeType::from($data['scope_type']);
            $scopeId = $scopeType === ScopeType::Company ? null : ($data['scope_id'] ?? null);
            $this->assertScopeTarget($scopeType, $scopeId);
            if (! $this->scopes->actorCoversScope($actor, 'tasks.create', $scopeType, $scopeId)) {
                $this->fail(__('tasks.task_create_forbidden'));
            }
        }

        $parent = $this->resolveParent($data['parent_task_id'] ?? null, $project, $scopeType ?? null, $scopeId ?? null);
        $status = $this->resolveStatus($data['status_id'] ?? null);
        [$dueType, $dueOn, $dueAt, $dueTz] = $this->normalizeDue($data);

        try {
            return $this->insertTask($actor, $data, $project, $scopeType ?? null, $scopeId ?? null, $parent, $status, $dueType, $dueOn, $dueAt, $dueTz, $employeeId, $fingerprintFields);
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent request with the same client_request_id won the insert
            // race (the partial-unique idempotency index fired). Re-read and reuse
            // it if the payload matches; a different payload is a real 409; an
            // unrelated unique violation re-throws unchanged. Never a raw 500.
            if (empty($data['client_request_id'])) {
                throw $e;
            }
            $existing = Task::query()
                ->where('created_by_user_id', (string) $actor->getKey())
                ->where('client_request_id', $data['client_request_id'])
                ->first();
            if ($existing === null) {
                throw $e;
            }
            if ((string) $existing->client_request_hash !== $this->fingerprint($fingerprintFields)) {
                throw new ConflictHttpException(__('tasks.idempotency_conflict'));
            }

            return $existing;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $fingerprintFields
     */
    private function insertTask(User $actor, array $data, ?Project $project, ?ScopeType $scopeType, ?string $scopeId, ?Task $parent, TaskStatus $status, DueType $dueType, ?string $dueOn, ?CarbonImmutable $dueAt, ?string $dueTz, ?string $employeeId, array $fingerprintFields): Task
    {
        return DB::transaction(function () use ($actor, $data, $project, $scopeType, $scopeId, $parent, $status, $dueType, $dueOn, $dueAt, $dueTz, $employeeId, $fingerprintFields) {
            $rank = ($project !== null && $parent === null)
                ? $this->nextRank($project->getKey(), $status->getKey())
                : null;

            $task = Task::query()->create([
                'project_id' => $project?->getKey(),
                'parent_task_id' => $parent?->getKey(),
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'status_id' => $status->getKey(),
                'priority' => TaskPriority::from($data['priority'] ?? 'normal'),
                'scope_type' => $project !== null ? null : $scopeType,
                'scope_id' => $project !== null ? null : $scopeId,
                'due_type' => $dueType,
                'due_on' => $dueOn,
                'due_at' => $dueAt,
                'due_timezone' => $dueTz,
                'start_on' => $data['start_on'] ?? null,
                'estimated_minutes' => $data['estimated_minutes'] ?? null,
                'board_rank' => $rank,
                'created_by_user_id' => (string) $actor->getKey(),
                'created_by_employee_id' => $employeeId,
                'client_request_id' => $data['client_request_id'] ?? null,
                'client_request_hash' => empty($data['client_request_id']) ? null : $this->fingerprint($fingerprintFields),
                'version' => 1,
                'completed_at' => $status->category->isCompleted() ? CarbonImmutable::now()->utc() : null,
            ]);

            $this->watchers->autoWatchCreator($task);
            $this->audit->log('tasks.task_created', [
                'actor' => $actor, 'subject' => $task,
                'metadata' => ['project_id' => $task->project_id, 'scope_type' => $task->scope_type?->value],
            ]);
            $this->activity->record(TaskActivityType::TaskCreated, $actor, $task->getKey(), $task->project_id, [
                'title' => $task->title, 'status_id' => $task->status_id,
            ]);

            return $task->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, Task $task, array $data, ?int $expectedVersion = null): Task
    {
        return DB::transaction(function () use ($actor, $task, $data, $expectedVersion) {
            $task = Task::query()->lockForUpdate()->findOrFail($task->getKey());
            $this->assertVersion($task, $expectedVersion);
            $this->assertNotArchived($task);
            if (! $this->authorizer->canManage($actor, $task)) {
                $this->fail(__('tasks.task_forbidden'));
            }

            foreach (['title', 'description', 'start_on', 'estimated_minutes'] as $field) {
                if (array_key_exists($field, $data)) {
                    $task->{$field} = $data[$field];
                }
            }
            if (array_key_exists('priority', $data)) {
                $old = $task->priority->value;
                $task->priority = TaskPriority::from($data['priority']);
                if ($old !== $task->priority->value) {
                    $this->activity->record(TaskActivityType::TaskPriorityChanged, $actor, $task->getKey(), $task->project_id, [
                        'from' => $old, 'to' => $task->priority->value,
                    ]);
                }
            }
            if (array_key_exists('due_type', $data)) {
                [$dueType, $dueOn, $dueAt, $dueTz] = $this->normalizeDue($data);
                $task->forceFill(['due_type' => $dueType, 'due_on' => $dueOn, 'due_at' => $dueAt, 'due_timezone' => $dueTz]);
                $this->activity->record(TaskActivityType::TaskDueChanged, $actor, $task->getKey(), $task->project_id, [
                    'due_type' => $dueType->value,
                ]);
            }

            $task->version = (int) $task->version + 1;
            $task->save();

            $this->audit->log('tasks.task_updated', [
                'actor' => $actor, 'subject' => $task, 'metadata' => ['fields' => array_keys($data)],
            ]);
            $this->activity->record(TaskActivityType::TaskUpdated, $actor, $task->getKey(), $task->project_id, []);

            return $task->fresh();
        });
    }

    public function changeStatus(User $actor, Task $task, string $statusId, ?int $expectedVersion = null): Task
    {
        return DB::transaction(function () use ($actor, $task, $statusId, $expectedVersion) {
            $task = Task::query()->lockForUpdate()->findOrFail($task->getKey());
            $this->assertVersion($task, $expectedVersion);
            $this->assertNotArchived($task);
            if (! $this->authorizer->canChangeStatus($actor, $task)) {
                $this->fail(__('tasks.task_forbidden'));
            }

            $status = TaskStatus::query()->find($statusId);
            if ($status === null) {
                $this->fail(__('tasks.status_invalid'));
            }
            if (! $status->active && (string) $task->status_id !== $statusId) {
                $this->fail(__('tasks.status_inactive'));
            }

            $wasCompleted = $task->completed_at !== null;
            $task->status_id = $status->getKey();
            if ($status->category->isCompleted()) {
                $task->completed_at ??= CarbonImmutable::now()->utc();
            } else {
                $task->completed_at = null; // done|cancelled leaving done, or cancelled: no completion
            }
            // Reposition into the destination column for a project top-level task.
            if ($task->project_id !== null && $task->parent_task_id === null) {
                $task->board_rank = $this->nextRank($task->project_id, $status->getKey());
            }
            $task->version = (int) $task->version + 1;
            $task->save();

            $this->activity->record(TaskActivityType::TaskStatusChanged, $actor, $task->getKey(), $task->project_id, [
                'status_id' => $status->getKey(), 'category' => $status->category->value,
            ]);
            if ($status->category->isCompleted() && ! $wasCompleted) {
                $this->activity->record(TaskActivityType::TaskCompleted, $actor, $task->getKey(), $task->project_id, []);
            } elseif (! $status->category->isCompleted() && $wasCompleted) {
                $this->activity->record(TaskActivityType::TaskReopened, $actor, $task->getKey(), $task->project_id, []);
            }
            $this->audit->log('tasks.task_status_changed', [
                'actor' => $actor, 'subject' => $task, 'metadata' => ['status_id' => $status->getKey()],
            ]);

            return $task->fresh();
        });
    }

    public function archive(User $actor, Task $task, ?int $expectedVersion = null): Task
    {
        return $this->toggleArchive($actor, $task, true, $expectedVersion);
    }

    public function unarchive(User $actor, Task $task, ?int $expectedVersion = null): Task
    {
        return $this->toggleArchive($actor, $task, false, $expectedVersion);
    }

    private function toggleArchive(User $actor, Task $task, bool $archive, ?int $expectedVersion): Task
    {
        return DB::transaction(function () use ($actor, $task, $archive, $expectedVersion) {
            $task = Task::query()->lockForUpdate()->findOrFail($task->getKey());
            $this->assertVersion($task, $expectedVersion);
            if (! $this->authorizer->canManage($actor, $task)) {
                $this->fail(__('tasks.task_forbidden'));
            }
            $task->forceFill([
                'archived_at' => $archive ? CarbonImmutable::now()->utc() : null,
                'version' => (int) $task->version + 1,
            ])->save();

            $this->activity->record(
                $archive ? TaskActivityType::TaskArchived : TaskActivityType::TaskUnarchived,
                $actor, $task->getKey(), $task->project_id, [],
            );
            $this->audit->log($archive ? 'tasks.task_archived' : 'tasks.task_unarchived', [
                'actor' => $actor, 'subject' => $task,
            ]);

            return $task->fresh();
        });
    }

    // ---- helpers ----

    private function resolveParent(?string $parentId, ?Project $project, ?ScopeType $scopeType, ?string $scopeId): ?Task
    {
        if ($parentId === null) {
            return null;
        }
        $parent = Task::query()->find($parentId);
        if ($parent === null) {
            $this->fail(__('tasks.task_forbidden'));
        }
        if ($parent->parent_task_id !== null) {
            $this->fail(__('tasks.subtask_depth')); // one level only
        }
        if ($parent->isArchived()) {
            $this->fail(__('tasks.subtask_parent_archived'));
        }
        // Same project / same standalone scope as the parent.
        if ($project !== null) {
            if ((string) $parent->project_id !== (string) $project->getKey()) {
                $this->fail(__('tasks.subtask_scope_mismatch'));
            }
        } else {
            if ($parent->project_id !== null
                || $parent->scope_type !== $scopeType
                || (string) $parent->scope_id !== (string) $scopeId) {
                $this->fail(__('tasks.subtask_scope_mismatch'));
            }
        }

        return $parent;
    }

    private function resolveStatus(?string $statusId): TaskStatus
    {
        if ($statusId !== null) {
            $status = TaskStatus::query()->find($statusId);
            if ($status === null) {
                $this->fail(__('tasks.status_invalid'));
            }
            if (! $status->active) {
                $this->fail(__('tasks.status_inactive'));
            }

            return $status;
        }
        $default = TaskStatus::query()->where('is_default', true)->where('active', true)->first();
        if ($default === null) {
            $this->fail(__('tasks.status_invalid'));
        }

        return $default;
    }

    /** @return array{0:DueType,1:?string,2:?CarbonImmutable,3:?string} */
    private function normalizeDue(array $data): array
    {
        $type = DueType::from($data['due_type'] ?? 'none');
        if ($type === DueType::None) {
            return [DueType::None, null, null, null];
        }
        $tz = $data['due_timezone'] ?? null;
        if ($tz === null || ! in_array($tz, timezone_identifiers_list(), true)) {
            $this->fail(__('tasks.scope_target_invalid'));
        }
        if ($type === DueType::Date) {
            if (empty($data['due_on'])) {
                $this->fail(__('tasks.scope_target_invalid'));
            }

            return [DueType::Date, CarbonImmutable::parse($data['due_on'])->toDateString(), null, $tz];
        }
        // datetime → store instant as UTC; keep display timezone snapshot.
        if (empty($data['due_at'])) {
            $this->fail(__('tasks.scope_target_invalid'));
        }

        return [DueType::Datetime, null, CarbonImmutable::parse($data['due_at'])->utc(), $tz];
    }

    private function nextRank(string $projectId, string $statusId): int
    {
        $max = (int) Task::query()
            ->where('project_id', $projectId)
            ->where('status_id', $statusId)
            ->whereNull('parent_task_id')
            ->max('board_rank');

        return $max + self::RANK_GAP;
    }

    /** @return array<string, mixed> */
    private function creationFingerprintFields(array $data): array
    {
        $keys = ['project_id', 'parent_task_id', 'title', 'description', 'scope_type', 'scope_id',
            'status_id', 'priority', 'due_type', 'due_on', 'due_at', 'due_timezone', 'start_on', 'estimated_minutes'];
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $data[$k] ?? null;
        }

        return $out;
    }

    private function fingerprint(array $fields): string
    {
        ksort($fields);

        return hash('sha256', json_encode($fields, JSON_UNESCAPED_UNICODE));
    }

    private function actorEmployeeId(User $actor): ?string
    {
        $id = Employee::query()->where('user_id', $actor->getKey())->value('id');

        return $id === null ? null : (string) $id;
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
        if ($table === null || ! DB::table($table)->where('id', $scopeId)->exists()) {
            $this->fail(__('tasks.scope_target_invalid'));
        }
    }

    private function assertVersion(Task $task, ?int $expectedVersion): void
    {
        if ($expectedVersion !== null && (int) $task->version !== $expectedVersion) {
            throw new ConflictHttpException(__('tasks.stale'));
        }
    }

    private function assertNotArchived(Task $task): void
    {
        if ($task->isArchived()) {
            $this->fail(__('tasks.task_archived_readonly'));
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['task' => [$message]]);
    }
}
