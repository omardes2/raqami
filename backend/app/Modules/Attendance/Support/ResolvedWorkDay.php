<?php

namespace App\Modules\Attendance\Support;

use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Models\AttendanceSession;
use App\Modules\Attendance\Models\WorkSchedule;
use App\Modules\Attendance\Models\WorkScheduleDay;
use Carbon\CarbonImmutable;

/**
 * The SERVER's authoritative picture of what was expected for one employee on one
 * work_date. Boundaries are computed in the schedule timezone and returned in UTC.
 *
 * Sprint 4: a day may hold several expected SEGMENTS (split shifts). The scalar
 * scheduledStartAt/EndAt/grace/break/overtimeAfter reflect the ACTIVE segment
 * (the one a given punch belongs to, or the first when none is targeted), so the
 * Sprint 3 single-segment calculator keeps working per session; $segments carries
 * the full day. Immutable by construction.
 */
final class ResolvedWorkDay
{
    /**
     * @param  array<int, ScheduledSegment>  $segments
     */
    public function __construct(
        public readonly ?WorkSchedule $schedule,
        public readonly ?WorkScheduleDay $day,
        public readonly CarbonImmutable $workDate,     // date in schedule timezone
        public readonly string $timezone,
        public readonly bool $isWorkingDay,
        public readonly ?CarbonImmutable $scheduledStartAt, // UTC (active segment)
        public readonly ?CarbonImmutable $scheduledEndAt,   // UTC (active segment)
        public readonly int $graceMinutes,
        public readonly int $breakMinutes,
        public readonly int $overtimeAfterMinutes,
        public readonly array $segments = [],
    ) {}

    /**
     * Rebuild the resolved-day context from a record's FROZEN snapshot (daily
     * aggregate boundaries) — used for record-level recomputation.
     */
    public static function fromRecordSnapshot(AttendanceRecord $record): self
    {
        return self::fromSnapshotValues(
            $record->schedule,
            $record->work_date,
            $record->timezone,
            $record->scheduled_start_at,
            $record->scheduled_end_at,
            $record->grace_minutes,
            $record->break_minutes,
            $record->schedule?->overtime_after_minutes ?? 0,
        );
    }

    /**
     * Rebuild from a single SESSION's frozen snapshot, so a per-session
     * recomputation uses the segment boundaries captured at that session's
     * check-in — later schedule edits never rewrite closed history.
     */
    public static function fromSessionSnapshot(AttendanceSession $session): self
    {
        return self::fromSnapshotValues(
            $session->record?->schedule,
            $session->record?->work_date ?? $session->check_in_at,
            $session->record?->timezone ?? 'UTC',
            $session->scheduled_start_at,
            $session->scheduled_end_at,
            $session->grace_minutes,
            $session->break_minutes,
            $session->record?->schedule?->overtime_after_minutes ?? 0,
        );
    }

    private static function fromSnapshotValues(
        ?WorkSchedule $schedule,
        mixed $workDate,
        string $timezone,
        mixed $start,
        mixed $end,
        int $grace,
        int $break,
        int $overtimeAfter,
    ): self {
        $hasWindow = $start !== null && $end !== null;
        $startAt = $hasWindow ? CarbonImmutable::parse($start) : null;
        $endAt = $hasWindow ? CarbonImmutable::parse($end) : null;

        $segments = $hasWindow
            ? [new ScheduledSegment(1, $startAt, $endAt, $grace, $break, $overtimeAfter)]
            : [];

        return new self(
            schedule: $schedule,
            day: null,
            workDate: CarbonImmutable::parse($workDate),
            timezone: $timezone,
            isWorkingDay: $hasWindow,
            scheduledStartAt: $startAt,
            scheduledEndAt: $endAt,
            graceMinutes: $grace,
            breakMinutes: $break,
            overtimeAfterMinutes: $overtimeAfter,
            segments: $segments,
        );
    }

    /** True when no schedule assignment covers this employee on this date. */
    public function hasSchedule(): bool
    {
        return $this->schedule !== null;
    }

    /** True when a schedule exists AND this day is a working day with hours. */
    public function isScheduledWorkingDay(): bool
    {
        return $this->schedule !== null
            && $this->isWorkingDay
            && $this->scheduledStartAt !== null
            && $this->scheduledEndAt !== null;
    }

    /**
     * The segment a punch instant belongs to: the one whose start is closest to
     * the instant. Deterministic; drives which segment a session is computed
     * against on split-shift days.
     */
    public function segmentFor(CarbonImmutable $instant): ?ScheduledSegment
    {
        $best = null;
        $bestDistance = null;

        foreach ($this->segments as $segment) {
            $distance = abs($segment->startAt->diffInSeconds($instant, false));
            if ($bestDistance === null || $distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $segment;
            }
        }

        return $best;
    }

    /** A single-segment view (scalars = $segment) for per-session calculation. */
    public function forSegment(ScheduledSegment $segment): self
    {
        return new self(
            schedule: $this->schedule,
            day: $this->day,
            workDate: $this->workDate,
            timezone: $this->timezone,
            isWorkingDay: true,
            scheduledStartAt: $segment->startAt,
            scheduledEndAt: $segment->endAt,
            graceMinutes: $segment->graceMinutes,
            breakMinutes: $segment->breakMinutes,
            overtimeAfterMinutes: $segment->overtimeAfterMinutes,
            segments: [$segment],
        );
    }
}
