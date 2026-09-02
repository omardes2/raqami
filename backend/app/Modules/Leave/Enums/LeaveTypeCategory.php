<?php

namespace App\Modules\Leave\Enums;

/**
 * Generic, jurisdiction-neutral grouping for a leave type. Used only for
 * grouping and reporting — it NEVER encodes an entitlement amount or a legal
 * rule (those live in leave_policies). Tenants may pick any category, including
 * `other`, for custom types.
 */
enum LeaveTypeCategory: string
{
    case Annual = 'annual';
    case Sick = 'sick';
    case Unpaid = 'unpaid';
    case Emergency = 'emergency';
    case Parental = 'parental';
    case Compensatory = 'compensatory';
    case Other = 'other';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
