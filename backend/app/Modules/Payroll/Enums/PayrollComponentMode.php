<?php

namespace App\Modules\Payroll\Enums;

/**
 * How a component's amount is derived. `fixed` = an explicit minor-unit amount in
 * a stated currency. `percent_of_base` = a share of monthly base, expressed as
 * integer basis points (no decimal percentage; V1 has no formula language).
 */
enum PayrollComponentMode: string
{
    case Fixed = 'fixed';
    case PercentOfBase = 'percent_of_base';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
