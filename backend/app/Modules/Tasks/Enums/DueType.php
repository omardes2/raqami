<?php

namespace App\Modules\Tasks\Enums;

/** Deadline shape (§22): none | date-only | exact datetime. */
enum DueType: string
{
    case None = 'none';
    case Date = 'date';
    case Datetime = 'datetime';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
