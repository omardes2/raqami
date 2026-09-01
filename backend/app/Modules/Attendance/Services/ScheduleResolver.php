<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Enums\ScheduleScopeType;
use App\Modules\Attendance\Enums\WorkScheduleStatus;
use App\Modules\Attendance\Models\WorkSchedule;
use App\Modules\Attendance\Models\WorkScheduleAssignment;
use App\Modules\Attendance\Models\WorkScheduleDay;
use App\Modules\Attendance\Support\ResolvedWorkDay;
use App\Modules\Attendance\Support\ScheduledSegment;
use App\Modules\Employees\Models\Employee;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\TeamMembership;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The single, deterministic source of truth for "which schedule applies to this
 * employee on this date, and what were the expected hours in UTC". Every
 * attendance calculation flows through here so precedence is applied exactly
 * once, the same way, everywhere.
 *
 * Precedence (most specific wins): employee > team > department (deepest
 * ancestor first) > branch > company. Within a single level, the tie-break is
 * priority desc, then effective_from desc, then created_at desc, then id desc —
 * fully deterministic, never dependent on row order.
 *
 * Time model (V1): work_date is the schedule-timezone local date of the punch.
 * An overnight window (end_time <= start_time) extends scheduled_end_at into the
 * following day; it does NOT reach back to the previous calendar day. This is
 * intentionally simple and deterministic (see ADR).
 */
class ScheduleResolver
{
    /**
     * Resolve the effective schedule for an employee on a given local date.
     * Returns null when no active assignment covers the employee that day.
     */
    public function resolveSchedule(Employee $employee, CarbonInterface $localDate): ?WorkSchedule
    {
        $date = CarbonImmutable::parse($localDate)->toDateString();

        $candidates = $this->candidateAssignments($employee, $date);
        if ($candidates->isEmpty()) {
            return null;
        }

        foreach ($this->orderedScopeSelectors($employee) as $selector) {
            $matches = $candidates->filter(
                fn (WorkScheduleAssignment $a) => $a->scope_type === $selector['type']
                    && $a->scope_id === $selector['id']
            );

            if ($matches->isNotEmpty()) {
                return $this->pickBest($matches)->schedule;
            }
        }

        return null;
    }

    /**
     * Resolve the full work-day context for an employee at a specific instant.
     * The instant is server-authoritative UTC; $fallbackTimezone (tenant default)
     * is used only until a schedule (with its own timezone) is found.
     *
     * OVERNIGHT REACH-BACK: a punch after midnight that still falls inside the
     * PREVIOUS local day's overnight window (e.g. a 01:00 punch under a
     * Mon 22:00 -> Tue 06:00 shift) resolves to that previous work_date, not to
     * the calendar day it landed on. This keeps one overnight shift as one
     * work_date and makes lateness measure against the 22:00 start. Ordinary
     * daytime schedules are unaffected. Deterministic — no date guessing.
     */
    public function resolveWorkDay(Employee $employee, CarbonImmutable $instantUtc, string $fallbackTimezone): ResolvedWorkDay
    {
        // Bootstrap the local date with the tenant default tz, resolve the
        // schedule, then re-derive the authoritative date in the schedule's tz.
        $bootstrapDate = $instantUtc->setTimezone($fallbackTimezone)->startOfDay();
        $schedule = $this->resolveSchedule($employee, $bootstrapDate);

        // Try to attribute this instant to the previous local day's overnight
        // window before falling through to the current-day resolution.
        if ($schedule !== null) {
            $reachBack = $this->resolveOvernightReachBack($employee, $instantUtc, $schedule, $fallbackTimezone);
            if ($reachBack !== null) {
                return $reachBack;
            }
        }

        $timezone = $schedule?->timezone ?: $fallbackTimezone;
        $workDate = $instantUtc->setTimezone($timezone)->startOfDay();

        if ($schedule === null) {
            return new ResolvedWorkDay(
                schedule: null,
                day: null,
                workDate: $workDate,
                timezone: $timezone,
                isWorkingDay: false,
                scheduledStartAt: null,
                scheduledEndAt: null,
                graceMinutes: 0,
                breakMinutes: 0,
                overtimeAfterMinutes: 0,
            );
        }

        return $this->buildWorkDay($schedule, $this->dayFor($schedule, $workDate), $workDate, $timezone);
    }

    /**
     * If the instant falls inside the previous local day's overnight window,
     * return that day's resolved context; otherwise null (use the current day).
     */
    private function resolveOvernightReachBack(
        Employee $employee,
        CarbonImmutable $instantUtc,
        WorkSchedule $currentSchedule,
        string $fallbackTimezone,
    ): ?ResolvedWorkDay {
        $currentTz = $currentSchedule->timezone ?: $fallbackTimezone;
        $previousLocalDate = $instantUtc->setTimezone($currentTz)->startOfDay()->subDay();

        // The previous day may fall under a different assignment (effective dates).
        $previousSchedule = $this->resolveSchedule($employee, $previousLocalDate) ?? $currentSchedule;
        $previousTz = $previousSchedule->timezone ?: $fallbackTimezone;
        $previousLocalDate = $instantUtc->setTimezone($previousTz)->startOfDay()->subDay();

        $previousDay = $this->dayFor($previousSchedule, $previousLocalDate);
        if ($previousDay === null) {
            return null;
        }

        $resolvedPrevious = $this->buildWorkDay($previousSchedule, $previousDay, $previousLocalDate, $previousTz);

        // If the instant falls inside any of the previous day's segment windows
        // (a shift that began yesterday and runs past midnight), attribute it to
        // that previous work_date. Covers overnight AND overnight-split segments.
        foreach ($resolvedPrevious->segments as $segment) {
            if ($instantUtc->greaterThanOrEqualTo($segment->startAt) && $instantUtc->lessThan($segment->endAt)) {
                return $resolvedPrevious;
            }
        }

        return null;
    }

    /**
     * Assemble a ResolvedWorkDay for a concrete (schedule, day, work_date, tz),
     * resolving the day's expected SEGMENTS (split shifts) to UTC. Scalars reflect
     * the first segment; $segments carries the whole day.
     */
    private function buildWorkDay(WorkSchedule $schedule, ?WorkScheduleDay $day, CarbonImmutable $workDate, string $timezone): ResolvedWorkDay
    {
        $segments = ($day !== null && $day->is_working_day)
            ? $this->segmentsFor($day, $schedule, $workDate, $timezone)
            : [];

        $first = $segments[0] ?? null;

        return new ResolvedWorkDay(
            schedule: $schedule,
            day: $day,
            workDate: $workDate,
            timezone: $timezone,
            isWorkingDay: $segments !== [],
            scheduledStartAt: $first?->startAt,
            scheduledEndAt: $first?->endAt,
            graceMinutes: $first?->graceMinutes ?? ($day?->grace_minutes ?? $schedule->grace_minutes),
            breakMinutes: $first?->breakMinutes ?? ($day?->break_minutes ?? $schedule->break_minutes),
            overtimeAfterMinutes: $first?->overtimeAfterMinutes ?? $schedule->overtime_after_minutes,
            segments: $segments,
        );
    }

    /**
     * Resolve a day's expected segments to UTC, ordered by start. Falls back to
     * the day's own start/end (legacy single window) when no segment rows exist.
     *
     * @return array<int, ScheduledSegment>
     */
    private function segmentsFor(WorkScheduleDay $day, WorkSchedule $schedule, CarbonImmutable $workDate, string $timezone): array
    {
        $rows = $day->segments;
        if ($rows->isEmpty() && $day->start_time && $day->end_time) {
            // Legacy compatibility: synthesize one segment from the day window.
            [$startUtc, $endUtc] = $this->boundaries($workDate, $timezone, (string) $day->start_time, (string) $day->end_time);

            return [new ScheduledSegment(
                1, $startUtc, $endUtc,
                $day->grace_minutes ?? $schedule->grace_minutes,
                $day->break_minutes ?? $schedule->break_minutes,
                $schedule->overtime_after_minutes,
            )];
        }

        $segments = [];
        foreach ($rows as $row) {
            [$startUtc, $endUtc] = $this->boundaries($workDate, $timezone, (string) $row->start_time, (string) $row->end_time);
            $segments[] = new ScheduledSegment(
                (int) $row->sequence,
                $startUtc,
                $endUtc,
                $row->grace_minutes ?? $day->grace_minutes ?? $schedule->grace_minutes,
                $row->break_minutes ?? $day->break_minutes ?? $schedule->break_minutes,
                $row->overtime_after_minutes ?? $schedule->overtime_after_minutes,
            );
        }

        usort($segments, fn (ScheduledSegment $a, ScheduledSegment $b) => $a->startAt <=> $b->startAt);

        return $segments;
    }

    /**
     * The day-pattern that applies to $workDate: weekday (0-6) for weekly
     * schedules, or the cycle-day-index for rotating (cyclic) schedules.
     */
    private function dayIndexFor(WorkSchedule $schedule, CarbonImmutable $workDate): int
    {
        if (! $schedule->isCyclic()) {
            return (int) $workDate->dayOfWeek;
        }

        $anchor = CarbonImmutable::parse($schedule->anchor_date)->startOfDay();
        $length = (int) $schedule->cycle_length_days;
        $daysSince = (int) floor($anchor->diffInDays($workDate, false));

        return (($daysSince % $length) + $length) % $length;
    }

    /**
     * Convert a local start/end time on $workDate (in $timezone) to UTC. An
     * overnight window (end <= start) pushes the end to the next calendar day.
     *
     * @return array{0:CarbonImmutable,1:CarbonImmutable}
     */
    private function boundaries(CarbonImmutable $workDate, string $timezone, string $startTime, string $endTime): array
    {
        $dateStr = $workDate->toDateString();
        $start = CarbonImmutable::parse("{$dateStr} {$startTime}", $timezone);
        $end = CarbonImmutable::parse("{$dateStr} {$endTime}", $timezone);

        if ($end->lessThanOrEqualTo($start)) {
            $end = $end->addDay();
        }

        return [$start->utc(), $end->utc()];
    }

    /** The WorkScheduleDay for a work_date (weekday or cycle-day), segments loaded. */
    private function dayFor(WorkSchedule $schedule, CarbonImmutable $workDate): ?WorkScheduleDay
    {
        $index = $this->dayIndexFor($schedule, $workDate);

        return $schedule->days()->with('segments')->where('weekday', $index)->first();
    }

    /**
     * All active assignments (active schedule) effective on $date for the scopes
     * this employee belongs to. Loaded once, ranked in PHP for determinism.
     *
     * @return Collection<int, WorkScheduleAssignment>
     */
    private function candidateAssignments(Employee $employee, string $date): Collection
    {
        $selectors = $this->orderedScopeSelectors($employee);

        $query = WorkScheduleAssignment::query()
            ->with('schedule')
            ->whereHas('schedule', fn ($q) => $q->where('status', WorkScheduleStatus::Active->value))
            ->whereDate('effective_from', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $date);
            })
            ->where(function ($q) use ($selectors) {
                foreach ($selectors as $s) {
                    $q->orWhere(function ($inner) use ($s) {
                        $inner->where('scope_type', $s['type']->value);
                        $s['id'] === null
                            ? $inner->whereNull('scope_id')
                            : $inner->where('scope_id', $s['id']);
                    });
                }
            });

        return $query->get();
    }

    /**
     * The ordered list of scope selectors for an employee, most specific first.
     * Department ancestors are ordered deepest (closest to the employee) first.
     *
     * @return array<int, array{type:ScheduleScopeType, id:?string}>
     */
    private function orderedScopeSelectors(Employee $employee): array
    {
        $selectors = [];

        $selectors[] = ['type' => ScheduleScopeType::Employee, 'id' => (string) $employee->getKey()];

        foreach ($this->teamIds($employee) as $teamId) {
            $selectors[] = ['type' => ScheduleScopeType::Team, 'id' => $teamId];
        }

        foreach ($this->departmentChain($employee->department_id) as $deptId) {
            $selectors[] = ['type' => ScheduleScopeType::Department, 'id' => $deptId];
        }

        if ($employee->branch_id) {
            $selectors[] = ['type' => ScheduleScopeType::Branch, 'id' => (string) $employee->branch_id];
        }

        $selectors[] = ['type' => ScheduleScopeType::Company, 'id' => null];

        return $selectors;
    }

    /** @return array<int, string> */
    private function teamIds(Employee $employee): array
    {
        return TeamMembership::query()
            ->where('employee_id', $employee->getKey())
            ->pluck('team_id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    /**
     * The employee's department id followed by each ancestor, deepest first.
     *
     * @return array<int, string>
     */
    private function departmentChain(?string $departmentId): array
    {
        if ($departmentId === null) {
            return [];
        }

        // Load the tenant department tree once (id -> parent_id).
        $parents = Department::query()
            ->select(['id', 'parent_department_id'])
            ->get()
            ->keyBy('id');

        $chain = [];
        $current = $departmentId;
        // Guard against cycles with a visited set.
        while ($current !== null && ! in_array($current, $chain, true)) {
            $chain[] = (string) $current;
            $current = $parents->get($current)?->parent_department_id;
        }

        return $chain;
    }

    /**
     * Deterministic tie-break within one precedence level.
     *
     * @param  Collection<int, WorkScheduleAssignment>  $matches
     */
    private function pickBest(Collection $matches): WorkScheduleAssignment
    {
        return $matches->sort(function (WorkScheduleAssignment $a, WorkScheduleAssignment $b) {
            return [$b->priority, $b->effective_from->timestamp, $b->created_at->timestamp, $b->id]
                <=> [$a->priority, $a->effective_from->timestamp, $a->created_at->timestamp, $a->id];
        })->first();
    }
}
