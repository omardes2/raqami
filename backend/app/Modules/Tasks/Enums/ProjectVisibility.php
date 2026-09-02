<?php

namespace App\Modules\Tasks\Enums;

/** Whether ordinary organizational scope may reveal a project (D2). */
enum ProjectVisibility: string
{
    case Scoped = 'scoped';
    case MembersOnly = 'members_only';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
