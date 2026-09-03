<?php

namespace App\Modules\Payroll\Support;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Runs one employee's calculation inside a single coherent PostgreSQL snapshot.
 * At the top transaction level (a real queue worker) it opens a transaction and
 * immediately sets REPEATABLE READ — issued before any data query so every
 * authoritative read (employment, compensation, components, schedule, holidays,
 * leave, overtime, settings) observes ONE consistent database moment. The
 * session-level RLS GUC set by TenantContext is established before this
 * transaction, so tenant scoping and FORCE RLS continue to apply.
 *
 * When already inside a transaction (e.g. a RefreshDatabase test wraps everything
 * in one), PostgreSQL forbids SET TRANSACTION ISOLATION LEVEL on a subtransaction,
 * so we degrade to a nested savepoint under the enclosing snapshot rather than
 * error. Production workers begin at level 0 and always get REPEATABLE READ.
 */
final class PayrollEntryTransaction
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

        if ($connection->getDriverName() === 'pgsql' && $connection->transactionLevel() === 0) {
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

        // Nested (tests) or non-PostgreSQL: run under the enclosing transaction.
        return $connection->transaction(fn () => $callback());
    }
}
