<?php

namespace App\Modules\Leave\Enums;

/**
 * Entitlement-period basis. Global SaaS never assumes Jan 1 — the tenant/policy
 * chooses the basis, and the window is computed by exact date arithmetic in the
 * employee/tenant timezone.
 */
enum PeriodType: string
{
    case CalendarYear = 'calendar_year';
    case EmploymentAnniversary = 'employment_anniversary';
    case CustomTenantYear = 'custom_tenant_year';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
