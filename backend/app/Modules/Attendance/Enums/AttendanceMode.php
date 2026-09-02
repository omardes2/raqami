<?php

namespace App\Modules\Attendance\Enums;

/** Where attendance is expected. Drives whether office geofence is enforced. */
enum AttendanceMode: string
{
    case Onsite = 'onsite';
    case Remote = 'remote';
    case Field = 'field';

    /** True when the office geofence must be enforced for this mode. */
    public function requiresGeofence(): bool
    {
        return $this === self::Onsite;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
