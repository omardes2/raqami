<?php

namespace App\Modules\Attendance\Enums;

enum AnomalyStatus: string
{
    case Open = 'open';
    case Acknowledged = 'acknowledged';
    case Resolved = 'resolved';
    case Dismissed = 'dismissed';

    public function isClosed(): bool
    {
        return in_array($this, [self::Resolved, self::Dismissed], true);
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
