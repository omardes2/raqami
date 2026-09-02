<?php

namespace App\Modules\Tasks\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Tasks\Enums\TaskActivityType;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskStatus;
use App\Modules\Tasks\Support\TaskActivityRecorder;
use App\Modules\Tasks\Support\TaskAuthorizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Project Kanban ordering (D8/§23). Manual rank applies ONLY to top-level project
 * tasks, within a project_id + status_id column. Sparse bigint ranks with
 * midpoint inserts; on gap exhaustion, a synchronous transactional
 * renormalization of that single column runs — no background job, no floats.
 */
class TaskBoardService
{
    private const GAP = 65536;

    public function __construct(
        private readonly TaskAuthorizer $authorizer,
        private readonly TaskActivityRecorder $activity,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Move a card into $statusId, positioned between the given neighbours (either
     * may be null for column ends). Applies status-change semantics if the column
     * changed. Optimistic version guard.
     */
    public function move(
        User $actor,
        Task $task,
        string $statusId,
        ?string $afterTaskId = null,
        ?string $beforeTaskId = null,
        ?int $expectedVersion = null,
    ): Task {
        return DB::transaction(function () use ($actor, $task, $statusId, $afterTaskId, $beforeTaskId, $expectedVersion) {
            $task = Task::query()->lockForUpdate()->findOrFail($task->getKey());

            if ($expectedVersion !== null && (int) $task->version !== $expectedVersion) {
                throw new ConflictHttpException(__('tasks.stale'));
            }
            if ($task->project_id === null || $task->parent_task_id !== null) {
                $this->fail(__('tasks.board_rank_project_only'));
            }
            if ($task->isArchived()) {
                $this->fail(__('tasks.task_archived_readonly'));
            }
            if (! $this->authorizer->canChangeStatus($actor, $task)) {
                $this->fail(__('tasks.task_forbidden'));
            }

            $status = TaskStatus::query()->find($statusId);
            if ($status === null || (! $status->active && (string) $task->status_id !== $statusId)) {
                $this->fail(__('tasks.status_invalid'));
            }

            $statusChanged = (string) $task->status_id !== $statusId;
            if ($statusChanged) {
                $wasCompleted = $task->completed_at !== null;
                $task->status_id = $status->getKey();
                if ($status->category->isCompleted()) {
                    $task->completed_at ??= CarbonImmutable::now()->utc();
                } else {
                    $task->completed_at = null;
                }
                $this->activity->record(TaskActivityType::TaskStatusChanged, $actor, $task->getKey(), $task->project_id, [
                    'status_id' => $status->getKey(), 'category' => $status->category->value,
                ]);
                if ($status->category->isCompleted() && ! $wasCompleted) {
                    $this->activity->record(TaskActivityType::TaskCompleted, $actor, $task->getKey(), $task->project_id, []);
                } elseif (! $status->category->isCompleted() && $wasCompleted) {
                    $this->activity->record(TaskActivityType::TaskReopened, $actor, $task->getKey(), $task->project_id, []);
                }
            }

            $task->board_rank = $this->computeRank($task, $status->getKey(), $afterTaskId, $beforeTaskId);
            $task->version = (int) $task->version + 1;
            $task->save();

            $this->audit->log('tasks.task_ranked', [
                'actor' => $actor, 'subject' => $task,
                'metadata' => ['status_id' => $status->getKey(), 'board_rank' => $task->board_rank],
            ]);

            return $task->fresh();
        });
    }

    private function computeRank(Task $task, string $statusId, ?string $afterTaskId, ?string $beforeTaskId): int
    {
        $after = $this->neighborRank($task, $statusId, $afterTaskId);
        $before = $this->neighborRank($task, $statusId, $beforeTaskId);

        if ($after !== null && $before !== null) {
            if ($before - $after > 1) {
                return intdiv($after + $before, 2);
            }
            // No gap → renormalize the column and retry once.
            $this->renormalize($task->project_id, $statusId);

            return $this->computeRankAfterRenorm($task, $statusId, $afterTaskId, $beforeTaskId);
        }
        if ($after !== null) {
            return $after + self::GAP;
        }
        if ($before !== null) {
            if ($before > self::GAP) {
                return intdiv($before, 2);
            }
            $this->renormalize($task->project_id, $statusId);

            return $this->computeRankAfterRenorm($task, $statusId, $afterTaskId, $beforeTaskId);
        }

        // Empty column.
        return self::GAP;
    }

    private function computeRankAfterRenorm(Task $task, string $statusId, ?string $afterTaskId, ?string $beforeTaskId): int
    {
        $after = $this->neighborRank($task, $statusId, $afterTaskId);
        $before = $this->neighborRank($task, $statusId, $beforeTaskId);
        if ($after !== null && $before !== null) {
            return intdiv($after + $before, 2);
        }
        if ($after !== null) {
            return $after + self::GAP;
        }
        if ($before !== null) {
            return intdiv($before, 2);
        }

        return self::GAP;
    }

    private function neighborRank(Task $task, string $statusId, ?string $neighborId): ?int
    {
        if ($neighborId === null) {
            return null;
        }
        $rank = Task::query()
            ->where('project_id', $task->project_id)
            ->where('status_id', $statusId)
            ->whereNull('parent_task_id')
            ->whereKey($neighborId)
            ->value('board_rank');

        return $rank === null ? null : (int) $rank;
    }

    private function renormalize(string $projectId, string $statusId): void
    {
        $tasks = Task::query()
            ->where('project_id', $projectId)
            ->where('status_id', $statusId)
            ->whereNull('parent_task_id')
            ->orderBy('board_rank')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($tasks as $i => $t) {
            $t->forceFill(['board_rank' => ($i + 1) * self::GAP])->save();
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['board' => [$message]]);
    }
}
