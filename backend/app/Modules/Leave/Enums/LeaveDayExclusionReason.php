<?php

namespace App\Modules\Leave\Enums;

/** Why a request day consumed/covered zero (recorded on the snapshot). */
enum LeaveDayExclusionReason: string
{
    case Holiday = 'holiday';
    case NonWorkingDay = 'non_working_day';
    case OutsidePeriod = 'outside_period';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
