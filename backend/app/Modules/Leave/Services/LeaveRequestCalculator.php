<?php

namespace App\Modules\Leave\Services;

use App\Modules\Attendance\Services\AttendanceSettingsService;
use App\Modules\Attendance\Services\HolidayResolver;
use App\Modules\Attendance\Services\ScheduleResolver;
use App\Modules\Employees\Models\Employee;
use App\Modules\Leave\Enums\ConsumptionBasis;
use App\Modules\Leave\Enums\LeaveDayExclusionReason;
use App\Modules\Leave\Enums\LeaveRequestKind;
use App\Modules\Leave\Models\LeavePolicy;
use App\Modules\Leave\Support\CoverageCalculator;
use App\Modules\Leave\Support\LeaveComputation;
use App\Modules\Leave\Support\LeaveDayComputation;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The SERVER's authoritative per-day computation of a leave request. For each
 * logical work_date it resolves the schedule (Sprint 4 ScheduleResolver, incl.
 * split shifts / rotation / overnight) and holidays (HolidayResolver), then
 * derives COVERAGE (expected-work minutes the leave covers, for attendance) and
 * CONSUMPTION (balance minutes, per the policy's consumption_basis, D7). Halves
 * are geometric over expected work minutes (CoverageCalculator). Deterministic;
 * the client never supplies coverage. Used for both preview and authoritative
 * submission (the submit path re-runs this inside the transaction).
 */
class LeaveRequestCalculator
{
    public function __construct(
        private readonly ScheduleResolver $scheduleResolver,
        private readonly HolidayResolver $holidayResolver,
        private readonly AttendanceSettingsService $attendanceSettings,
        private readonly CoverageCalculator $coverage,
    ) {}

    /**
     * @param  CarbonInterface|null  $periodStart  entitlement window (days outside are excluded)
     */
    public function compute(
        Employee $employee,
        LeavePolicy $policy,
        LeaveRequestKind $kind,
        CarbonInterface $startsOn,
        CarbonInterface $endsOn,
        ?CarbonInterface $periodStart = null,
        ?CarbonInterface $periodEnd = null,
    ): LeaveComputation {
        $fallbackTz = $this->attendanceSettings->current()->default_timezone ?: 'UTC';

        $start = CarbonImmutable::parse($startsOn)->startOfDay();
        $end = CarbonImmutable::parse($endsOn)->startOfDay();
        $pStart = $periodStart ? CarbonImmutable::parse($periodStart)->startOfDay() : null;
        $pEnd = $periodEnd ? CarbonImmutable::parse($periodEnd)->startOfDay() : null;

        $days = [];
        $totalConsumption = 0;
        $totalCoverage = 0;

        for ($date = $start; $date->lessThanOrEqualTo($end); $date = $date->addDay()) {
            $day = $this->computeDay($employee, $policy, $kind, $date, $fallbackTz, $pStart, $pEnd);
            $days[] = $day;
            $totalConsumption += $day->consumptionMinutes;
            $totalCoverage += $day->coverageMinutes;
        }

        return new LeaveComputation($days, $totalConsumption, $totalCoverage);
    }

    private function computeDay(
        Employee $employee,
        LeavePolicy $policy,
        LeaveRequestKind $kind,
        CarbonImmutable $date,
        string $fallbackTz,
        ?CarbonImmutable $periodStart,
        ?CarbonImmutable $periodEnd,
    ): LeaveDayComputation {
        // Resolve the work day at local noon so overnight reach-back is stable.
        $noonUtc = CarbonImmutable::parse($date->toDateString().' 12:00:00', $fallbackTz)->utc();
        $resolved = $this->scheduleResolver->resolveWorkDay($employee, $noonUtc, $fallbackTz);
        $timezone = $resolved->timezone;
        $workDate = $resolved->workDate;

        $holiday = $this->holidayResolver->resolve($employee->branch_id, $workDate);
        $isHoliday = $holiday !== null;
        $isWorkingDay = $resolved->isScheduledWorkingDay();

        $basis = $policy->consumption_basis instanceof ConsumptionBasis
            ? $policy->consumption_basis
            : ConsumptionBasis::ScheduledMinutes;
        $nominal = (int) ($policy->nominal_day_minutes ?? 0);

        // Ordered expected-work segments as [start,end] UTC pairs.
        $segments = array_map(
            fn ($seg) => [$seg->startAt, $seg->endAt],
            $resolved->segments,
        );
        $scheduledMinutes = 0;
        foreach ($segments as [$s, $e]) {
            $scheduledMinutes += (int) round($s->diffInMinutes($e, false));
        }

        $scheduleSnapshot = [
            'is_working_day' => $isWorkingDay,
            'work_schedule_id' => $resolved->schedule?->getKey(),
            'segments' => array_map(fn ($seg) => [
                'start_at' => $seg->startAt->utc()->toIso8601String(),
                'end_at' => $seg->endAt->utc()->toIso8601String(),
            ], $resolved->segments),
        ];
        $holidaySnapshot = $isHoliday ? [
            'id' => (string) $holiday->getKey(),
            'name' => $holiday->name ?? null,
            'date' => $holiday->date?->toDateString(),
        ] : null;

        // --- Exclusion (whether this date participates in consumption) ---
        $excluded = $this->exclusionReason($isHoliday, $isWorkingDay, $policy, $date, $workDate, $periodStart, $periodEnd);

        // --- Coverage (expected work leave covers → attendance) ---
        // Only a scheduled working day that is NOT a holiday has expected work.
        $coverageIntervals = [];
        $coverageMinutes = 0;
        if ($isWorkingDay && ! $isHoliday) {
            $cov = $this->coverage->coverage($segments, $kind);
            $coverageIntervals = $cov['intervals'];
            $coverageMinutes = $cov['minutes'];
        }

        // --- Consumption (balance) ---
        $consumptionMinutes = 0;
        if ($excluded === null) {
            $consumptionMinutes = $basis === ConsumptionBasis::NominalCalendarDay
                ? $this->nominalPortion($nominal, $kind)
                : $coverageMinutes; // scheduled basis: consume the covered expected work
        }

        return new LeaveDayComputation(
            workDate: $workDate->toDateString(),
            timezone: $timezone,
            scheduledMinutes: $scheduledMinutes,
            coverageMinutes: $coverageMinutes,
            consumptionMinutes: $consumptionMinutes,
            portion: $kind,
            coverageIntervals: $coverageIntervals,
            consumptionBasis: $basis,
            nominalDayMinutes: $basis === ConsumptionBasis::NominalCalendarDay ? $nominal : null,
            excludedReason: $excluded,
            holidayId: $holidaySnapshot['id'] ?? null,
            holidaySnapshot: $holidaySnapshot,
            scheduleSnapshot: $scheduleSnapshot,
        );
    }

    private function exclusionReason(
        bool $isHoliday,
        bool $isWorkingDay,
        LeavePolicy $policy,
        CarbonImmutable $date,
        CarbonImmutable $workDate,
        ?CarbonImmutable $periodStart,
        ?CarbonImmutable $periodEnd,
    ): ?string {
        if ($periodStart !== null && $workDate->lessThan($periodStart)) {
            return LeaveDayExclusionReason::OutsidePeriod->value;
        }
        if ($periodEnd !== null && $workDate->greaterThan($periodEnd)) {
            return LeaveDayExclusionReason::OutsidePeriod->value;
        }
        if ($isHoliday && ! $policy->count_holidays) {
            return LeaveDayExclusionReason::Holiday->value;
        }
        if (! $isWorkingDay && ! $isHoliday && ! $policy->count_non_working_days) {
            return LeaveDayExclusionReason::NonWorkingDay->value;
        }

        return null;
    }

    /** Nominal minutes for a portion (full=nominal; halves ceil/floor of nominal). */
    private function nominalPortion(int $nominal, LeaveRequestKind $kind): int
    {
        return match ($kind) {
            LeaveRequestKind::FullDay => $nominal,
            LeaveRequestKind::FirstHalf => intdiv($nominal, 2) + ($nominal % 2),
            LeaveRequestKind::SecondHalf => intdiv($nominal, 2),
        };
    }
}
