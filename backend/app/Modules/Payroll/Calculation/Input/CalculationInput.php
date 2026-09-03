<?php

namespace App\Modules\Payroll\Calculation\Input;

/**
 * The complete normalized, authoritative input for one employee's calculation.
 * Built by the input adapters; consumed by the pure engine. `periodExpectedMinutes`
 * is the FULL-month scheduled denominator (never reduced by hire/termination/leave).
 */
final class CalculationInput
{
    /**
     * @param  array<int, BaseSegment>  $baseSegments
     * @param  array<int, ComponentSegment>  $componentSegments
     * @param  array<int, UnpaidLeaveSegment>  $unpaidLeaveSegments
     * @param  array<int, OvertimeItem>  $overtimeItems
     */
    public function __construct(
        public readonly string $currency,
        public readonly int $periodExpectedMinutes,
        public readonly array $baseSegments,
        public readonly array $componentSegments,
        public readonly array $unpaidLeaveSegments,
        public readonly array $overtimeItems,
        public readonly bool $overtimeEnabled,
    ) {}
}
