<?php

namespace App\Modules\Payroll\Enums;

/** A monthly payroll period is open (mutable runs) or closed (finalized). */
enum PayrollPeriodStatus: string
{
    case Open = 'open';
    case Closed = 'closed';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
