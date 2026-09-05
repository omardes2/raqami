<?php

namespace App\Modules\Tasks\Services;

use App\Modules\Employees\Models\Employee;
use App\Modules\Notifications\Services\NotificationPayloadFactory;
use App\Modules\Notifications\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sprint 8B — post-commit task notifications. Delivery is registered via
 * DB::afterCommit so a rolled-back assignment never notifies, and a failed
 * notification is swallowed and logged (it must not fail the assignment).
 *
 * The recipient is the ACTUAL assignee's linked User (Employee.user_id); an
 * employee without a User is skipped, as is a non-member (NotificationService).
 * The TaskActivityEvent id discriminates transitions, so A → B → A produces
 * distinct notifications. No task title or other private content is included —
 * subject_id carries the task for an authorized deep-link only. Possessing a
 * notification never grants task access; the target screen re-authorizes.
 */
class TaskNotifier
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function assigned(string $taskId, string $employeeId, string $activityEventId, bool $isReassignment): void
    {
        $userId = Employee::query()->whereKey($employeeId)->value('user_id');
        if ($userId === null) {
            return;
        }

        $this->afterCommit(function () use ($taskId, $activityEventId, $userId, $isReassignment) {
            $this->notifications->send(
                (string) $userId,
                NotificationPayloadFactory::taskAssigned($taskId, $activityEventId, (string) $userId, $isReassignment),
            );
        });
    }

    private function afterCommit(\Closure $send): void
    {
        DB::afterCommit(function () use ($send) {
            try {
                $send();
            } catch (Throwable $e) {
                Log::warning('notification.delivery_failed', [
                    'domain' => 'tasks',
                    'event' => 'task.assigned',
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        });
    }
}
