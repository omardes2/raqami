<?php

namespace App\Modules\Leave\Enums;

/** Lifecycle of a leave type (archive over delete). */
enum LeaveTypeStatus: string
{
    case Active = 'active';
    case Archived = 'archived';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
