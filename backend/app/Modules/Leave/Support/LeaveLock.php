<?php

namespace App\Modules\Leave\Support;

use Illuminate\Support\Facades\DB;

/**
 * Serializes concurrent leave BALANCE operations for the SAME employee via a
 * PostgreSQL transaction-scoped advisory lock (auto-released at commit/rollback).
 * A distinct key prefix ("leave:") from attendance so pure balance work does not
 * contend with punches — but any operation that writes attendance_records must
 * instead use AttendanceLock (the shared "attendance:" key). No-op off pgsql.
 */
final class LeaveLock
{
    /** Acquire a transaction-scoped advisory lock for (tenant, employee). */
    public static function forEmployee(string $tenantId, string $employeeId): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
            ["leave:{$tenantId}:{$employeeId}"]
        );
    }
}
