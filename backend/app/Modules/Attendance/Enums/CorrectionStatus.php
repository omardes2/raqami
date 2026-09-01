<?php

namespace App\Modules\Attendance\Enums;

/**
 * Lifecycle of an attendance correction request. A request is created Pending
 * and moves to a terminal state exactly once (no re-review).
 */
enum CorrectionStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function isTerminal(): bool
    {
        return $this !== self::Pending;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
