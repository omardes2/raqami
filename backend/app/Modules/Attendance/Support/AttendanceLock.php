<?php

namespace App\Modules\Attendance\Support;

use Illuminate\Support\Facades\DB;

/**
 * Serializes concurrent punches for the SAME employee. A PostgreSQL
 * transaction-scoped advisory lock (auto-released at commit/rollback) means two
 * simultaneous check-in requests cannot both create an open record — one waits
 * for the other. Combined with the partial unique index, double punches are
 * impossible under races. No-op on non-PostgreSQL drivers.
 */
final class AttendanceLock
{
    /**
     * Acquire a transaction-scoped advisory lock for (tenant, employee). Must be
     * called inside an open DB transaction.
     */
    public static function forEmployee(string $tenantId, string $employeeId): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
            ["attendance:{$tenantId}:{$employeeId}"]
        );
    }
}
