<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Models\AttendanceCorrection;
use App\Modules\Notifications\Services\NotificationPayloadFactory;
use App\Modules\Notifications\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sprint 8B — post-commit attendance-correction notifications to the requester.
 * The requester is a User directly (AttendanceCorrection.requested_by_user_id).
 * The payload carries only the result flag (approved/rejected) — never the
 * reviewer's rejection reason or any private note. Delivery is post-commit and
 * failures are swallowed and logged.
 */
class AttendanceCorrectionNotifier
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function reviewed(AttendanceCorrection $correction, string $result): void
    {
        $requesterUserId = $correction->requested_by_user_id;
        if ($requesterUserId === null || $requesterUserId === '') {
            return;
        }
        $correctionId = (string) $correction->getKey();
        $result = $result === 'approved' ? 'approved' : 'rejected';

        DB::afterCommit(function () use ($requesterUserId, $correctionId, $result) {
            try {
                $this->notifications->send(
                    (string) $requesterUserId,
                    NotificationPayloadFactory::attendanceCorrectionReviewed($correctionId, $result),
                );
            } catch (Throwable $e) {
                Log::warning('notification.delivery_failed', [
                    'domain' => 'attendance',
                    'event' => 'attendance.correction.reviewed',
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        });
    }
}
