<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Enums\AttendanceEventType;
use App\Modules\Attendance\Enums\AttendanceSource;
use App\Modules\Attendance\Models\AttendanceEvent;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Support\AttendanceEligibility;
use App\Modules\Attendance\Support\AttendanceLock;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Employees\Models\Employee;
use App\Modules\Tenancy\Services\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Authorized manual attendance entry. An HR/manager actor (never the client)
 * supplies explicit check-in / check-out instants on behalf of an employee — for
 * missed punches or offline work. The SERVER still computes all minutes and
 * status from those instants and snapshots the schedule; the entry is marked
 * is_manual + source=manual and fully audited. GPS is not involved.
 */
class ManualAttendanceService
{
    public function __construct(
        private readonly ScheduleResolver $resolver,
        private readonly AttendanceCalculator $calculator,
        private readonly AttendanceSettingsService $settings,
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  array{check_in_at:string, check_out_at?:?string, reason?:?string}  $data
     */
    public function record(Employee $employee, array $data, Model $actor): AttendanceRecord
    {
        if (! AttendanceEligibility::isEligible($employee)) {
            $this->reject(__('attendance.not_eligible'));
        }

        $checkIn = CarbonImmutable::parse($data['check_in_at'])->utc();
        $checkOut = isset($data['check_out_at']) && $data['check_out_at'] !== null
            ? CarbonImmutable::parse($data['check_out_at'])->utc()
            : null;

        if ($checkOut !== null && $checkOut->lessThanOrEqualTo($checkIn)) {
            $this->reject(__('attendance.checkout_after_checkin'));
        }

        return DB::transaction(function () use ($employee, $checkIn, $checkOut, $data, $actor) {
            AttendanceLock::forEmployee((string) $this->context->tenantId(), (string) $employee->getKey());

            $settings = $this->settings->current();
            $resolved = $this->resolver->resolveWorkDay($employee, $checkIn, $settings->default_timezone);
            $workDate = $resolved->workDate->toDateString();

            $exists = AttendanceRecord::query()
                ->where('employee_id', $employee->getKey())
                ->whereDate('work_date', $workDate)
                ->lockForUpdate()
                ->exists();

            if ($exists) {
                $this->reject(__('attendance.already_recorded_today'));
            }

            $computation = $this->calculator->compute($resolved, $checkIn, $checkOut);

            $record = AttendanceRecord::query()->create([
                'employee_id' => $employee->getKey(),
                'work_schedule_id' => $resolved->schedule?->getKey(),
                'work_date' => $workDate,
                'timezone' => $resolved->timezone,
                'scheduled_start_at' => $resolved->scheduledStartAt,
                'scheduled_end_at' => $resolved->scheduledEndAt,
                'check_in_at' => $checkIn,
                'check_out_at' => $checkOut,
                'worked_minutes' => $computation->workedMinutes,
                'break_minutes' => $computation->breakMinutes,
                'late_minutes' => $computation->lateMinutes,
                'early_leave_minutes' => $computation->earlyLeaveMinutes,
                'overtime_minutes' => $computation->overtimeMinutes,
                'grace_minutes' => $resolved->graceMinutes,
                'status' => $computation->status,
                'source' => AttendanceSource::Manual,
                'is_manual' => true,
            ]);

            $this->event($record, $employee, AttendanceEventType::ManualCheckIn, $checkIn, $actor, $data['reason'] ?? null);
            if ($checkOut !== null) {
                $this->event($record, $employee, AttendanceEventType::ManualCheckOut, $checkOut, $actor, $data['reason'] ?? null);
            }

            $this->audit->log('attendance.manual_recorded', [
                'actor' => $actor,
                'subject' => $record,
                'metadata' => [
                    'employee_id' => (string) $employee->getKey(),
                    'work_date' => $workDate,
                    'reason' => $data['reason'] ?? null,
                ],
            ]);

            return $record;
        });
    }

    private function event(AttendanceRecord $record, Employee $employee, AttendanceEventType $type, CarbonImmutable $at, Model $actor, ?string $reason): void
    {
        AttendanceEvent::query()->create([
            'employee_id' => $employee->getKey(),
            'attendance_record_id' => $record->getKey(),
            'event_type' => $type,
            'source' => AttendanceSource::Manual,
            'occurred_at' => $at,
            'metadata' => $reason !== null ? ['reason' => $reason] : null,
            'created_by_user_id' => (string) $actor->getKey(),
        ]);
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['attendance' => [$message]]);
    }
}
