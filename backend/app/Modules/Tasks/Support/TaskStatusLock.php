<?php

namespace App\Modules\Tasks\Support;

use Illuminate\Support\Facades\DB;

/**
 * Serializes concurrent mutations of the tenant's single active default task
 * status via a PostgreSQL transaction-scoped advisory lock (auto-released at
 * commit/rollback). Two admins setting a default at the same instant then run
 * one after the other — the second sees the first's committed state and simply
 * moves the default, so the "one active default" partial-unique index can never
 * fire a raw error under a race. No-op on non-PostgreSQL drivers.
 */
final class TaskStatusLock
{
    /** Acquire a transaction-scoped advisory lock for the tenant. Must run inside an open transaction. */
    public static function forDefault(string $tenantId): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
            ["task_default_status:{$tenantId}"]
        );
    }
}
