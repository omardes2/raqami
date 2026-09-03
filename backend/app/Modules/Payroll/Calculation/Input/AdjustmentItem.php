<?php

namespace App\Modules\Payroll\Calculation\Input;

/**
 * A manual payroll adjustment (Phase 2B) as an authoritative calculation input: a
 * fixed, NON-prorated earning or deduction at its full magnitude. `direction` is
 * 'earning' | 'deduction'; `amountMinor` is a non-negative magnitude (the sign is
 * the direction). Feeds exactly one ADJUSTMENT_EARNING / ADJUSTMENT_DEDUCTION line.
 */
final class AdjustmentItem
{
    public function __construct(
        public readonly string $id,
        public readonly string $direction,
        public readonly int $amountMinor,
        public readonly string $label,
    ) {}
}
