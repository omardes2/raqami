<?php

namespace App\Modules\Leave\Enums;

/**
 * The approval workflow a policy applies. Snapshotted into concrete steps at
 * submission. `manager` uses the fallback chain direct_manager → department_
 * manager → HR pool; Team Lead is never automatic (a custom policy can add it).
 */
enum ApprovalFlow: string
{
    case None = 'none';
    case Manager = 'manager';
    case Hr = 'hr';
    case ManagerThenHr = 'manager_then_hr';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
