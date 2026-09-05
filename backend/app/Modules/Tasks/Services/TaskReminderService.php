<?php

namespace App\Modules\Tasks\Services;

use App\Modules\Employees\Models\Employee;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Services\NotificationPayloadFactory;
use App\Modules\Notifications\Services\NotificationResult;
use App\Modules\Notifications\Services\NotificationService;
use App\Modules\Tasks\Enums\DueType;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskAssignee;
use App\Modules\Tasks\Support\TaskDueQuery;
use App\Modules\Tasks\Support\TaskVisibilityResolver;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantContext;
use Throwable;

/**
 * Sprint 8B — task due-soon / overdue reminders across every tenant. Recipients
 * are actual assignee Users, but the system cron is NOT globally authorized: for
 * each candidate the assignee's CURRENT visibility is re-checked with
 * TaskVisibilityResolver before delivery. Completed/terminal/archived and
 * invisible tasks are excluded (TaskDueQuery + the visibility re-check);
 * employees without a User and non-members are skipped. Dedupe keys include the
 * due date and assignee, so a due-date change or assignee change yields a new
 * reminder while a same-state rerun never duplicates (one per due-date/assignee).
 */
class TaskReminderService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TaskVisibilityResolver $visibility,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @return array{tenants:int, due_soon:int, overdue:int, skipped:int, errors:int}
     */
    public function remind(): array
    {
        $totals = ['tenants' => 0, 'due_soon' => 0, 'overdue' => 0, 'skipped' => 0, 'errors' => 0];

        foreach ($this->tenantIds() as $tenantId) {
            $totals['tenants']++;
            try {
                $this->context->runAs($tenantId, function () use (&$totals) {
                    foreach (Task::query()->whereRaw(TaskDueQuery::overdue())->orderBy('id')->get() as $task) {
                        $totals['overdue'] += $this->notify($task, 'overdue', $totals);
                    }
                    foreach (Task::query()->whereRaw(TaskDueQuery::dueSoon())->orderBy('id')->get() as $task) {
                        $totals['due_soon'] += $this->notify($task, 'due_soon', $totals);
                    }
                });
            } catch (Throwable $e) {
                $totals['errors']++;
                report($e);
            }
        }

        return $totals;
    }

    private function notify(Task $task, string $state, array &$totals): int
    {
        $dueOn = $this->dueOn($task);
        if ($dueOn === '') {
            return 0;
        }
        $sent = 0;

        $employeeIds = TaskAssignee::query()->where('task_id', $task->getKey())->pluck('employee_id');
        foreach ($employeeIds as $employeeId) {
            $userId = Employee::query()->whereKey($employeeId)->value('user_id');
            if ($userId === null) {
                $totals['skipped']++;

                continue;
            }
            $user = User::query()->find($userId);
            // The cron is not globally authorized — re-check THIS assignee's sight.
            if ($user === null || ! $this->visibility->canViewTask($user, $task)) {
                $totals['skipped']++;

                continue;
            }

            $payload = $state === 'overdue'
                ? NotificationPayloadFactory::taskOverdue((string) $task->getKey(), $dueOn, (string) $userId)
                : NotificationPayloadFactory::taskDueSoon((string) $task->getKey(), $dueOn, (string) $userId);

            if ($this->notifications->send((string) $userId, $payload) === NotificationResult::Created) {
                $sent++;
            }
        }

        return $sent;
    }

    /** The task's due date as a stable YYYY-MM-DD discriminator. */
    private function dueOn(Task $task): string
    {
        if ($task->due_type === DueType::Date && $task->due_on !== null) {
            return $task->due_on->toDateString();
        }

        return $task->due_at?->toDateString() ?? '';
    }

    /** @return array<int, string> */
    private function tenantIds(): array
    {
        return $this->context->runAsPlatform(
            fn () => Tenant::query()->orderBy('id')->pluck('id')->all()
        );
    }
}
