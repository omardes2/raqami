<?php

namespace App\Modules\Payroll\Calculation\Input;

use App\Modules\Payroll\Enums\PayrollComponentMode;
use App\Modules\Payroll\Enums\PayrollComponentType;

/**
 * A recurring component's payable segment (already intersected with the covering
 * compensation segment). For a percent component `monthlyBaseMinor` is the base of
 * the covering compensation segment; for a fixed component `fixedAmountMinor` is the
 * monthly amount and `rateBps` is null.
 */
final class ComponentSegment
{
    public function __construct(
        public readonly string $assignmentId,
        public readonly string $componentId,
        public readonly string $code,
        public readonly string $label,
        public readonly PayrollComponentType $componentType,
        public readonly PayrollComponentMode $mode,
        public readonly int $payableMinutes,
        public readonly int $monthlyBaseMinor,
        public readonly ?int $fixedAmountMinor,
        public readonly ?int $rateBps,
        public readonly string $effectiveFrom,
        public readonly ?string $effectiveTo,
    ) {}
}
