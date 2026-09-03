<?php

namespace App\Modules\Payroll\Calculation\Input;

/** One approved-overtime fact valued at the full explicit hourly rate (no multiplier). */
final class OvertimeItem
{
    public function __construct(
        public readonly string $approvalId,
        public readonly string $workDate,
        public readonly int $approvedMinutes,
        public readonly int $rateMinorPerHour,
    ) {}
}
