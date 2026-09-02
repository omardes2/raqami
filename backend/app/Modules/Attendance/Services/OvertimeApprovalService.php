<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Enums\OvertimeStatus;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Models\OvertimeApproval;
use App\Modules\Audit\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Overtime approval workflow. The SERVER computes raw overtime (calculated_minutes)
 * from punches; a reviewer then decides how many minutes are approved
 * (approved_minutes) — the two are kept SEPARATE because only approved overtime
 * feeds any future payroll. This module never converts overtime to money.
 *
 * Guarantees:
 *  - The employee can never self-approve (segregation of duties).
 *  - A reviewer cannot approve MORE than calculated without an explicit override.
 *  - Optimistic concurrency: if the record changed since the reviewer loaded it,
 *    approval is refused (stale) rather than acting on outdated numbers.
 *  - One approval per attendance_record (DB unique); terminal decisions are final.
 */
class OvertimeApprovalService
{
    public function __construct(
        private readonly AttendanceSettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Keep the raw overtime approval in sync with a (re)aggregated record. Called
     * right after a punch aggregates the daily record. Creates a pending request
     * when overtime appears, auto-approves when policy allows, and keeps the raw
     * calculated_minutes current on an existing pending row. No-op when overtime
     * tracking is off or there is no overtime.
     */
    public function syncForRecord(AttendanceRecord $record, mixed $actor = null): ?OvertimeApproval
    {
        $settings = $this->settings->current();
        if (! $settings->overtime_tracking_enabled) {
            return null;
        }

        $minutes = (int) $record->overtime_minutes;

        return DB::transaction(function () use ($record, $minutes, $settings, $actor) {
            $approval = OvertimeApproval::query()
                ->where('attendance_record_id', $record->getKey())
                ->lockForUpdate()
                ->first();

            if ($approval !== null) {
                // Keep the raw figure current; never touch a reviewer's decision.
                if ($approval->status === OvertimeStatus::Pending && (int) $approval->calculated_minutes !== $minutes) {
                    $approval->fill(['calculated_minutes' => $minutes])->save();
                }

                return $approval;
            }

            if ($minutes <= 0) {
                return null;
            }

            $autoApprove = $settings->overtime_auto_approve || ! $settings->overtime_requires_approval;

            $approval = OvertimeApproval::query()->create([
                'attendance_record_id' => $record->getKey(),
                'employee_id' => $record->employee_id,
                'work_date' => $record->work_date->toDateString(),
                'calculated_minutes' => $minutes,
                'approved_minutes' => $autoApprove ? $minutes : null,
                'status' => $autoApprove ? OvertimeStatus::Approved : OvertimeStatus::Pending,
                'reviewed_at' => $autoApprove ? CarbonImmutable::now()->utc() : null,
            ]);

            $this->audit->log('attendance.overtime_requested', [
                'actor' => $actor,
                'subject' => $approval,
                'metadata' => [
                    'attendance_record_id' => (string) $record->getKey(),
                    'calculated_minutes' => $minutes,
                    'auto_approved' => $autoApprove,
                ],
            ]);

            return $approval;
        });
    }

    /**
     * Approve overtime. approved_minutes defaults to the calculated figure; a
     * larger value requires $allowOverride. $expectedRecordVersion enables
     * optimistic concurrency — pass the version the reviewer saw.
     */
    public function approve(
        OvertimeApproval $approval,
        Model $reviewer,
        ?int $approvedMinutes = null,
        ?string $notes = null,
        bool $allowOverride = false,
        ?int $expectedRecordVersion = null,
    ): OvertimeApproval {
        $this->assertReviewable($approval, $reviewer);

        return DB::transaction(function () use ($approval, $reviewer, $approvedMinutes, $notes, $allowOverride, $expectedRecordVersion) {
            $record = AttendanceRecord::query()->lockForUpdate()->findOrFail($approval->attendance_record_id);
            $this->assertFresh($record, $expectedRecordVersion);

            $calculated = (int) $approval->calculated_minutes;
            $minutes = $approvedMinutes ?? $calculated;

            if ($minutes < 0) {
                $this->fail(__('attendance.overtime_minutes_invalid'));
            }
            if ($minutes > $calculated && ! $allowOverride) {
                $this->fail(__('attendance.overtime_minutes_invalid'));
            }

            $approval->fill([
                'status' => OvertimeStatus::Approved,
                'approved_minutes' => $minutes,
                'reviewed_by_user_id' => (string) $reviewer->getKey(),
                'reviewed_at' => CarbonImmutable::now()->utc(),
                'notes' => $notes,
            ])->save();

            $this->audit->log('attendance.overtime_approved', [
                'actor' => $reviewer,
                'subject' => $approval,
                'metadata' => [
                    'attendance_record_id' => (string) $approval->attendance_record_id,
                    'calculated_minutes' => $calculated,
                    'approved_minutes' => $minutes,
                    'override' => $minutes > $calculated,
                ],
            ]);

            return $approval;
        });
    }

    public function reject(OvertimeApproval $approval, Model $reviewer, ?string $notes = null): OvertimeApproval
    {
        $this->assertReviewable($approval, $reviewer);

        return DB::transaction(function () use ($approval, $reviewer, $notes) {
            $approval->fill([
                'status' => OvertimeStatus::Rejected,
                'approved_minutes' => 0,
                'reviewed_by_user_id' => (string) $reviewer->getKey(),
                'reviewed_at' => CarbonImmutable::now()->utc(),
                'notes' => $notes,
            ])->save();

            $this->audit->log('attendance.overtime_rejected', [
                'actor' => $reviewer,
                'subject' => $approval,
                'metadata' => ['attendance_record_id' => (string) $approval->attendance_record_id],
            ]);

            return $approval;
        });
    }

    /** A pending approval reviewed by someone OTHER than the overtime's employee. */
    private function assertReviewable(OvertimeApproval $approval, Model $reviewer): void
    {
        if ($approval->status->isTerminal()) {
            $this->fail(__('attendance.overtime_reviewed'));
        }

        $employeeUserId = $approval->employee?->user_id;
        if ($employeeUserId !== null && (string) $reviewer->getKey() === (string) $employeeUserId) {
            $this->fail(__('attendance.overtime_self'));
        }
    }

    private function assertFresh(AttendanceRecord $record, ?int $expectedRecordVersion): void
    {
        if ($expectedRecordVersion !== null && (int) $record->version !== $expectedRecordVersion) {
            $this->fail(__('attendance.overtime_stale'));
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['attendance' => [$message]]);
    }
}
