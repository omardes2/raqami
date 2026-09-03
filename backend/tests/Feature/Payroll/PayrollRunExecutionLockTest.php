<?php

namespace Tests\Feature\Payroll;

use App\Modules\Payroll\Support\PayrollRunExecutionLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use Tests\TestCase;

/**
 * The run-execution advisory lock (B-3) guarantees at most one active calculation
 * worker per run. Uses two independent PostgreSQL connections and pg_try_advisory_lock
 * for deterministic, sleep-free assertions.
 */
class PayrollRunExecutionLockTest extends TestCase
{
    /** @var list<PDO> */
    private array $conns = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Advisory-lock test requires PostgreSQL.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->conns as $c) {
            try {
                $c->exec('SELECT pg_advisory_unlock_all()');
            } catch (\Throwable) {
            }
        }
        $this->conns = [];
        parent::tearDown();
    }

    private function pdo(): PDO
    {
        $c = config('database.connections.pgsql');
        $pdo = new PDO("pgsql:host={$c['host']};port={$c['port']};dbname={$c['database']}", $c['username'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->conns[] = $pdo;

        return $pdo;
    }

    private function tryLock(PDO $pdo, string $ns): bool
    {
        $stmt = $pdo->prepare('SELECT pg_try_advisory_lock(hashtextextended(?, 0)) AS locked');
        $stmt->execute([$ns]);

        return (bool) $stmt->fetch(PDO::FETCH_ASSOC)['locked'];
    }

    private function unlock(PDO $pdo, string $ns): void
    {
        $pdo->prepare('SELECT pg_advisory_unlock(hashtextextended(?, 0))')->execute([$ns]);
    }

    public function test_only_one_worker_can_own_a_run(): void
    {
        $tenant = (string) Str::ulid();
        $run = (string) Str::ulid();
        $ns = PayrollRunExecutionLock::namespace($tenant, $run);

        $a = $this->pdo();
        $b = $this->pdo();

        $this->assertTrue($this->tryLock($a, $ns), 'first worker acquires');
        $this->assertFalse($this->tryLock($b, $ns), 'second worker is denied while A holds it');

        $this->unlock($a, $ns);
        $this->assertTrue($this->tryLock($b, $ns), 'after release, another worker can acquire');
        $this->unlock($b, $ns);
    }

    public function test_different_runs_do_not_block_each_other(): void
    {
        $tenant = (string) Str::ulid();
        $nsX = PayrollRunExecutionLock::namespace($tenant, (string) Str::ulid());
        $nsY = PayrollRunExecutionLock::namespace($tenant, (string) Str::ulid());

        $a = $this->pdo();
        $b = $this->pdo();

        $this->assertTrue($this->tryLock($a, $nsX));
        $this->assertTrue($this->tryLock($b, $nsY), 'a different run must not be blocked');
        $this->unlock($a, $nsX);
        $this->unlock($b, $nsY);
    }

    public function test_namespace_is_deterministic(): void
    {
        $this->assertSame('payroll:run_execution:T:R', PayrollRunExecutionLock::namespace('T', 'R'));
    }
}
