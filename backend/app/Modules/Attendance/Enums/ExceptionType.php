<?php

namespace App\Modules\Attendance\Enums;

enum ExceptionType: string
{
    case Remote = 'remote';
    case Field = 'field';
    case OffDayWork = 'off_day_work';
    case AlternateLocation = 'alternate_location';
    case ScheduleOverride = 'schedule_override';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
