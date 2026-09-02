<?php

namespace App\Modules\Tasks\Services;

use App\Modules\Employees\Models\Employee;
use App\Modules\Identity\Models\User;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskWatcher;

/**
 * Task watchers (Correction F). A watcher is a User notification preference — it
 * NEVER grants visibility. Auto-watch covers the creator and the linked Users of
 * assignees only (an assignee Employee with no linked User is skipped — identities
 * are never fabricated). Commenters are not auto-watched.
 */
class TaskWatcherService
{
    /** Idempotent explicit watch (task visibility is checked by the caller). */
    public function watch(User $user, Task $task): TaskWatcher
    {
        return TaskWatcher::query()->firstOrCreate(
            ['task_id' => $task->getKey(), 'user_id' => $user->getKey()],
            ['created_at' => now()],
        );
    }

    public function unwatch(User $user, Task $task): void
    {
        TaskWatcher::query()
            ->where('task_id', $task->getKey())
            ->where('user_id', $user->getKey())
            ->delete();
    }

    /** Auto-watch the task creator (already a User identity). */
    public function autoWatchCreator(Task $task): void
    {
        if ($task->created_by_user_id === null) {
            return;
        }
        TaskWatcher::query()->firstOrCreate(
            ['task_id' => $task->getKey(), 'user_id' => $task->created_by_user_id],
            ['created_at' => now()],
        );
    }

    /** Auto-watch an assignee ONLY if the Employee currently has a linked User. */
    public function autoWatchAssignee(Task $task, Employee $employee): void
    {
        $userId = $employee->user_id;
        if ($userId === null) {
            return; // never fabricate a User identity for an unlinked Employee
        }
        TaskWatcher::query()->firstOrCreate(
            ['task_id' => $task->getKey(), 'user_id' => (string) $userId],
            ['created_at' => now()],
        );
    }
}
