<?php

namespace App\Modules\Payroll\Calculation;

use App\Modules\Payroll\Enums\PayrollErrorCode;
use RuntimeException;

/**
 * A controlled, per-employee calculation failure. Carries a bounded error code and
 * a safe context (ids/dates/codes only — never salary amounts) so the orchestrator
 * can mark the entry failed and keep it visible in Run Review.
 */
class PayrollCalculationException extends RuntimeException
{
    /** @param array<string, scalar|null> $context */
    public function __construct(
        public readonly PayrollErrorCode $errorCode,
        public readonly array $context = [],
    ) {
        parent::__construct($errorCode->value);
    }
}
