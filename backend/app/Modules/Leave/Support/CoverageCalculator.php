<?php

namespace App\Modules\Leave\Support;

use App\Modules\Leave\Enums\LeaveRequestKind;
use Carbon\CarbonImmutable;

/**
 * Pure geometry for leave coverage over a day's ordered expected-work intervals.
 * Halves are measured in WORK MINUTES (not wall-clock): the split boundary is
 * ceil(T/2) so first_half + second_half = T exactly for odd totals, and the
 * boundary may fall between segments (clean split) or inside one (interval cut).
 * All instants are UTC; intervals are half-open [start, end). Deterministic and
 * unit-testable — no DB, no clock, no client input.
 */
final class CoverageCalculator
{
    /**
     * @param  array<int, array{0:CarbonImmutable,1:CarbonImmutable}>  $segments  ordered [start,end] UTC
     * @return array{intervals: array<int, array{start_at:string,end_at:string}>, minutes:int}
     */
    public function coverage(array $segments, LeaveRequestKind $kind): array
    {
        $total = 0;
        foreach ($segments as [$start, $end]) {
            $total += $this->minutes($start, $end);
        }

        if ($total <= 0) {
            return ['intervals' => [], 'minutes' => 0];
        }

        if ($kind === LeaveRequestKind::FullDay) {
            return $this->take($segments, 0, $total);
        }

        $firstHalf = intdiv($total, 2) + ($total % 2); // ceil(T/2)

        return $kind === LeaveRequestKind::FirstHalf
            ? $this->take($segments, 0, $firstHalf)
            : $this->take($segments, $firstHalf, $total);
    }

    /**
     * Clip the concatenated work time to the work-minute window [$fromMin,$toMin)
     * and return the real UTC intervals plus their total minutes.
     *
     * @param  array<int, array{0:CarbonImmutable,1:CarbonImmutable}>  $segments
     * @return array{intervals: array<int, array{start_at:string,end_at:string}>, minutes:int}
     */
    private function take(array $segments, int $fromMin, int $toMin): array
    {
        $intervals = [];
        $minutes = 0;
        $acc = 0; // accumulated work-minutes at each segment's start

        foreach ($segments as [$start, $end]) {
            $dur = $this->minutes($start, $end);
            $segFrom = $acc;
            $segTo = $acc + $dur;

            $a = max($fromMin, $segFrom);
            $b = min($toMin, $segTo);

            if ($b > $a) {
                $clipStart = $start->addMinutes($a - $segFrom);
                $clipEnd = $start->addMinutes($b - $segFrom);
                $intervals[] = [
                    'start_at' => $clipStart->utc()->toIso8601String(),
                    'end_at' => $clipEnd->utc()->toIso8601String(),
                ];
                $minutes += ($b - $a);
            }

            $acc = $segTo;
        }

        return ['intervals' => $intervals, 'minutes' => $minutes];
    }

    private function minutes(CarbonImmutable $start, CarbonImmutable $end): int
    {
        return (int) round($start->diffInMinutes($end, false));
    }
}
