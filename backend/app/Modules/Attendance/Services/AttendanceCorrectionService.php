<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Enums\AttendanceEventType;
use App\Modules\Attendance\Enums\AttendanceSource;
use App\Modules\Attendance\Enums\CorrectionStatus;
use App\Modules\Attendance\Models\AttendanceCorrection;
use App\Modules\Attendance\Models\AttendanceEvent;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Support\ResolvedWorkDay;
use App\Modules\Audit\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Controlled correction workflow. A change to a recorded attendance is REQUESTED
 * (never applied silently) and then reviewed by a DIFFERENT person — no
 * self-approval, enforcing segregation of duties. On approval the server
 * recomputes the record from its snapshot using the corrected instants and
 * keeps a full before/after audit trail.
 */
class AttendanceCorrectionService
{
    public function __construct(
        private readonly AttendanceCalculator $calculator,
        private readonly AttendanceSettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{requested_check_in_at?:?string, requested_check_out_at?:?string, reason:string}  $data
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

        $effectiveIn = $checkIn ?? ($record->check_in_at ? CarbonImmutable::parse($record->check_in_at) : null);
        $effectiveOut = $checkOut ?? ($record->check_out_at ? CarbonImmutable::parse($record->check_out_at) : null);

        if ($effectiveIn !== null && $effectiveOut !== null && $effectiveOut->lessThanOrEqualTo($effectiveIn)) {
            $this->reject(__('attendance.checkout_after_checkin'));
        }

        return DB::transaction(function () use ($record, $data, $requestedBy, $checkIn, $checkOut) {
            $correction = AttendanceCorrection::query()->create([
                'attendance_record_id' => $record->getKey(),
                'employee_id' => $record->employee_id,
                'requested_by_user_id' => (string) $requestedBy->getKey(),
                'requested_check_in_at' => $checkIn,
                'requested_check_out_at' => $checkOut,
                'reason' => $data['reason'],
                'status' => CorrectionStatus::Pending,
                'old_values' => $this->snapshot($record),
            ]);

            $this->audit->log('attendance.correction_requested', [
                'actor' => $requestedBy,
                'subject' => $correction,
                'metadata' => ['attendance_record_id' => (string) $record->getKey()],
            ]);

            return $correction;
        });
    }

    public function approve(AttendanceCorrection $correction, Model $reviewer): AttendanceCorrection
    {
        $this->assertReviewable($correction, $reviewer);

        return DB::transaction(function () use ($correction, $reviewer) {
            $record = AttendanceRecord::query()->lockForUpdate()->findOrFail($correction->attendance_record_id);

            // Optimistic concurrency: the record must be unchanged since the
            // correction was requested. If a later punch/aggregation moved it,
            // the reviewer is acting on stale numbers — refuse and force a reload.
            $baseVersion = $correction->old_values['version'] ?? null;
            if ($baseVersion !== null && (int) $record->version !== (int) $baseVersion) {
                $this->reject(__('attendance.correction_stale'));
            }

            $checkIn = $correction->requested_check_in_at
                ? CarbonImmutable::parse($correction->requested_check_in_at)
                : ($record->check_in_at ? CarbonImmutable::parse($record->check_in_at) : null);
            $checkOut = $correction->requested_check_out_at
                ? CarbonImmutable::parse($correction->requested_check_out_at)
                : ($record->check_out_at ? CarbonImmutable::parse($record->check_out_at) : null);

            $resolved = ResolvedWorkDay::fromRecordSnapshot($record);
            $computation = $this->calculator->compute($resolved, $checkIn, $checkOut);

            $record->fill([
                'check_in_at' => $checkIn,
                'check_out_at' => $checkOut,
                'worked_minutes' => $computation->workedMinutes,
                'break_minutes' => $computation->breakMinutes,
                'late_minutes' => $computation->lateMinutes,
                'early_leave_minutes' => $computation->earlyLeaveMinutes,
                'overtime_minutes' => $computation->overtimeMinutes,
                'status' => $computation->status,
                'source' => AttendanceSource::Correction,
                'corrected_at' => CarbonImmutable::now()->utc(),
                'version' => (int) $record->version + 1,
            ])->save();

            AttendanceEvent::query()->create([
                'employee_id' => $record->employee_id,
                'attendance_record_id' => $record->getKey(),
                'event_type' => AttendanceEventType::CorrectionApplied,
                'source' => AttendanceSource::Correction,
                'occurred_at' => CarbonImmutable::now()->utc(),
                'metadata' => ['correction_id' => (string) $correction->getKey()],
                'created_by_user_id' => (string) $reviewer->getKey(),
            ]);

            $correction->fill([
                'status' => CorrectionStatus::Approved,
                'reviewed_by_user_id' => (string) $reviewer->getKey(),
                'reviewed_at' => CarbonImmutable::now()->utc(),
                'new_values' => $this->snapshot($record),
            ])->save();

            $this->audit->log('attendance.correction_approved', [
                'actor' => $reviewer,
                'subject' => $correction,
                'metadata' => ['attendance_record_id' => (string) $record->getKey()],
            ]);

            return $correction;
        });
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['attendance' => [$message]]);
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

            return $correction;
        });
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

    /** @return array<string, mixed> */
    private function snapshot(AttendanceRecord $record): array
    {
        return [
            'check_in_at' => optional($record->check_in_at)->toISOString(),
            'check_out_at' => optional($record->check_out_at)->toISOString(),
            'worked_minutes' => $record->worked_minutes,
            'late_minutes' => $record->late_minutes,
            'early_leave_minutes' => $record->early_leave_minutes,
            'overtime_minutes' => $record->overtime_minutes,
            'status' => $record->status instanceof \BackedEnum ? $record->status->value : $record->status,
            'version' => (int) $record->version,
        ];
    }
}
