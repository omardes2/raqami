<?php

namespace App\Modules\Leave\Services;

use App\Modules\Leave\Enums\ApprovalPurpose;
use App\Modules\Leave\Enums\ApprovalStatus;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Notifications\Services\NotificationPayloadFactory;
use App\Modules\Notifications\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sprint 8B — post-commit leave notifications. Producers register delivery via
 * DB::afterCommit so a rolled-back transition never notifies, and a failed
 * notification (after the business change is already committed) is swallowed and
 * logged — it must never turn a successful approval/rejection into an HTTP 500.
 *
 * Recipients come from the REAL approval architecture: named approver steps
 * (LeaveRequestApproval.approver_user_id) for "submitted", and the requesting
 * employee's linked User for "approved"/"rejected". hr_pool steps carry no
 * definitive user and are intentionally skipped; an Employee without a linked
 * User is skipped; NotificationService additionally requires an active
 * membership. No role-name guessing, no leave type / reason in the payload.
 */
class LeaveNotifier
{
    public function __construct(private readonly NotificationService $notifications) {}

    /** Notify the named approvers of a freshly submitted request awaiting them. */
    public function submitted(LeaveRequest $request): void
    {
        $request->loadMissing('employee', 'approvals');
        $requestId = (string) $request->getKey();
        $employeeName = $request->employee?->fullName() ?? '';

        $approverIds = $request->approvals
            ->where('purpose', ApprovalPurpose::Approval->value)
            ->where('status', ApprovalStatus::Pending->value)
            ->pluck('approver_user_id')
            ->filter()          // drop hr_pool (null) steps — no definitive user
            ->unique()
            ->values()
            ->all();

        if ($approverIds === []) {
            return;
        }

        $this->afterCommit('leave.request.submitted', function () use ($approverIds, $requestId, $employeeName) {
            foreach ($approverIds as $approverId) {
                $this->notifications->send(
                    (string) $approverId,
                    NotificationPayloadFactory::leaveRequestSubmitted($requestId, (string) $approverId, $employeeName),
                );
            }
        });
    }

    /** Notify the requester that their request was approved. */
    public function approved(LeaveRequest $request): void
    {
        $request->loadMissing('employee');
        $requesterUserId = $request->employee?->user_id;
        if ($requesterUserId === null) {
            return;
        }
        $requestId = (string) $request->getKey();
        $version = (int) $request->version;

        $this->afterCommit('leave.request.approved', fn () => $this->notifications->send(
            (string) $requesterUserId,
            NotificationPayloadFactory::leaveRequestApproved($requestId, $version),
        ));
    }

    /** Notify the requester that their request was rejected. */
    public function rejected(LeaveRequest $request): void
    {
        $request->loadMissing('employee');
        $requesterUserId = $request->employee?->user_id;
        if ($requesterUserId === null) {
            return;
        }
        $requestId = (string) $request->getKey();
        $version = (int) $request->version;

        $this->afterCommit('leave.request.rejected', fn () => $this->notifications->send(
            (string) $requesterUserId,
            NotificationPayloadFactory::leaveRequestRejected($requestId, $version),
        ));
    }

    /** Deliver after the surrounding transaction commits; never rethrow. */
    private function afterCommit(string $event, \Closure $send): void
    {
        DB::afterCommit(function () use ($event, $send) {
            try {
                $send();
            } catch (Throwable $e) {
                Log::warning('notification.delivery_failed', [
                    'domain' => 'leave',
                    'event' => $event,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        });
    }
}
