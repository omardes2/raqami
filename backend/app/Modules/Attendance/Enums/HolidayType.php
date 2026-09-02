<?php

namespace App\Modules\Attendance\Enums;

enum HolidayType: string
{
    case Public = 'public';
    case Company = 'company';
    case BranchSpecific = 'branch_specific';
    case Custom = 'custom';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
