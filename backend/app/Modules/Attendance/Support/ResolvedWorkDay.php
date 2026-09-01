<?php

namespace App\Modules\Attendance\Support;

use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Models\WorkSchedule;
use App\Modules\Attendance\Models\WorkScheduleDay;
use Carbon\CarbonImmutable;

/**
 * The fully-resolved schedule context for one employee on one work_date. This is
 * the SERVER's authoritative picture of what was expected — schedule boundaries
 * are computed in the schedule timezone and returned in UTC, ready to be
 * snapshot onto an attendance_record. Immutable by construction.
 */
final class ResolvedWorkDay
{
    public function __construct(
        public readonly ?WorkSchedule $schedule,
        public readonly ?WorkScheduleDay $day,
        public readonly CarbonImmutable $workDate,     // date in schedule timezone
        public readonly string $timezone,
        public readonly bool $isWorkingDay,
        public readonly ?CarbonImmutable $scheduledStartAt, // UTC
        public readonly ?CarbonImmutable $scheduledEndAt,   // UTC (next day if overnight)
        public readonly int $graceMinutes,
        public readonly int $breakMinutes,
        public readonly int $overtimeAfterMinutes,
    ) {}

    /**
     * Rebuild the resolved-day context from a record's FROZEN snapshot, so a
     * recomputation (check-out, correction) uses the boundaries captured at
     * check-in — later schedule edits never rewrite closed history.
     */
    public static function fromRecordSnapshot(AttendanceRecord $record): self
    {
        $hasWindow = $record->scheduled_start_at !== null && $record->scheduled_end_at !== null;

        return new self(
            schedule: $record->schedule,
            day: null,
            workDate: CarbonImmutable::parse($record->work_date),
            timezone: $record->timezone,
            isWorkingDay: $hasWindow,
            scheduledStartAt: $hasWindow ? CarbonImmutable::parse($record->scheduled_start_at) : null,
            scheduledEndAt: $hasWindow ? CarbonImmutable::parse($record->scheduled_end_at) : null,
            graceMinutes: $record->grace_minutes,
            breakMinutes: $record->break_minutes,
            overtimeAfterMinutes: $record->schedule?->overtime_after_minutes ?? 0,
        );
    }

    /** True when no schedule assignment covers this employee on this date. */
    public function hasSchedule(): bool
    {
        return $this->schedule !== null;
    }

    /** True when a schedule exists AND this weekday is a working day with hours. */
    public function isScheduledWorkingDay(): bool
    {
        return $this->schedule !== null
            && $this->isWorkingDay
            && $this->scheduledStartAt !== null
            && $this->scheduledEndAt !== null;
    }
}
