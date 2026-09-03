<?php

namespace App\Modules\Payroll\Calculation\Input;

/** A compensation segment's payable scheduled minutes at a fixed monthly base. */
final class BaseSegment
{
    public function __construct(
        public readonly string $compensationId,
        public readonly int $monthlyBaseMinor,
        public readonly int $payableMinutes,
        public readonly string $effectiveFrom,
        public readonly ?string $effectiveTo,
    ) {}
}
