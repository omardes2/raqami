<?php

namespace App\Modules\Payroll\Support;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Runs payroll finalization inside a single coherent, TOP-LEVEL PostgreSQL
 * REPEATABLE READ transaction and FAILS CLOSED if that is not possible.
 *
 * Unlike the per-entry calculation executor (which safely degrades to a nested
 * savepoint under an enclosing transaction), finalization is the authoritative
 * financial commit: closing a period and freezing money is irreversible, so it must
 * observe ONE consistent snapshot at the strongest practical isolation. If a
 * transaction is already open, this throws NestedFinalizationException rather than
 * degrade to a savepoint that would inherit READ COMMITTED — no authoritative
 * financial read may happen before REPEATABLE READ is established.
 *
 * Order guaranteed to the callback: BEGIN → SET TRANSACTION ISOLATION LEVEL
 * REPEATABLE READ (issued before any data query) → callback → COMMIT. The
 * session-level RLS GUC set by TenantContext is already established, so tenant
 * scoping and FORCE RLS continue to apply.
 */
final class PayrollFinalizationTransaction
{
    /**
     * @template T
     *
     * @param  callable():T  $callback
     * @return T
     */
    public static function run(callable $callback): mixed
    {
        $connection = DB::connection();

        if ($connection->transactionLevel() !== 0) {
            throw new NestedFinalizationException;
        }

        // Non-PostgreSQL cannot set REPEATABLE READ per transaction; still require a
        // top-level transaction (asserted above) and run the commit atomically.
        if ($connection->getDriverName() !== 'pgsql') {
            return $connection->transaction(fn () => $callback());
        }

        $connection->beginTransaction();
        try {
            // Must precede any data query in this transaction.
            $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $result = $callback();
            $connection->commit();

            return $result;
        } catch (Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }
}
