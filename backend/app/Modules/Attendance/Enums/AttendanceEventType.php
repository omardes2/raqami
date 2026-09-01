<?php

namespace App\Modules\Attendance\Enums;

/**
 * The kind of raw punch recorded in attendance_events (append-only log).
 */
enum AttendanceEventType: string
{
    case CheckIn = 'check_in';
    case CheckOut = 'check_out';
    case ManualCheckIn = 'manual_check_in';
    case ManualCheckOut = 'manual_check_out';
    case CorrectionApplied = 'correction_applied';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
