<?php

namespace App\Modules\Leave\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Leave\Enums\ApprovalPurpose;
use App\Modules\Leave\Enums\ApprovalStatus;
use App\Modules\Leave\Enums\LeaveRequestStatus;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveRequestApproval;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Decides ORIGINAL-approval steps (purpose = approval). Segregation of duties is
 * enforced here regardless of entry point: the requesting employee can never
 * approve their own request. Concurrency-safe (request + step row locks + status
 * guards) so a step is decided once and the reservation→usage conversion runs
 * exactly once on final approval. Balance/scope checks that belong to the caller
 * (permission + organizational scope) are done in the controller.
 */
class LeaveApprovalService
{
    public function __construct(
        private readonly LeaveBalanceService $balances,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Approve the current pending approval step. When it is the last one, the
     * request is finalized: the reservation becomes usage exactly once.
     */
    public function approve(LeaveRequest $request, Model $reviewer, ?string $note = null, ?int $expectedVersion = null): LeaveRequest
    {
        return DB::transaction(function () use ($request, $reviewer, $note, $expectedVersion) {
            $request = LeaveRequest::query()->lockForUpdate()->findOrFail($request->getKey());
            $this->assertActionable($request, $reviewer, $expectedVersion);
            $this->assertAttachmentIfRequired($request);

            $step = $this->currentStep($request);
            if ($step === null) {
                $this->fail(__('leave.no_pending_step'));
            }

            $step->fill([
                'status' => ApprovalStatus::Approved,
                'reviewed_by_user_id' => (string) $reviewer->getKey(),
                'reviewed_at' => CarbonImmutable::now()->utc(),
                'note' => $note,
            ])->save();

            $this->audit->log('leave.approval_step_approved', [
                'actor' => $reviewer,
                'subject' => $request,
                'metadata' => ['step_order' => (int) $step->step_order],
            ]);

            // More pending approval steps? Stay pending; reservation is retained.
            if ($this->currentStep($request) !== null) {
                return $request->fresh();
            }

            $this->finalizeApproval($request, $reviewer);

            return $request->fresh();
        });
    }

    /** Reject the current step: cancel remaining steps, release reservation, terminal. */
    public function reject(LeaveRequest $request, Model $reviewer, ?string $note = null, ?int $expectedVersion = null): LeaveRequest
    {
        return DB::transaction(function () use ($request, $reviewer, $note, $expectedVersion) {
            $request = LeaveRequest::query()->lockForUpdate()->findOrFail($request->getKey());
            $this->assertActionable($request, $reviewer, $expectedVersion);

            $step = $this->currentStep($request);
            if ($step === null) {
                $this->fail(__('leave.no_pending_step'));
            }

            $step->fill([
                'status' => ApprovalStatus::Rejected,
                'reviewed_by_user_id' => (string) $reviewer->getKey(),
                'reviewed_at' => CarbonImmutable::now()->utc(),
                'note' => $note,
            ])->save();

            // Cancel any later pending approval steps.
            $request->approvals()
                ->where('purpose', ApprovalPurpose::Approval->value)
                ->where('status', ApprovalStatus::Pending->value)
                ->update(['status' => ApprovalStatus::Cancelled->value]);

            // Release the reservation.
            $period = $request->period;
            $this->balances->withLockedBalance($period, function ($balance) use ($request, $reviewer) {
                $this->balances->releaseReservation($balance, (int) $request->requested_consumption_minutes, [
                    'leave_request_id' => $request->getKey(),
                    'reason' => 'rejection release',
                    'created_by_user_id' => (string) $reviewer->getKey(),
                ]);
            });

            $request->fill([
                'status' => LeaveRequestStatus::Rejected,
                'finalized_at' => CarbonImmutable::now()->utc(),
                'decision_note' => $note,
                'version' => (int) $request->version + 1,
            ])->save();

            $this->audit->log('leave.rejected', [
                'actor' => $reviewer,
                'subject' => $request,
                'metadata' => ['employee_id' => (string) $request->employee_id],
            ]);

            return $request->fresh();
        });
    }

    /**
     * Convert the reservation into usage exactly once and mark the request
     * approved. Net availability change is zero (reserved → used). Called on the
     * last approval step, and directly by the router for the `none` flow.
     */
    public function finalizeApproval(LeaveRequest $request, Model $actor): void
    {
        $period = $request->period;
        $minutes = (int) $request->requested_consumption_minutes;

        $this->balances->withLockedBalance($period, function ($balance) use ($request, $actor, $minutes) {
            $this->balances->releaseReservation($balance, $minutes, [
                'leave_request_id' => $request->getKey(),
                'reason' => 'approval release',
                'created_by_user_id' => (string) $actor->getKey(),
            ]);
            $this->balances->consume($balance, $minutes, [
                'leave_request_id' => $request->getKey(),
                'reason' => 'approval usage',
                'created_by_user_id' => (string) $actor->getKey(),
            ]);
        });

        $request->fill([
            'status' => LeaveRequestStatus::Approved,
            'finalized_at' => CarbonImmutable::now()->utc(),
            'version' => (int) $request->version + 1,
        ])->save();

        $this->audit->log('leave.request_approved', [
            'actor' => $actor,
            'subject' => $request,
            'metadata' => [
                'employee_id' => (string) $request->employee_id,
                'consumption_minutes' => $minutes,
            ],
        ]);
    }

    /** The lowest-order pending ORIGINAL-approval step (locked), or null. */
    private function currentStep(LeaveRequest $request): ?LeaveRequestApproval
    {
        return $request->approvals()
            ->where('purpose', ApprovalPurpose::Approval->value)
            ->where('status', ApprovalStatus::Pending->value)
            ->orderBy('step_order')
            ->lockForUpdate()
            ->first();
    }

    /** A request whose policy/type requires an attachment cannot be finalized without one. */
    private function assertAttachmentIfRequired(LeaveRequest $request): void
    {
        $type = $request->leaveType;
        $policy = $request->policy;
        $requires = ($policy?->requires_attachment ?? false) || ($type?->requires_attachment ?? false);
        if (! $requires) {
            return;
        }

        $threshold = $type?->attachment_required_after_minutes;
        if ($threshold !== null && (int) $request->requested_consumption_minutes < (int) $threshold) {
            return;
        }

        if ($request->attachments()->count() === 0) {
            $this->fail(__('leave.attachment_required'));
        }
    }

    private function assertActionable(LeaveRequest $request, Model $reviewer, ?int $expectedVersion): void
    {
        if ($request->status !== LeaveRequestStatus::Pending) {
            $this->fail(__('leave.not_pending'));
        }
        if ($expectedVersion !== null && (int) $request->version !== $expectedVersion) {
            $this->fail(__('leave.stale'));
        }
        // Segregation of duties: never approve your own request (even Owner/Admin).
        if ($request->employee?->user_id !== null && (string) $reviewer->getKey() === (string) $request->employee->user_id) {
            $this->fail(__('leave.self_approval_forbidden'));
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['leave' => [$message]]);
    }
}
