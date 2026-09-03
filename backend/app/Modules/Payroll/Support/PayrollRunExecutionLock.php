<?php

namespace App\Modules\Payroll\Support;

use Illuminate\Support\Facades\DB;

/**
 * A SESSION-level PostgreSQL advisory lock that guarantees at most one active
 * calculation worker per payroll run. A calculation job spans many per-entry
 * transactions, so a transaction-scoped lock is insufficient — this lock is held
 * across the whole job and released in a finally block on the SAME connection.
 * If the worker process dies, PostgreSQL frees the session lock automatically, so
 * a later retry can re-acquire it (no stuck execution flag).
 *
 * The namespace + hash mirror PayrollLock's approach (one shared hashing scheme):
 * pg_advisory_lock(hashtextextended(namespace, 0)). No-op off PostgreSQL.
 */
final class PayrollRunExecutionLock
{
    public static function namespace(string $tenantId, string $runId): string
    {
        return "payroll:run_execution:{$tenantId}:{$runId}";
    }

    /** Try to claim exclusive execution of a run. false = another worker owns it. */
    public static function tryAcquire(string $tenantId, string $runId): bool
    {
        if (DB::getDriverName() !== 'pgsql') {
            return true;
        }

        $row = DB::selectOne('SELECT pg_try_advisory_lock(hashtextextended(?, 0)) AS locked', [self::namespace($tenantId, $runId)]);

        return (bool) ($row->locked ?? false);
    }

    /** Release a previously acquired execution lock (same session/connection). */
    public static function release(string $tenantId, string $runId): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::select('SELECT pg_advisory_unlock(hashtextextended(?, 0))', [self::namespace($tenantId, $runId)]);
    }
}
