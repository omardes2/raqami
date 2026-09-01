<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Enums\AttendanceEventType;
use App\Modules\Attendance\Models\AttendanceEvent;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Support\AttendanceEligibility;
use App\Modules\Attendance\Support\AttendanceLock;
use App\Modules\Attendance\Support\PunchInput;
use App\Modules\Attendance\Support\ResolvedWorkDay;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Employees\Models\Employee;
use App\Modules\Tenancy\Services\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records a check-in. The SERVER decides everything: the instant (its own clock),
 * the applicable schedule, whether the punch is inside a geofence, and the
 * resulting status. The client only submits raw facts (coordinates). The whole
 * operation is one transaction guarded by an advisory lock + row locks, so
 * concurrent retries can never create two open records.
 */
class CheckInService
{
    public function __construct(
        private readonly AttendanceSettingsService $settings,
        private readonly ScheduleResolver $resolver,
        private readonly GeofenceService $geofence,
        private readonly AttendanceCalculator $calculator,
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {}

    public function checkIn(Employee $employee, PunchInput $input, mixed $actor = null, ?CarbonImmutable $now = null): AttendanceRecord
    {
        $now = ($now ?? CarbonImmutable::now())->utc();

        if (! AttendanceEligibility::isEligible($employee)) {
            $this->reject('This employee is not eligible to record attendance.');
        }

        return DB::transaction(function () use ($employee, $input, $actor, $now) {
            AttendanceLock::forEmployee((string) $this->context->tenantId(), (string) $employee->getKey());

            if ($replay = $this->replay($employee, $input)) {
                return $replay;
            }

            $this->assertNoOpenRecord($employee);

            $settings = $this->settings->current();
            $resolved = $this->resolver->resolveWorkDay($employee, $now, $settings->default_timezone);

            $this->assertSchedulingAllowed($resolved, $settings);
            $this->assertGpsAcceptable($input, $settings);
            $this->assertWithinCheckInWindow($resolved, $settings, $now);

            $this->assertNoRecordForDate($employee, $resolved->workDate->toDateString());

            $geo = $this->geofence->evaluate($input->latitude, $input->longitude, $input->accuracyMeters);
            $computation = $this->calculator->compute($resolved, $now, null);

            $record = AttendanceRecord::query()->create([
                'employee_id' => $employee->getKey(),
                'work_schedule_id' => $resolved->schedule?->getKey(),
                'work_date' => $resolved->workDate->toDateString(),
                'timezone' => $resolved->timezone,
                'scheduled_start_at' => $resolved->scheduledStartAt,
                'scheduled_end_at' => $resolved->scheduledEndAt,
                'check_in_at' => $now,
                'grace_minutes' => $resolved->graceMinutes,
                'break_minutes' => $computation->breakMinutes,
                'late_minutes' => $computation->lateMinutes,
                'status' => $computation->status,
                'source' => $input->source,
                'check_in_latitude' => $input->latitude,
                'check_in_longitude' => $input->longitude,
                'check_in_inside_geofence' => $input->hasCoordinates() ? $geo->inside : null,
                'check_in_location_id' => $geo->matchedLocationId,
            ]);

            AttendanceEvent::query()->create([
                'employee_id' => $employee->getKey(),
                'attendance_record_id' => $record->getKey(),
                'event_type' => AttendanceEventType::CheckIn,
                'source' => $input->source,
                'occurred_at' => $now,
                'latitude' => $input->latitude,
                'longitude' => $input->longitude,
                'accuracy_meters' => $input->accuracyMeters,
                'matched_location_id' => $geo->matchedLocationId,
                'distance_meters' => $geo->distanceMeters,
                'inside_geofence' => $input->hasCoordinates() ? $geo->inside : null,
                'metadata' => $input->metadata ?: null,
                'created_by_user_id' => $this->actorId($actor),
                'client_request_id' => $input->clientRequestId,
            ]);

            $this->audit->log('attendance.checked_in', [
                'actor' => $actor,
                'subject' => $record,
                'metadata' => [
                    'employee_id' => (string) $employee->getKey(),
                    'work_date' => $record->work_date->toDateString(),
                    'status' => $computation->status->value,
                    'inside_geofence' => $record->check_in_inside_geofence,
                ],
            ]);

            return $record;
        });
    }

    /** Idempotent replay: same client_request_id already recorded → its record. */
    private function replay(Employee $employee, PunchInput $input): ?AttendanceRecord
    {
        if ($input->clientRequestId === null) {
            return null;
        }

        $event = AttendanceEvent::query()
            ->where('employee_id', $employee->getKey())
            ->where('client_request_id', $input->clientRequestId)
            ->where('event_type', AttendanceEventType::CheckIn->value)
            ->first();

        return $event?->record;
    }

    private function assertNoOpenRecord(Employee $employee): void
    {
        $open = AttendanceRecord::query()
            ->where('employee_id', $employee->getKey())
            ->whereNotNull('check_in_at')
            ->whereNull('check_out_at')
            ->lockForUpdate()
            ->first();

        if ($open !== null) {
            $this->reject('This employee already has an open attendance; check out first.');
        }
    }

    private function assertNoRecordForDate(Employee $employee, string $workDate): void
    {
        $exists = AttendanceRecord::query()
            ->where('employee_id', $employee->getKey())
            ->whereDate('work_date', $workDate)
            ->lockForUpdate()
            ->exists();

        if ($exists) {
            $this->reject('This employee already has attendance recorded for this day.');
        }
    }

    private function assertSchedulingAllowed(ResolvedWorkDay $resolved, $settings): void
    {
        if (! $resolved->hasSchedule() && ! $settings->allow_unscheduled_work) {
            $this->reject('No work schedule is assigned for this day.');
        }

        if ($resolved->hasSchedule() && ! $resolved->isScheduledWorkingDay() && ! $settings->allow_unscheduled_work) {
            $this->reject('Today is not a scheduled working day.');
        }
    }

    private function assertGpsAcceptable(PunchInput $input, $settings): void
    {
        if (($settings->require_gps || $settings->geofence_required) && ! $input->hasCoordinates()) {
            $this->reject('Location is required to check in.');
        }

        if ($settings->min_gps_accuracy_meters !== null
            && $input->accuracyMeters !== null
            && $input->accuracyMeters > $settings->min_gps_accuracy_meters) {
            $this->reject('The GPS reading is not accurate enough to check in.');
        }

        if ($settings->geofence_required) {
            $geo = $this->geofence->evaluate($input->latitude, $input->longitude, $input->accuracyMeters);
            if (! $geo->accuracyAcceptable) {
                $this->reject('The GPS reading is not accurate enough to check in.');
            }
            if (! $geo->inside) {
                $this->reject('You are outside the allowed check-in area.');
            }
        }
    }

    private function assertWithinCheckInWindow(ResolvedWorkDay $resolved, $settings, CarbonImmutable $now): void
    {
        if (! $resolved->isScheduledWorkingDay()) {
            return;
        }

        $start = $resolved->scheduledStartAt;

        if ($now->lessThan($start)) {
            if (! $settings->allow_early_check_in) {
                $this->reject('Early check-in is not allowed.');
            }
            $earliest = $start->subMinutes($settings->early_check_in_window_minutes);
            if ($now->lessThan($earliest)) {
                $this->reject('It is too early to check in.');
            }
        }

        $graceEnd = $start->addMinutes($resolved->graceMinutes);
        if ($now->greaterThan($graceEnd) && ! $settings->allow_late_check_in) {
            $this->reject('Late check-in is not allowed.');
        }
    }

    private function actorId(mixed $actor): ?string
    {
        return $actor instanceof Model ? (string) $actor->getKey() : null;
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['attendance' => [$message]]);
    }
}
