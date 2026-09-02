<?php

namespace App\Modules\Tasks\Enums;

/** Bounded project-local ACL role (D1). Owner is projects.owner_employee_id, never a row. */
enum ProjectMembershipRole: string
{
    case Manager = 'manager';
    case Member = 'member';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
