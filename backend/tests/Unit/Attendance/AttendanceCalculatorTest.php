<?php

namespace Tests\Unit\Attendance;

use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Attendance\Models\WorkSchedule;
use App\Modules\Attendance\Services\AttendanceCalculator;
use App\Modules\Attendance\Support\ResolvedWorkDay;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The AttendanceCalculator is the single source of truth for attendance minutes.
 * These are pure computations — no DB, no clock, no client input — so the SERVER
 * always decides lateness/overtime/worked time the same, testable way.
 */
class AttendanceCalculatorTest extends TestCase
{
    private function workingDay(
        string $startUtc,
        string $endUtc,
        int $grace = 15,
        int $break = 0,
        int $overtimeAfter = 0,
    ): ResolvedWorkDay {
        return new ResolvedWorkDay(
            schedule: new WorkSchedule,
            day: null,
            workDate: CarbonImmutable::parse('2026-03-01'),
            timezone: 'UTC',
            isWorkingDay: true,
            scheduledStartAt: CarbonImmutable::parse($startUtc),
            scheduledEndAt: CarbonImmutable::parse($endUtc),
            graceMinutes: $grace,
            breakMinutes: $break,
            overtimeAfterMinutes: $overtimeAfter,
        );
    }

    public function test_on_time_within_grace_is_present_with_no_late_minutes(): void
    {
        $day = $this->workingDay('2026-03-01 08:00:00', '2026-03-01 16:00:00', grace: 15);
        $calc = new AttendanceCalculator;

        $result = $calc->compute(
            $day,
            CarbonImmutable::parse('2026-03-01 08:10:00'), // within 15m grace
            CarbonImmutable::parse('2026-03-01 16:00:00'),
        );

        $this->assertSame(0, $result->lateMinutes);
        $this->assertSame(AttendanceStatus::Present, $result->status);
        $this->assertSame(470, $result->workedMinutes); // 08:10 -> 16:00 actual presence
    }

    public function test_late_beyond_grace_counts_from_end_of_grace(): void
    {
        $day = $this->workingDay('2026-03-01 08:00:00', '2026-03-01 16:00:00', grace: 15);
        $calc = new AttendanceCalculator;

        $result = $calc->compute(
            $day,
            CarbonImmutable::parse('2026-03-01 08:45:00'), // 45m late, grace 15 => 30 late
            CarbonImmutable::parse('2026-03-01 16:00:00'),
        );

        $this->assertSame(30, $result->lateMinutes);
        $this->assertSame(AttendanceStatus::Late, $result->status);
    }

    public function test_break_is_deducted_from_worked_minutes(): void
    {
        $day = $this->workingDay('2026-03-01 08:00:00', '2026-03-01 16:00:00', break: 60);
        $calc = new AttendanceCalculator;

        $result = $calc->compute(
            $day,
            CarbonImmutable::parse('2026-03-01 08:00:00'),
            CarbonImmutable::parse('2026-03-01 16:00:00'),
        );

        $this->assertSame(420, $result->workedMinutes); // 480 - 60 break
    }

    public function test_early_leave_is_measured_against_scheduled_end(): void
    {
        $day = $this->workingDay('2026-03-01 08:00:00', '2026-03-01 16:00:00');
        $calc = new AttendanceCalculator;

        $result = $calc->compute(
            $day,
            CarbonImmutable::parse('2026-03-01 08:00:00'),
            CarbonImmutable::parse('2026-03-01 15:30:00'), // left 30m early
        );

        $this->assertSame(30, $result->earlyLeaveMinutes);
    }

    public function test_overtime_only_counts_beyond_the_threshold(): void
    {
        $day = $this->workingDay('2026-03-01 08:00:00', '2026-03-01 16:00:00', overtimeAfter: 30);
        $calc = new AttendanceCalculator;

        $result = $calc->compute(
            $day,
            CarbonImmutable::parse('2026-03-01 08:00:00'),
            CarbonImmutable::parse('2026-03-01 17:00:00'), // 60m past end, threshold 30 => 30 OT
        );

        $this->assertSame(30, $result->overtimeMinutes);
        $this->assertSame(0, $result->earlyLeaveMinutes);
    }

    public function test_overnight_window_worked_minutes_cross_midnight(): void
    {
        // 22:00 -> 06:00 next day (schedule end already resolved to next day UTC).
        $day = $this->workingDay('2026-03-01 22:00:00', '2026-03-02 06:00:00');
        $calc = new AttendanceCalculator;

        $result = $calc->compute(
            $day,
            CarbonImmutable::parse('2026-03-01 22:00:00'),
            CarbonImmutable::parse('2026-03-02 06:00:00'),
        );

        $this->assertSame(480, $result->workedMinutes);
        $this->assertSame(0, $result->lateMinutes);
    }

    public function test_open_record_reports_lateness_but_zero_duration(): void
    {
        $day = $this->workingDay('2026-03-01 08:00:00', '2026-03-01 16:00:00', grace: 15);
        $calc = new AttendanceCalculator;

        $result = $calc->compute(
            $day,
            CarbonImmutable::parse('2026-03-01 09:00:00'),
            null,
        );

        $this->assertSame(45, $result->lateMinutes);
        $this->assertSame(0, $result->workedMinutes);
        $this->assertSame(AttendanceStatus::Late, $result->status);
    }

    public function test_no_check_in_on_scheduled_day_is_absent(): void
    {
        $day = $this->workingDay('2026-03-01 08:00:00', '2026-03-01 16:00:00');
        $calc = new AttendanceCalculator;

        $result = $calc->compute($day, null, null);

        $this->assertSame(AttendanceStatus::Absent, $result->status);
    }
}
