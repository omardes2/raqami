<?php

namespace App\Modules\Tasks\Enums;

/** Project lifecycle state. Archive is orthogonal (projects.archived_at), never a status. */
enum ProjectStatus: string
{
    case Active = 'active';
    case OnHold = 'on_hold';
    case Completed = 'completed';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
