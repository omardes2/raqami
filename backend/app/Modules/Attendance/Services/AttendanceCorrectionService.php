<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Enums\AttendanceEventType;
use App\Modules\Attendance\Enums\AttendanceSource;
use App\Modules\Attendance\Enums\CorrectionStatus;
use App\Modules\Attendance\Models\AttendanceCorrection;
use App\Modules\Attendance\Models\AttendanceEvent;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Models\AttendanceSession;
use App\Modules\Attendance\Support\AttendanceLock;
use App\Modules\Attendance\Support\ResolvedWorkDay;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Tenancy\Services\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Controlled, SESSION-AWARE correction workflow. A change to a recorded punch is
 * REQUESTED (never applied silently) and reviewed by a DIFFERENT person (no
 * self-approval). Because attendance_sessions are the authoritative punch state
 * (Sprint 4), a correction targets a SESSION, and the daily attendance_record is
 * only ever the derived aggregate of its sessions — never written directly.
 *
 * Target resolution:
 *   - a single-session day auto-resolves that session (Sprint 3 compatible);
 *   - a multi-session day REQUIRES an explicit target session;
 *   - a session-less (e.g. materialized-absent) record has its session CREATED on
 *     approval, then the record is re-aggregated.
 *
 * Approval is atomic under the per-employee advisory lock + row locks, guarded by
 * optimistic concurrency (record.version snapshot), overlap prevention against
 * sibling sessions, and a full before/after audit trail.
 */
class AttendanceCorrectionService
{
    public function __construct(
        private readonly ScheduleResolver $resolver,
        private readonly AttendanceCalculator $calculator,
        private readonly AttendanceRecordAggregator $aggregator,
        private readonly OvertimeApprovalService $overtime,
        private readonly AttendanceSettingsService $settings,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
        private readonly AttendanceCorrectionNotifier $notifier,
    ) {}

    /**
     * @param  array{requested_check_in_at?:?string, requested_check_out_at?:?string,
     *     attendance_session_id?:?string, reason:string}  $data
     */
    public function request(AttendanceRecord $record, array $data, Model $requestedBy): AttendanceCorrection
    {
        if (! $this->settings->current()->attendance_correction_enabled) {
            $this->reject(__('attendance.corrections_disabled'));
        }

        $checkIn = $this->parse($data['requested_check_in_at'] ?? null);
        $checkOut = $this->parse($data['requested_check_out_at'] ?? null);

        if ($checkIn === null && $checkOut === null) {
            $this->reject(__('attendance.correction_empty'));
        }

        $target = $this->resolveTargetSession($record, $data['attendance_session_id'] ?? null);

        // Validate the effective window against the target session (or the record
        // for a session-less correction that will create one).
        $baseIn = $target?->check_in_at ?? $record->check_in_at;
        $baseOut = $target?->check_out_at ?? $record->check_out_at;
        $effectiveIn = $checkIn ?? ($baseIn ? CarbonImmutable::parse($baseIn) : null);
        $effectiveOut = $checkOut ?? ($baseOut ? CarbonImmutable::parse($baseOut) : null);

        if ($effectiveIn !== null && $effectiveOut !== null && $effectiveOut->lessThanOrEqualTo($effectiveIn)) {
            $this->reject(__('attendance.checkout_after_checkin'));
        }

        // A session-less correction must at least establish a check-in to create one.
        if ($target === null && $record->sessions()->count() === 0 && $effectiveIn === null) {
            $this->reject(__('attendance.correction_empty'));
        }

        return DB::transaction(function () use ($record, $target, $data, $requestedBy, $checkIn, $checkOut) {
            $correction = AttendanceCorrection::query()->create([
                'attendance_record_id' => $record->getKey(),
                'attendance_session_id' => $target?->getKey(),
                'employee_id' => $record->employee_id,
                'requested_by_user_id' => (string) $requestedBy->getKey(),
                'requested_check_in_at' => $checkIn,
                'requested_check_out_at' => $checkOut,
                'reason' => $data['reason'],
                'status' => CorrectionStatus::Pending,
                'old_values' => $this->snapshot($record, $target),
            ]);

            $this->audit->log('attendance.correction_requested', [
                'actor' => $requestedBy,
                'subject' => $correction,
                'metadata' => [
                    'attendance_record_id' => (string) $record->getKey(),
                    'attendance_session_id' => (string) ($target?->getKey() ?? ''),
                ],
            ]);

            return $correction;
        });
    }

    public function approve(AttendanceCorrection $correction, Model $reviewer): AttendanceCorrection
    {
        $this->assertReviewable($correction, $reviewer);

        return DB::transaction(function () use ($correction, $reviewer) {
            $record = AttendanceRecord::query()->lockForUpdate()->findOrFail($correction->attendance_record_id);

            // Serialize with live punches for this employee.
            AttendanceLock::forEmployee((string) $this->context->tenantId(), (string) $record->employee_id);

            // Optimistic concurrency: the record must be unchanged since the request.
            $baseVersion = $correction->old_values['version'] ?? null;
            if ($baseVersion !== null && (int) $record->version !== (int) $baseVersion) {
                $this->reject(__('attendance.correction_stale'));
            }

            $session = $correction->attendance_session_id !== null
                ? AttendanceSession::query()->lockForUpdate()->find($correction->attendance_session_id)
                : null;

            // The targeted session may have been deleted since the request.
            if ($correction->attendance_session_id !== null && $session === null) {
                $this->reject(__('attendance.correction_stale'));
            }

            $checkIn = $correction->requested_check_in_at
                ? CarbonImmutable::parse($correction->requested_check_in_at)
                : ($session?->check_in_at ? CarbonImmutable::parse($session->check_in_at) : null);
            $checkOut = $correction->requested_check_out_at
                ? CarbonImmutable::parse($correction->requested_check_out_at)
                : ($session?->check_out_at ? CarbonImmutable::parse($session->check_out_at) : null);

            if ($checkIn === null) {
                $this->reject(__('attendance.correction_empty'));
            }
            if ($checkOut !== null && $checkOut->lessThanOrEqualTo($checkIn)) {
                $this->reject(__('attendance.checkout_after_checkin'));
            }

            // No overlap with sibling sessions (exclude the one being corrected).
            if ($this->overlapsSibling($record, $session?->getKey(), $checkIn, $checkOut)) {
                $this->reject(__('attendance.session_overlap'));
            }

            $session = $this->applyToSession($record, $session, $checkIn, $checkOut);

            $record = $this->aggregator->aggregate($record);
            $record->fill(['corrected_at' => CarbonImmutable::now()->utc()])->save();
            $this->overtime->syncForRecord($record, $reviewer);

            AttendanceEvent::query()->create([
                'employee_id' => $record->employee_id,
                'attendance_record_id' => $record->getKey(),
                'event_type' => AttendanceEventType::CorrectionApplied,
                'source' => AttendanceSource::Correction,
                'occurred_at' => CarbonImmutable::now()->utc(),
                'metadata' => [
                    'correction_id' => (string) $correction->getKey(),
                    'attendance_session_id' => (string) $session->getKey(),
                ],
                'created_by_user_id' => (string) $reviewer->getKey(),
            ]);

            $correction->fill([
                'attendance_session_id' => $session->getKey(),
                'status' => CorrectionStatus::Approved,
                'reviewed_by_user_id' => (string) $reviewer->getKey(),
                'reviewed_at' => CarbonImmutable::now()->utc(),
                'new_values' => $this->snapshot($record->fresh(), $session->fresh()),
            ])->save();

            $this->audit->log('attendance.correction_approved', [
                'actor' => $reviewer,
                'subject' => $correction,
                'metadata' => [
                    'attendance_record_id' => (string) $record->getKey(),
                    'attendance_session_id' => (string) $session->getKey(),
                ],
            ]);

            $this->notifier->reviewed($correction, 'approved');

            return $correction;
        });
    }

    public function rejectRequest(AttendanceCorrection $correction, Model $reviewer, string $reason): AttendanceCorrection
    {
        $this->assertReviewable($correction, $reviewer);

        return DB::transaction(function () use ($correction, $reviewer, $reason) {
            $correction->fill([
                'status' => CorrectionStatus::Rejected,
                'reviewed_by_user_id' => (string) $reviewer->getKey(),
                'reviewed_at' => CarbonImmutable::now()->utc(),
                'rejection_reason' => $reason,
            ])->save();

            $this->audit->log('attendance.correction_rejected', [
                'actor' => $reviewer,
                'subject' => $correction,
                'metadata' => ['attendance_record_id' => (string) $correction->attendance_record_id],
            ]);

            $this->notifier->reviewed($correction, 'rejected');

            return $correction;
        });
    }

    /**
     * Decide which session a correction targets. Explicit id must belong to the
     * record; a single-session day auto-resolves; a multi-session day requires an
     * explicit target; a session-less record resolves to null (created on approval).
     */
    private function resolveTargetSession(AttendanceRecord $record, ?string $sessionId): ?AttendanceSession
    {
        $sessions = $record->sessions()->orderBy('sequence')->get();

        if ($sessionId !== null) {
            $match = $sessions->firstWhere('id', $sessionId);
            if ($match === null) {
                $this->reject(__('attendance.correction_session_invalid'));
            }

            return $match;
        }

        if ($sessions->count() > 1) {
            $this->reject(__('attendance.correction_session_required'));
        }

        return $sessions->first(); // the one session, or null when there are none
    }

    /** Update the target session, or create one when the record had none. */
    private function applyToSession(AttendanceRecord $record, ?AttendanceSession $session, CarbonImmutable $checkIn, ?CarbonImmutable $checkOut): AttendanceSession
    {
        if ($session !== null) {
            $resolved = ResolvedWorkDay::fromSessionSnapshot($session);
            $computation = $this->calculator->compute($resolved, $checkIn, $checkOut);

            $session->fill([
                'check_in_at' => $checkIn,
                'check_out_at' => $checkOut,
                'worked_minutes' => $computation->workedMinutes,
                'break_minutes' => $computation->breakMinutes,
                'late_minutes' => $computation->lateMinutes,
                'early_leave_minutes' => $computation->earlyLeaveMinutes,
                'overtime_minutes' => $computation->overtimeMinutes,
                'source' => AttendanceSource::Correction,
                'is_manual' => true,
            ])->save();

            return $session;
        }

        // Create the session for a previously session-less (materialized) record,
        // snapshotting the schedule segment nearest the corrected check-in.
        $settings = $this->settings->current();
        $resolved = $this->resolver->resolveWorkDay($record->employee, $checkIn, $settings->default_timezone);
        $segment = $resolved->segmentFor($checkIn);
        $active = $segment !== null ? $resolved->forSegment($segment) : $resolved;
        $computation = $this->calculator->compute($active, $checkIn, $checkOut);
        $sequence = (int) $record->sessions()->max('sequence') + 1;

        return AttendanceSession::query()->create([
            'attendance_record_id' => $record->getKey(),
            'employee_id' => $record->employee_id,
            'sequence' => $sequence,
            'check_in_at' => $checkIn,
            'check_out_at' => $checkOut,
            'scheduled_start_at' => $active->scheduledStartAt,
            'scheduled_end_at' => $active->scheduledEndAt,
            'worked_minutes' => $computation->workedMinutes,
            'break_minutes' => $computation->breakMinutes,
            'late_minutes' => $computation->lateMinutes,
            'early_leave_minutes' => $computation->earlyLeaveMinutes,
            'overtime_minutes' => $computation->overtimeMinutes,
            'grace_minutes' => $active->graceMinutes,
            'source' => AttendanceSource::Correction,
            'is_manual' => true,
        ]);
    }

    /** Half-open [in, out) overlap against the record's other sessions. */
    private function overlapsSibling(AttendanceRecord $record, ?string $exceptSessionId, CarbonImmutable $in, ?CarbonImmutable $out): bool
    {
        foreach ($record->sessions()->get() as $s) {
            if ($exceptSessionId !== null && (string) $s->getKey() === $exceptSessionId) {
                continue;
            }

            $sIn = CarbonImmutable::parse($s->check_in_at);
            $sOut = $s->check_out_at ? CarbonImmutable::parse($s->check_out_at) : null;

            $startsBeforeOtherEnds = $out === null || $sIn->lessThan($out);
            $endsAfterOtherStarts = $sOut === null || $in->lessThan($sOut);

            if ($startsBeforeOtherEnds && $endsAfterOtherStarts) {
                return true;
            }
        }

        return false;
    }

    /** A pending correction reviewed by someone OTHER than the requester. */
    private function assertReviewable(AttendanceCorrection $correction, Model $reviewer): void
    {
        if ($correction->status->isTerminal()) {
            $this->reject(__('attendance.correction_reviewed'));
        }

        if ((string) $reviewer->getKey() === (string) $correction->requested_by_user_id) {
            $this->reject(__('attendance.correction_self'));
        }
    }

    private function parse(?string $value): ?CarbonImmutable
    {
        return $value !== null ? CarbonImmutable::parse($value)->utc() : null;
    }

    /**
     * Before/after snapshot: the record's version (for concurrency) plus the
     * targeted session's punch/minute state.
     *
     * @return array<string, mixed>
     */
    private function snapshot(AttendanceRecord $record, ?AttendanceSession $session): array
    {
        return [
            'version' => (int) $record->version,
            'check_in_at' => optional($record->check_in_at)->toISOString(),
            'check_out_at' => optional($record->check_out_at)->toISOString(),
            'worked_minutes' => $record->worked_minutes,
            'late_minutes' => $record->late_minutes,
            'early_leave_minutes' => $record->early_leave_minutes,
            'overtime_minutes' => $record->overtime_minutes,
            'status' => $record->status instanceof \BackedEnum ? $record->status->value : $record->status,
            'session' => $session === null ? null : [
                'id' => (string) $session->getKey(),
                'check_in_at' => optional($session->check_in_at)->toISOString(),
                'check_out_at' => optional($session->check_out_at)->toISOString(),
                'worked_minutes' => $session->worked_minutes,
                'late_minutes' => $session->late_minutes,
                'overtime_minutes' => $session->overtime_minutes,
            ],
        ];
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['attendance' => [$message]]);
    }
}
