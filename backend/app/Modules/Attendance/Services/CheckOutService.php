<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Enums\AttendanceEventType;
use App\Modules\Attendance\Models\AttendanceEvent;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Models\AttendanceSession;
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
 * Closes the employee's single OPEN session. The server sets the instant and
 * recomputes that session's worked/early-leave/overtime minutes from the SNAPSHOT
 * captured at its check-in — so later schedule edits never rewrite closed history.
 * The daily attendance_record is then re-aggregated from all its sessions.
 * Transactional + advisory-locked; idempotent on client_request_id.
 */
class CheckOutService
{
    public function __construct(
        private readonly AttendanceSettingsService $settings,
        private readonly GeofenceService $geofence,
        private readonly AttendanceCalculator $calculator,
        private readonly AttendanceRecordAggregator $aggregator,
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {}

    public function checkOut(Employee $employee, PunchInput $input, mixed $actor = null, ?CarbonImmutable $now = null): AttendanceRecord
    {
        $now = ($now ?? CarbonImmutable::now())->utc();

        return DB::transaction(function () use ($employee, $input, $actor, $now) {
            AttendanceLock::forEmployee((string) $this->context->tenantId(), (string) $employee->getKey());

            if ($replay = $this->replay($employee, $input)) {
                return $replay;
            }

            $session = AttendanceSession::query()
                ->where('employee_id', $employee->getKey())
                ->whereNull('check_out_at')
                ->lockForUpdate()
                ->first();

            if ($session === null) {
                $this->reject(__('attendance.no_open'));
            }

            $checkIn = CarbonImmutable::parse($session->check_in_at);
            if ($now->lessThan($checkIn)) {
                $this->reject(__('attendance.checkout_before_checkin'));
            }

            $settings = $this->settings->current();
            $this->assertGpsAcceptable($input, $settings);

            $geo = $this->geofence->evaluate($input->latitude, $input->longitude, $input->accuracyMeters);

            // Recompute this session against ITS OWN frozen segment snapshot.
            $resolved = ResolvedWorkDay::fromSessionSnapshot($session);
            $computation = $this->calculator->compute($resolved, $checkIn, $now);

            $session->fill([
                'check_out_at' => $now,
                'worked_minutes' => $computation->workedMinutes,
                'break_minutes' => $computation->breakMinutes,
                'late_minutes' => $computation->lateMinutes,
                'early_leave_minutes' => $computation->earlyLeaveMinutes,
                'overtime_minutes' => $computation->overtimeMinutes,
                'check_out_latitude' => $input->latitude,
                'check_out_longitude' => $input->longitude,
                'check_out_inside_geofence' => $input->hasCoordinates() ? $geo->inside : null,
                'check_out_location_id' => $geo->matchedLocationId,
            ])->save();

            $record = $session->record()->lockForUpdate()->first();

            AttendanceEvent::query()->create([
                'employee_id' => $employee->getKey(),
                'attendance_record_id' => $record->getKey(),
                'event_type' => AttendanceEventType::CheckOut,
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

            $record = $this->aggregator->aggregate($record);

            $this->audit->log('attendance.checked_out', [
                'actor' => $actor,
                'subject' => $record,
                'metadata' => [
                    'employee_id' => (string) $employee->getKey(),
                    'work_date' => $record->work_date->toDateString(),
                    'session_id' => (string) $session->getKey(),
                    'worked_minutes' => $computation->workedMinutes,
                    'overtime_minutes' => $computation->overtimeMinutes,
                ],
            ]);

            return $record;
        });
    }

    private function replay(Employee $employee, PunchInput $input): ?AttendanceRecord
    {
        if ($input->clientRequestId === null) {
            return null;
        }

        $event = AttendanceEvent::query()
            ->where('employee_id', $employee->getKey())
            ->where('client_request_id', $input->clientRequestId)
            ->where('event_type', AttendanceEventType::CheckOut->value)
            ->first();

        return $event?->record;
    }

    private function assertGpsAcceptable(PunchInput $input, $settings): void
    {
        if (($settings->require_gps || $settings->geofence_required) && ! $input->hasCoordinates()) {
            $this->reject(__('attendance.location_required_out'));
        }

        if ($settings->min_gps_accuracy_meters !== null
            && $input->accuracyMeters !== null
            && $input->accuracyMeters > $settings->min_gps_accuracy_meters) {
            $this->reject(__('attendance.gps_inaccurate_out'));
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
