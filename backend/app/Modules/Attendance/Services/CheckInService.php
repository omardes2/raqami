<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Enums\AttendanceEventType;
use App\Modules\Attendance\Enums\AttendanceSource;
use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Attendance\Models\AttendanceEvent;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Models\AttendanceSession;
use App\Modules\Attendance\Support\AttendanceEligibility;
use App\Modules\Attendance\Support\AttendanceLock;
use App\Modules\Attendance\Support\PunchInput;
use App\Modules\Attendance\Support\ResolvedWorkDay;
use App\Modules\Attendance\Support\ScheduledSegment;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Employees\Models\Employee;
use App\Modules\Leave\Services\LeaveResolver;
use App\Modules\Leave\Support\IntervalMath;
use App\Modules\Tenancy\Services\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records a check-in as a new SESSION. The SERVER decides the instant, schedule,
 * geofence, and status; the client sends only facts. Split shifts are supported:
 * a work_date may hold several closed sessions, but AT MOST ONE open session per
 * employee (advisory lock + partial unique index). The daily attendance_record is
 * re-aggregated from its sessions. Idempotent on client_request_id.
 */
class CheckInService
{
    public function __construct(
        private readonly AttendanceSettingsService $settings,
        private readonly ScheduleResolver $resolver,
        private readonly GeofenceService $geofence,
        private readonly AttendanceCalculator $calculator,
        private readonly AttendanceRecordAggregator $aggregator,
        private readonly ExceptionResolver $exceptions,
        private readonly HolidayResolver $holidays,
        private readonly LeaveResolver $leave,
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {}

    public function checkIn(Employee $employee, PunchInput $input, mixed $actor = null, ?CarbonImmutable $now = null): AttendanceRecord
    {
        $now = ($now ?? CarbonImmutable::now())->utc();

        if (! AttendanceEligibility::isEligible($employee)) {
            $this->reject(__('attendance.not_eligible'));
        }

        return DB::transaction(function () use ($employee, $input, $actor, $now) {
            AttendanceLock::forEmployee((string) $this->context->tenantId(), (string) $employee->getKey());

            if ($replay = $this->replay($employee, $input)) {
                return $replay;
            }

            $this->assertNoOpenSession($employee);

            $settings = $this->settings->current();
            // Resolution order: eligibility (above) → holiday → schedule → exception
            // → off-day/holiday policy → mode/geofence.
            $resolved = $this->resolver->resolveWorkDay($employee, $now, $settings->default_timezone);
            $holiday = $this->holidays->resolve($employee->branch_id, $resolved->workDate);
            $exception = $this->exceptions->resolve($employee, $resolved->workDate);

            $this->assertSchedulingAllowed($resolved, $settings, $exception, $holiday !== null);
            $this->assertGpsAcceptable($input, $settings, $resolved, $exception);
            $this->assertWithinCheckInWindow($resolved, $settings, $now);

            $record = $this->recordForDay($employee, $resolved, $settings, $exception);
            $this->assertDailySessionsAllowed($record, $settings);

            // The active segment for this punch drives the session snapshot + math.
            $segment = $resolved->segmentFor($now);
            $active = $segment !== null ? $resolved->forSegment($segment) : $resolved;

            // Approved leave covering part of this segment shifts the REMAINING
            // expected window, so a punch during covered time is not counted late
            // (and early-leave/overtime at checkout use the same frozen window).
            $active = $this->adjustForApprovedLeave($active, $employee, $resolved->workDate);

            $this->assertNoOverlap($record, $now);

            $geo = $this->geofence->evaluate($input->latitude, $input->longitude, $input->accuracyMeters);
            $computation = $this->calculator->compute($active, $now, null);
            $sequence = (int) $record->sessions()->max('sequence') + 1;

            $session = AttendanceSession::query()->create([
                'attendance_record_id' => $record->getKey(),
                'employee_id' => $employee->getKey(),
                'sequence' => $sequence,
                'check_in_at' => $now,
                'scheduled_start_at' => $active->scheduledStartAt,
                'scheduled_end_at' => $active->scheduledEndAt,
                'grace_minutes' => $active->graceMinutes,
                'break_minutes' => $computation->breakMinutes,
                'late_minutes' => $computation->lateMinutes,
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

            $record = $this->aggregator->aggregate($record);

            $this->audit->log('attendance.checked_in', [
                'actor' => $actor,
                'subject' => $record,
                'metadata' => [
                    'employee_id' => (string) $employee->getKey(),
                    'work_date' => $record->work_date->toDateString(),
                    'session_id' => (string) $session->getKey(),
                    'status' => $record->status->value,
                ],
            ]);

            return $record;
        });
    }

    /**
     * If approved leave covers the leading part of the active segment, shift the
     * expected window to the remaining (uncovered) work so lateness/early-leave
     * are measured against real expectation. Half-day leave is always a prefix or
     * suffix of the segment (never the middle), so the remainder is contiguous.
     * A fully-covered segment (working during approved leave) is left unchanged.
     */
    private function adjustForApprovedLeave(ResolvedWorkDay $active, Employee $employee, CarbonImmutable $workDate): ResolvedWorkDay
    {
        if ($active->scheduledStartAt === null || $active->scheduledEndAt === null) {
            return $active;
        }

        $leave = $this->leave->resolve($employee, $workDate);
        if ($leave === null || ! $leave->hasCoverage()) {
            return $active;
        }

        $expected = [[
            'start_at' => $active->scheduledStartAt->utc()->toIso8601String(),
            'end_at' => $active->scheduledEndAt->utc()->toIso8601String(),
        ]];
        $remaining = IntervalMath::subtract($expected, $leave->coverageIntervals);

        if ($remaining === []) {
            return $active; // fully covered — preserve the punch, no adjustment
        }

        $newStart = CarbonImmutable::parse($remaining[0]['start_at']);
        $newEnd = CarbonImmutable::parse($remaining[count($remaining) - 1]['end_at']);

        if ($newStart->equalTo($active->scheduledStartAt) && $newEnd->equalTo($active->scheduledEndAt)) {
            return $active; // leave does not touch this segment's boundaries
        }

        $segment = new ScheduledSegment(
            $active->segments[0]->sequence ?? 1,
            $newStart,
            $newEnd,
            $active->graceMinutes,
            $active->breakMinutes,
            $active->overtimeAfterMinutes,
        );

        return $active->forSegment($segment);
    }

    /** Find or create the daily aggregate record for the resolved work day. */
    private function recordForDay(Employee $employee, ResolvedWorkDay $resolved, $settings, $exception): AttendanceRecord
    {
        $mode = $exception?->attendance_mode?->value
            ?? ($resolved->schedule ? $settings->default_attendance_mode : $settings->default_attendance_mode);

        $record = AttendanceRecord::query()
            ->where('employee_id', $employee->getKey())
            ->whereDate('work_date', $resolved->workDate->toDateString())
            ->lockForUpdate()
            ->first();

        if ($record !== null) {
            return $record;
        }

        return AttendanceRecord::query()->create([
            'employee_id' => $employee->getKey(),
            'work_schedule_id' => $resolved->schedule?->getKey(),
            'work_date' => $resolved->workDate->toDateString(),
            'timezone' => $resolved->timezone,
            'scheduled_start_at' => $resolved->scheduledStartAt,
            'scheduled_end_at' => $this->dayEnd($resolved),
            'grace_minutes' => $resolved->graceMinutes,
            'status' => AttendanceStatus::Present,
            'source' => $resolved->schedule ? AttendanceSource::Web : AttendanceSource::Web,
            'attendance_mode' => $mode,
        ]);
    }

    /** The end of the day's LAST segment (for the record-level reference window). */
    private function dayEnd(ResolvedWorkDay $resolved): ?CarbonImmutable
    {
        $last = null;
        foreach ($resolved->segments as $segment) {
            if ($last === null || $segment->endAt->greaterThan($last)) {
                $last = $segment->endAt;
            }
        }

        return $last;
    }

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

    private function assertNoOpenSession(Employee $employee): void
    {
        $open = AttendanceSession::query()
            ->where('employee_id', $employee->getKey())
            ->whereNull('check_out_at')
            ->lockForUpdate()
            ->exists();

        if ($open) {
            $this->reject(__('attendance.already_open'));
        }
    }

    private function assertDailySessionsAllowed(AttendanceRecord $record, $settings): void
    {
        $existing = $record->sessions()->count();
        if ($existing > 0 && ! $settings->allow_multiple_sessions) {
            $this->reject(__('attendance.already_recorded_today'));
        }
    }

    /** A new session's check-in must not fall inside an existing session window. */
    private function assertNoOverlap(AttendanceRecord $record, CarbonImmutable $now): void
    {
        $overlap = $record->sessions()
            ->where('check_in_at', '<=', $now)
            ->where(function ($q) use ($now) {
                $q->whereNull('check_out_at')->orWhere('check_out_at', '>=', $now);
            })
            ->exists();

        if ($overlap) {
            $this->reject(__('attendance.session_overlap'));
        }
    }

    private function assertSchedulingAllowed(ResolvedWorkDay $resolved, $settings, $exception, bool $isHoliday = false): void
    {
        // An authorized off-day/remote exception permits attendance regardless —
        // including working on a holiday.
        if ($exception !== null) {
            return;
        }

        // A holiday means the employee is NOT normally expected to work. Holiday
        // work follows the same explicit off-day policy as any non-working day.
        if ($isHoliday) {
            if ($settings->off_day_work_policy !== 'allow' && ! $settings->allow_unscheduled_work) {
                $this->reject(__('attendance.holiday_not_working_day'));
            }

            return;
        }

        if (! $resolved->hasSchedule() && ! $settings->allow_unscheduled_work) {
            $this->reject(__('attendance.no_schedule'));
        }

        // Off-day (scheduled but non-working) attendance without an authorizing
        // exception: 'allow' permits it, 'reject'/'require_approval' do not (the
        // exception above is the record of an approval). Never silently treated
        // as ordinary attendance.
        if ($resolved->hasSchedule() && ! $resolved->isScheduledWorkingDay() && ! $settings->allow_unscheduled_work) {
            if ($settings->off_day_work_policy !== 'allow') {
                $this->reject(__('attendance.not_working_day'));
            }
        }
    }

    private function assertGpsAcceptable(PunchInput $input, $settings, ResolvedWorkDay $resolved, $exception): void
    {
        // Remote/field mode (via exception) does not require the office geofence.
        $mode = $exception?->attendance_mode?->value ?? $settings->default_attendance_mode;
        $geofenceRequired = $settings->geofence_required && $mode === 'onsite';

        if (($settings->require_gps || $geofenceRequired) && ! $input->hasCoordinates()) {
            $this->reject(__('attendance.location_required'));
        }

        if ($settings->min_gps_accuracy_meters !== null
            && $input->accuracyMeters !== null
            && $input->accuracyMeters > $settings->min_gps_accuracy_meters) {
            $this->reject(__('attendance.gps_inaccurate'));
        }

        if ($geofenceRequired) {
            $geo = $this->geofence->evaluate($input->latitude, $input->longitude, $input->accuracyMeters);
            if (! $geo->accuracyAcceptable) {
                $this->reject(__('attendance.gps_inaccurate'));
            }
            if (! $geo->inside) {
                $this->reject(__('attendance.outside_geofence'));
            }
        }
    }

    private function assertWithinCheckInWindow(ResolvedWorkDay $resolved, $settings, CarbonImmutable $now): void
    {
        if (! $resolved->isScheduledWorkingDay()) {
            return;
        }

        // Windows are checked against the nearest segment's start.
        $segment = $resolved->segmentFor($now);
        $start = $segment?->startAt ?? $resolved->scheduledStartAt;
        $grace = $segment?->graceMinutes ?? $resolved->graceMinutes;

        if ($now->lessThan($start)) {
            if (! $settings->allow_early_check_in) {
                $this->reject(__('attendance.early_not_allowed'));
            }
            $earliest = $start->subMinutes($settings->early_check_in_window_minutes);
            if ($now->lessThan($earliest)) {
                $this->reject(__('attendance.too_early'));
            }
        }

        $graceEnd = $start->addMinutes($grace);
        if ($now->greaterThan($graceEnd) && ! $settings->allow_late_check_in) {
            $this->reject(__('attendance.late_not_allowed'));
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
