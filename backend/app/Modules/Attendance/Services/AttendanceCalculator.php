<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Attendance\Support\AttendanceComputation;
use App\Modules\Attendance\Support\ResolvedWorkDay;
use Carbon\CarbonImmutable;

/**
 * The single place attendance minutes and status are computed. Pure functions of
 * (resolved schedule snapshot, check-in, check-out) — no DB, no clock, no client
 * input. Centralizing this guarantees payroll-relevant numbers are consistent
 * and testable everywhere.
 *
 * Definitions (all in minutes, never negative):
 *   late        = (check_in - scheduled_start) - grace
 *   worked      = (check_out - check_in) - break
 *   early_leave = scheduled_end - check_out
 *   overtime    = (check_out - scheduled_end) - overtime_after
 */
class AttendanceCalculator
{
    /**
     * Compute the full result for a day. $checkOut null means the record is still
     * open — only check-in-derived fields (lateness, status) are meaningful.
     */
    public function compute(ResolvedWorkDay $day, ?CarbonImmutable $checkIn, ?CarbonImmutable $checkOut): AttendanceComputation
    {
        if ($checkIn === null) {
            // No punch: absent on a scheduled working day, otherwise a non-working day.
            $status = $day->isScheduledWorkingDay() ? AttendanceStatus::Absent : AttendanceStatus::Holiday;

            return new AttendanceComputation(0, 0, 0, 0, 0, $status);
        }

        $break = $day->breakMinutes;
        $late = $this->lateMinutes($day, $checkIn);

        if ($checkOut === null) {
            // Open record — punctuality is known, duration is not yet.
            return new AttendanceComputation(
                workedMinutes: 0,
                breakMinutes: $break,
                lateMinutes: $late,
                earlyLeaveMinutes: 0,
                overtimeMinutes: 0,
                status: $this->openStatus($day, $late),
            );
        }

        $worked = max(0, $this->diffMinutes($checkIn, $checkOut) - $break);
        $earlyLeave = $this->earlyLeaveMinutes($day, $checkOut);
        $overtime = $this->overtimeMinutes($day, $checkOut);

        return new AttendanceComputation(
            workedMinutes: $worked,
            breakMinutes: $break,
            lateMinutes: $late,
            earlyLeaveMinutes: $earlyLeave,
            overtimeMinutes: $overtime,
            status: $this->closedStatus($day, $late),
        );
    }

    private function lateMinutes(ResolvedWorkDay $day, CarbonImmutable $checkIn): int
    {
        if (! $day->isScheduledWorkingDay()) {
            return 0;
        }

        $allowedStart = $day->scheduledStartAt->addMinutes($day->graceMinutes);

        return $checkIn->greaterThan($allowedStart)
            ? $this->diffMinutes($allowedStart, $checkIn)
            : 0;
    }

    private function earlyLeaveMinutes(ResolvedWorkDay $day, CarbonImmutable $checkOut): int
    {
        if (! $day->isScheduledWorkingDay()) {
            return 0;
        }

        return $checkOut->lessThan($day->scheduledEndAt)
            ? $this->diffMinutes($checkOut, $day->scheduledEndAt)
            : 0;
    }

    private function overtimeMinutes(ResolvedWorkDay $day, CarbonImmutable $checkOut): int
    {
        if (! $day->isScheduledWorkingDay()) {
            return 0;
        }

        $overtimeStart = $day->scheduledEndAt->addMinutes($day->overtimeAfterMinutes);

        return $checkOut->greaterThan($overtimeStart)
            ? $this->diffMinutes($overtimeStart, $checkOut)
            : 0;
    }

    private function openStatus(ResolvedWorkDay $day, int $late): AttendanceStatus
    {
        if (! $day->isScheduledWorkingDay()) {
            return AttendanceStatus::Present;
        }

        return $late > 0 ? AttendanceStatus::Late : AttendanceStatus::Present;
    }

    private function closedStatus(ResolvedWorkDay $day, int $late): AttendanceStatus
    {
        if (! $day->isScheduledWorkingDay()) {
            return AttendanceStatus::Present;
        }

        return $late > 0 ? AttendanceStatus::Late : AttendanceStatus::Present;
    }

    /** Whole minutes between two instants (a <= b), truncated. */
    private function diffMinutes(CarbonImmutable $a, CarbonImmutable $b): int
    {
        return (int) floor($a->diffInSeconds($b, true) / 60);
    }
}
