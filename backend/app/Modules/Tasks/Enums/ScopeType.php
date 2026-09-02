<?php

namespace App\Modules\Tasks\Enums;

/** Organizational scope anchor (mirrors existing ADR-015 scope semantics). */
enum ScopeType: string
{
    case Company = 'company';
    case Branch = 'branch';
    case Department = 'department';
    case Team = 'team';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
