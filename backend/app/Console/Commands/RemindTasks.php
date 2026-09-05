<?php

namespace App\Console\Commands;

use App\Modules\Tasks\Services\TaskReminderService;
use Illuminate\Console\Command;

/**
 * Sprint 8B — send task due-soon and overdue reminders across all tenants.
 * Idempotent (dedupe per task/due-date/assignee); re-checks each assignee's
 * current visibility before notifying. Scheduler-ready; external cron model.
 *
 *   notifications:remind-tasks
 */
class RemindTasks extends Command
{
    protected $signature = 'notifications:remind-tasks';

    protected $description = 'Notify assignees of due-soon and overdue tasks across all tenants (idempotent).';

    public function handle(TaskReminderService $reminders): int
    {
        $result = $reminders->remind();

        $this->info(sprintf(
            'Task reminders: tenants=%d due_soon=%d overdue=%d skipped=%d errors=%d',
            $result['tenants'], $result['due_soon'], $result['overdue'], $result['skipped'], $result['errors'],
        ));

        return $result['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
