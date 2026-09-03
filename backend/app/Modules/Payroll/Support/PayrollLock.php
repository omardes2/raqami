<?php

namespace App\Modules\Payroll\Support;

use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL transaction-scoped advisory locks (auto-released at commit/rollback)
 * that serialize concurrent effective-dated payroll writes so overlapping ranges
 * cannot be created under a race (Corrections F/H). Distinct key prefixes keep
 * unrelated work from contending. No-op on non-PostgreSQL drivers. Must be called
 * inside an open DB transaction.
 */
final class PayrollLock
{
    /** Serialize compensation writes for (tenant, employee). */
    public static function forCompensation(string $tenantId, string $employeeId): void
    {
        self::acquire("payroll:comp:{$tenantId}:{$employeeId}");
    }

    /** Serialize recurring-component writes for (tenant, employee, component). */
    public static function forComponent(string $tenantId, string $employeeId, string $componentId): void
    {
        self::acquire("payroll:comp-comp:{$tenantId}:{$employeeId}:{$componentId}");
    }

    /** Serialize per-tenant settings create-or-fetch. */
    public static function forSettings(string $tenantId): void
    {
        self::acquire("payroll:settings:{$tenantId}");
    }

    /** Serialize run creation for a tenant period (one active run per period). */
    public static function forPeriodRun(string $tenantId, string $periodId): void
    {
        self::acquire("payroll:run:{$tenantId}:{$periodId}");
    }

    private static function acquire(string $key): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$key]);
    }
}
