<?php

namespace App\Modules\Attendance\Enums;

/**
 * Server-computed daily state of an attendance_record. The SERVER decides this
 * from schedule snapshots + punch times — the client cannot claim a status.
 */
enum AttendanceStatus: string
{
    case Present = 'present';           // checked in on time (or within grace)
    case Late = 'late';                 // checked in after grace window
    case Absent = 'absent';             // scheduled but no check-in
    case Incomplete = 'incomplete';     // checked in but never checked out
    case OnLeave = 'on_leave';          // reserved hook for future Leave module
    case Holiday = 'holiday';           // resolved holiday (calendar)
    case Weekend = 'weekend';           // non-working schedule day (off day)
    case PendingReview = 'pending_review'; // flagged (e.g. outside geofence in warn mode)

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
