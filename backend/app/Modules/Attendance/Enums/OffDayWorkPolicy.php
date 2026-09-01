<?php

namespace App\Modules\Attendance\Enums;

/** Tenant policy for attendance on a non-working / unscheduled day. */
enum OffDayWorkPolicy: string
{
    case Reject = 'reject';
    case Allow = 'allow';
    case RequireApproval = 'require_approval';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
