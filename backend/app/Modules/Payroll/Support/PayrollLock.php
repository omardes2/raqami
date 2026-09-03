<?php

namespace App\Modules\Payroll\Support;

use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL transaction-scoped advisory locks (auto-released at commit/rollback)
 * that serialize concurrent effective-dated payroll writes so overlapping ranges
 * cannot be created under a race (Corrections F/H). Distinct key prefixes keep
 * unrelated work from contending. No-op on non-PostgreSQL drivers. Must be called
 * inside an open DB transaction.
 *
 * The effective-range namespaces below are DELIBERATELY identical to the strings
 * the DB overlap triggers rebuild (migration ..._harden_payroll_phase_one_invariants):
 * both the application service and a direct-SQL writer serialize on the SAME
 * pg_advisory_xact_lock(hashtextextended(namespace, 0)) key. Do NOT change these
 * strings without updating the trigger functions in lockstep.
 */
final class PayrollLock
{
    /** Serialize compensation writes for (tenant, employee). Mirrored by the DB trigger. */
    public static function forCompensation(string $tenantId, string $employeeId): void
    {
        self::acquire(self::compensationNamespace($tenantId, $employeeId));
    }

    /** Serialize recurring-component writes for (tenant, employee, component). Mirrored by the DB trigger. */
    public static function forComponent(string $tenantId, string $employeeId, string $componentId): void
    {
        self::acquire(self::componentNamespace($tenantId, $employeeId, $componentId));
    }

    /** The exact advisory-lock namespace the compensation overlap trigger rebuilds. */
    public static function compensationNamespace(string $tenantId, string $employeeId): string
    {
        return "payroll:compensation:{$tenantId}:{$employeeId}";
    }

    /** The exact advisory-lock namespace the component overlap trigger rebuilds. */
    public static function componentNamespace(string $tenantId, string $employeeId, string $componentId): string
    {
        return "payroll:compensation_component:{$tenantId}:{$employeeId}:{$componentId}";
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
