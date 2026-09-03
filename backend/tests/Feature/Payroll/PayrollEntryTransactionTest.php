<?php

namespace Tests\Feature\Payroll;

use App\Modules\Payroll\Support\PayrollEntryTransaction;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Proves the per-entry executor runs under a coherent REPEATABLE READ snapshot at
 * the top transaction level (a real queue worker), and degrades safely to a nested
 * savepoint when already inside a transaction. Deliberately does NOT use
 * RefreshDatabase, so it begins at transaction level 0.
 */
class PayrollEntryTransactionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('REPEATABLE READ test requires PostgreSQL.');
        }
    }

    public function test_top_level_uses_repeatable_read(): void
    {
        $isolation = PayrollEntryTransaction::run(
            fn () => DB::selectOne('SHOW transaction_isolation')->transaction_isolation,
        );

        $this->assertSame('repeatable read', $isolation);
    }

    public function test_nested_degrades_to_savepoint_without_error(): void
    {
        DB::beginTransaction();
        try {
            $isolation = PayrollEntryTransaction::run(
                fn () => DB::selectOne('SHOW transaction_isolation')->transaction_isolation,
            );
            // Runs under the enclosing transaction (no SET ISOLATION on a subtransaction).
            $this->assertSame('read committed', $isolation);
        } finally {
            DB::rollBack();
        }
    }
}
