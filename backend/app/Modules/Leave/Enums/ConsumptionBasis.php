<?php

namespace App\Modules\Leave\Enums;

/**
 * How a policy converts a leave day into consumed balance MINUTES (D7).
 *
 * - ScheduledMinutes (default): consumption follows the employee's resolved
 *   expected work minutes for the date; a non-working day consumes zero.
 * - NominalCalendarDay: consumption uses the policy's nominal_day_minutes for
 *   every counted date, even where no work was scheduled. Requires
 *   nominal_day_minutes > 0. Never a global 8h assumption.
 */
enum ConsumptionBasis: string
{
    case ScheduledMinutes = 'scheduled_minutes';
    case NominalCalendarDay = 'nominal_calendar_day';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
