<?php

namespace App\Modules\Leave\Support;

/**
 * Authoritative approved-leave coverage for one employee on one logical
 * work_date, aggregated across possibly multiple overlapping requests. Coverage
 * intervals are merged UTC half-open [start, end). Attendance decides full vs
 * partial by subtracting these from the CURRENT expected work. Immutable.
 */
final class ResolvedLeaveDay
{
    /**
     * @param  array<int, array{start_at:string,end_at:string}>  $coverageIntervals
     * @param  array<int, array{leave_request_id:string,leave_type_id:?string,minutes:int,intervals:array}>  $contributions
     */
    public function __construct(
        public readonly array $coverageIntervals,
        public readonly int $coveredMinutes,
        public readonly array $contributions,
    ) {}

    public function hasCoverage(): bool
    {
        return $this->coveredMinutes > 0 && $this->coverageIntervals !== [];
    }

    /** @return array<int, string> */
    public function requestIds(): array
    {
        return array_values(array_unique(array_map(
            fn (array $c) => $c['leave_request_id'],
            $this->contributions,
        )));
    }
}
