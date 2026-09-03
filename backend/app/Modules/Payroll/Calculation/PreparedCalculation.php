<?php

namespace App\Modules\Payroll\Calculation;

use App\Modules\Payroll\Calculation\Input\CalculationInput;

/** The builder's output: engine input + the canonical snapshot and employee snapshot. */
final class PreparedCalculation
{
    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $employeeSnapshot
     */
    public function __construct(
        public readonly CalculationInput $input,
        public readonly array $snapshot,
        public readonly array $employeeSnapshot,
    ) {}
}
