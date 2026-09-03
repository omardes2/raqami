<?php

namespace App\Modules\Payroll\Enums;

/** A compensation component is either an earning (adds to gross) or a deduction. */
enum PayrollComponentType: string
{
    case Earning = 'earning';
    case Deduction = 'deduction';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
