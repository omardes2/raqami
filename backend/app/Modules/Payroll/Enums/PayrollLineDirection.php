<?php

namespace App\Modules\Payroll\Enums;

/**
 * The single money-sign truth for a payroll line: an earning adds to gross, a
 * deduction subtracts from it. Line amounts are always non-negative magnitudes;
 * the direction carries the sign.
 */
enum PayrollLineDirection: string
{
    case Earning = 'earning';
    case Deduction = 'deduction';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
