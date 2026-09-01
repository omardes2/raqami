<?php

namespace App\Modules\Attendance\Enums;

/**
 * Whether a work schedule (and its assignments) participate in resolution.
 * Archived schedules are retained for history but never newly resolved.
 */
enum WorkScheduleStatus: string
{
    case Active = 'active';
    case Archived = 'archived';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
