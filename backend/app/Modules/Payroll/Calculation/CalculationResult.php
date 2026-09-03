<?php

namespace App\Modules\Payroll\Calculation;

/** The pure engine's output: currency, lines, derived totals, and warnings. */
final class CalculationResult
{
    /**
     * @param  array<int, CalculatedLine>  $lines
     * @param  array<int, string>  $warnings
     */
    public function __construct(
        public readonly string $currency,
        public readonly array $lines,
        public readonly int $grossMinor,
        public readonly int $deductionMinor,
        public readonly int $netMinor,
        public readonly array $warnings = [],
    ) {}
}
