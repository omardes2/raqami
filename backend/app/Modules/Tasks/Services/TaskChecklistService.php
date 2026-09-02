<?php

namespace App\Modules\Tasks\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Tasks\Enums\TaskActivityType;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskChecklistItem;
use App\Modules\Tasks\Support\TaskActivityRecorder;
use App\Modules\Tasks\Support\TaskAuthorizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Task checklist items (§19). Assignee participation OR task management may edit.
 * A meaningful completion is recorded on the activity timeline.
 */
class TaskChecklistService
{
    public function __construct(
        private readonly TaskAuthorizer $authorizer,
        private readonly TaskActivityRecorder $activity,
        private readonly AuditLogger $audit,
    ) {}

    public function add(User $actor, Task $task, string $text): TaskChecklistItem
    {
        $this->assertParticipate($actor, $task);
        $max = (int) TaskChecklistItem::query()->where('task_id', $task->getKey())->max('sort_order');

        return TaskChecklistItem::query()->create([
            'task_id' => $task->getKey(),
            'text' => $text,
            'is_completed' => false,
            'sort_order' => $max + 10,
        ]);
    }

    public function toggle(User $actor, Task $task, TaskChecklistItem $item, bool $completed): TaskChecklistItem
    {
        $this->assertParticipate($actor, $task);

        return DB::transaction(function () use ($actor, $task, $item, $completed) {
            $item->forceFill([
                'is_completed' => $completed,
                'completed_by_user_id' => $completed ? (string) $actor->getKey() : null,
                'completed_at' => $completed ? CarbonImmutable::now()->utc() : null,
            ])->save();

            if ($completed) {
                $this->activity->record(TaskActivityType::ChecklistCompleted, $actor, $task->getKey(), $task->project_id, [
                    'checklist_item_id' => $item->getKey(),
                ]);
            }
            $this->audit->log('tasks.checklist_toggled', [
                'actor' => $actor, 'subject' => $task,
                'metadata' => ['checklist_item_id' => $item->getKey(), 'completed' => $completed],
            ]);

            return $item->fresh();
        });
    }

    public function update(User $actor, Task $task, TaskChecklistItem $item, array $data): TaskChecklistItem
    {
        $this->assertParticipate($actor, $task);
        foreach (['text', 'sort_order'] as $field) {
            if (array_key_exists($field, $data)) {
                $item->{$field} = $data[$field];
            }
        }
        $item->save();

        return $item->fresh();
    }

    public function remove(User $actor, Task $task, TaskChecklistItem $item): void
    {
        if (! $this->authorizer->canManage($actor, $task)) {
            $this->fail(__('tasks.task_forbidden'));
        }
        $item->delete();
    }

    private function assertParticipate(User $actor, Task $task): void
    {
        if ($task->isArchived()) {
            $this->fail(__('tasks.task_archived_readonly'));
        }
        if (! $this->authorizer->canParticipate($actor, $task)) {
            $this->fail(__('tasks.task_forbidden'));
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['checklist' => [$message]]);
    }
}
