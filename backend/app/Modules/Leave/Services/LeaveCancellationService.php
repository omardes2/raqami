<?php

namespace App\Modules\Leave\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Leave\Enums\ApprovalPurpose;
use App\Modules\Leave\Enums\ApprovalStatus;
use App\Modules\Leave\Enums\LeaveRequestStatus;
use App\Modules\Leave\Models\LeaveRequest;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Approved-leave cancellation (D3), distinct from pending withdrawal. The
 * employee may only REQUEST cancellation (→ cancellation_pending); the leave
 * stays ACTIVE for LeaveResolver/attendance and balance is NOT restored until a
 * manager/HR finalizes. HR/Admin may direct-cancel with a mandatory reason. Final
 * cancellation reverses the FUTURE (not-yet-started) usage exactly once and
 * re-materializes the freed attendance days (via the injected sync hook).
 */
class LeaveCancellationService
{
    public function __construct(
        private readonly LeaveBalanceService $balances,
        private readonly LeaveApprovalRouter $router,
        private readonly LeaveAttendanceSync $attendanceSync,
        private readonly AuditLogger $audit,
    ) {}

    /** Employee requests cancellation of approved leave → cancellation_pending. */
    public function request(LeaveRequest $request, Model $actor, ?int $expectedVersion = null): LeaveRequest
    {
        return DB::transaction(function () use ($request, $actor, $expectedVersion) {
            $request = LeaveRequest::query()->lockForUpdate()->findOrFail($request->getKey());

            if ($request->status === LeaveRequestStatus::CancellationPending) {
                return $request; // idempotent
            }
            if ($request->status !== LeaveRequestStatus::Approved) {
                $this->fail(__('leave.not_cancellable'));
            }
            $this->assertFresh($request, $expectedVersion);

            $policy = $request->policy;
            if ($policy !== null && ! $policy->allow_cancellation_request) {
                $this->fail(__('leave.cancellation_not_allowed'));
            }

            $request->fill([
                'status' => LeaveRequestStatus::CancellationPending,
                'cancellation_requested_at' => CarbonImmutable::now()->utc(),
                'version' => (int) $request->version + 1,
            ])->save();

            $this->router->buildCancellationStep($request->fresh(), $request->employee);

            $this->audit->log('leave.cancellation_requested', [
                'actor' => $actor,
                'subject' => $request,
                'metadata' => ['employee_id' => (string) $request->employee_id],
            ]);

            return $request->fresh();
        });
    }

    /** Approve the pending cancellation step → finalize the cancellation. */
    public function approve(LeaveRequest $request, Model $reviewer, ?string $note = null): LeaveRequest
    {
        return DB::transaction(function () use ($request, $reviewer, $note) {
            $request = LeaveRequest::query()->lockForUpdate()->findOrFail($request->getKey());

            if ($request->status !== LeaveRequestStatus::CancellationPending) {
                $this->fail(__('leave.not_cancellation_pending'));
            }
            $this->assertNotSelf($request, $reviewer);

            $step = $request->approvals()
                ->where('purpose', ApprovalPurpose::Cancellation->value)
                ->where('status', ApprovalStatus::Pending->value)
                ->orderBy('step_order')
                ->lockForUpdate()
                ->first();

            if ($step === null) {
                $this->fail(__('leave.no_pending_step'));
            }

            $step->fill([
                'status' => ApprovalStatus::Approved,
                'reviewed_by_user_id' => (string) $reviewer->getKey(),
                'reviewed_at' => CarbonImmutable::now()->utc(),
                'note' => $note,
            ])->save();

            return $this->finalize($request, $reviewer, $note);
        });
    }

    /** Reject the cancellation request → leave returns to approved. */
    public function reject(LeaveRequest $request, Model $reviewer, ?string $note = null): LeaveRequest
    {
        return DB::transaction(function () use ($request, $reviewer, $note) {
            $request = LeaveRequest::query()->lockForUpdate()->findOrFail($request->getKey());

            if ($request->status !== LeaveRequestStatus::CancellationPending) {
                $this->fail(__('leave.not_cancellation_pending'));
            }
            $this->assertNotSelf($request, $reviewer);

            $request->approvals()
                ->where('purpose', ApprovalPurpose::Cancellation->value)
                ->where('status', ApprovalStatus::Pending->value)
                ->update([
                    'status' => ApprovalStatus::Rejected->value,
                    'reviewed_by_user_id' => (string) $reviewer->getKey(),
                    'reviewed_at' => CarbonImmutable::now()->utc(),
                    'note' => $note,
                ]);

            $request->fill([
                'status' => LeaveRequestStatus::Approved,
                'cancellation_requested_at' => null,
                'version' => (int) $request->version + 1,
            ])->save();

            $this->audit->log('leave.cancellation_rejected', [
                'actor' => $reviewer,
                'subject' => $request,
            ]);

            return $request->fresh();
        });
    }

    /** HR/Admin direct cancellation of approved (or cancellation_pending) leave. */
    public function directCancel(LeaveRequest $request, Model $actor, string $reason): LeaveRequest
    {
        if (trim($reason) === '') {
            $this->fail(__('leave.cancellation_reason_required'));
        }

        return DB::transaction(function () use ($request, $actor, $reason) {
            $request = LeaveRequest::query()->lockForUpdate()->findOrFail($request->getKey());

            if (! in_array($request->status, [LeaveRequestStatus::Approved, LeaveRequestStatus::CancellationPending], true)) {
                $this->fail(__('leave.not_cancellable'));
            }

            // Cancel any open cancellation step.
            $request->approvals()
                ->where('purpose', ApprovalPurpose::Cancellation->value)
                ->where('status', ApprovalStatus::Pending->value)
                ->update(['status' => ApprovalStatus::Cancelled->value]);

            return $this->finalize($request, $actor, $reason);
        });
    }

    /**
     * Reverse the FUTURE portion of usage exactly once, mark cancelled, and
     * re-materialize freed attendance days. Elapsed days remain consumed.
     */
    private function finalize(LeaveRequest $request, Model $actor, ?string $note): LeaveRequest
    {
        $today = CarbonImmutable::now()->startOfDay();

        $reversible = (int) $request->days()
            ->whereDate('work_date', '>=', $today->toDateString())
            ->sum('consumption_minutes');

        if ($reversible > 0) {
            $period = $request->period;
            $this->balances->withLockedBalance($period, function ($balance) use ($request, $actor, $reversible) {
                $this->balances->reverseUsage($balance, $reversible, [
                    'leave_request_id' => $request->getKey(),
                    'reason' => 'cancellation reversal',
                    'created_by_user_id' => (string) $actor->getKey(),
                ]);
            });
        }

        $request->fill([
            'status' => LeaveRequestStatus::Cancelled,
            'finalized_at' => CarbonImmutable::now()->utc(),
            'decision_note' => $note,
            'version' => (int) $request->version + 1,
        ])->save();

        $this->audit->log('leave.cancelled', [
            'actor' => $actor,
            'subject' => $request,
            'metadata' => [
                'employee_id' => (string) $request->employee_id,
                'reversed_minutes' => $reversible,
            ],
        ]);

        // Re-materialize the attendance days this cancellation freed.
        $this->attendanceSync->rematerializeForRequest($request->fresh());

        return $request->fresh();
    }

    private function assertNotSelf(LeaveRequest $request, Model $reviewer): void
    {
        if ($request->employee?->user_id !== null && (string) $reviewer->getKey() === (string) $request->employee->user_id) {
            $this->fail(__('leave.self_approval_forbidden'));
        }
    }

    private function assertFresh(LeaveRequest $request, ?int $expectedVersion): void
    {
        if ($expectedVersion !== null && (int) $request->version !== $expectedVersion) {
            $this->fail(__('leave.stale'));
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['leave' => [$message]]);
    }
}
