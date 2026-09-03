<?php

namespace App\Modules\Payroll\Calculation\Input;

/**
 * Unpaid-leave coverage minutes (already intersected with expected work) falling
 * inside one compensation segment, attributed to one leave request. Deducted at the
 * segment's monthly base — never from balance consumption.
 */
final class UnpaidLeaveSegment
{
    public function __construct(
        public readonly string $leaveRequestId,
        public readonly int $monthlyBaseMinor,
        public readonly int $unpaidMinutes,
        public readonly string $dateFrom,
        public readonly string $dateTo,
    ) {}
}
