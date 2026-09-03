<?php

namespace App\Modules\Payroll\Calculation;

use App\Modules\Payroll\Enums\PayrollLineDirection;
use App\Modules\Payroll\Enums\PayrollLineType;

/** One line produced by the pure calculation engine (no persistence concerns). */
final class CalculatedLine
{
    /** @param array<string, scalar|null> $metadata */
    public function __construct(
        public readonly PayrollLineType $type,
        public readonly PayrollLineDirection $direction,
        public readonly string $sourceType,
        public readonly ?string $sourceId,
        public readonly string $label,
        public readonly int $amountMinor,
        public readonly ?int $quantityMinutes = null,
        public readonly ?int $rateMinorPerHour = null,
        public readonly ?int $rateBps = null,
        public readonly array $metadata = [],
    ) {}
}
