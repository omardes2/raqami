<?php

namespace App\Modules\Leave\Support;

/**
 * The full computed effect of a leave request across its dates. totals drive the
 * balance (consumption) and attendance (coverage) respectively.
 */
final class LeaveComputation
{
    /**
     * @param  array<int, LeaveDayComputation>  $days
     */
    public function __construct(
        public readonly array $days,
        public readonly int $totalConsumptionMinutes,
        public readonly int $totalCoverageMinutes,
    ) {}

    public function hasEffect(): bool
    {
        return $this->totalConsumptionMinutes > 0 || $this->totalCoverageMinutes > 0;
    }
}
