<?php

namespace App\Modules\Leave\Enums;

/**
 * Accrual cadence for accrual-method policies. No pay-period accrual in Sprint 5
 * (that needs Payroll — Sprint 7).
 */
enum AccrualFrequency: string
{
    case None = 'none';
    case Monthly = 'monthly';
    case Annual = 'annual';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
