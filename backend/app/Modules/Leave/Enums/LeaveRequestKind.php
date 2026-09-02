<?php

namespace App\Modules\Leave\Enums;

/**
 * The portion of the scheduled day a request covers. V1 supports full/half only
 * (no arbitrary employee-entered hourly leave). Halves are derived from the
 * ordered expected work minutes, not wall-clock AM/PM.
 */
enum LeaveRequestKind: string
{
    case FullDay = 'full_day';
    case FirstHalf = 'first_half';
    case SecondHalf = 'second_half';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
