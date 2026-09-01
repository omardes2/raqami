<?php

namespace App\Modules\Attendance\Enums;

/**
 * How an attendance event entered the system. The client NEVER decides the
 * computed result — source only records the channel the raw punch came through.
 */
enum AttendanceSource: string
{
    case Web = 'web';
    case Mobile = 'mobile';
    case Manual = 'manual';       // entered by an authorized manager/HR on behalf of an employee
    case Correction = 'correction'; // applied via an approved correction request
    case System = 'system';        // auto-generated (e.g. auto-close), reserved

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
