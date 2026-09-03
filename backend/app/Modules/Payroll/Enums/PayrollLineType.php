<?php

namespace App\Modules\Payroll\Enums;

/**
 * The bounded catalogue of payroll line types produced by calculation core-v1.
 * Deliberately small: V1 has no ABSENCE / LATE / EARLY / TAX / SOCIAL_SECURITY /
 * PAYMENT / COMMISSION lines. `direction()` gives each type's fixed money sign
 * except components, whose direction follows the catalog component's type.
 */
enum PayrollLineType: string
{
    case BaseSalary = 'BASE_SALARY';
    case ComponentEarning = 'COMPONENT_EARNING';
    case ComponentDeduction = 'COMPONENT_DEDUCTION';
    case Overtime = 'OVERTIME';
    case UnpaidLeave = 'UNPAID_LEAVE';

    public function direction(): PayrollLineDirection
    {
        return match ($this) {
            self::BaseSalary, self::ComponentEarning, self::Overtime => PayrollLineDirection::Earning,
            self::ComponentDeduction, self::UnpaidLeave => PayrollLineDirection::Deduction,
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
