<?php

namespace App\Modules\Leave\Enums;

/**
 * How a policy grants entitlement into a period.
 *
 * - None: no automatic entitlement (manual grants/adjustments only).
 * - Fixed: one upfront grant of entitlement_minutes on period open.
 * - Accrual: periodic accrual driven by accrual_frequency/accrual_minutes.
 */
enum EntitlementMethod: string
{
    case None = 'none';
    case Fixed = 'fixed';
    case Accrual = 'accrual';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
