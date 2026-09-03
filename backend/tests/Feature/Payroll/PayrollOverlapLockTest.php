<?php

namespace Tests\Feature\Payroll;

use App\Modules\Payroll\Support\PayrollLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use Tests\TestCase;

/**
 * Proves the effective-range overlap DB triggers acquire the SAME transaction-
 * scoped advisory lock the application services take — so two DIRECT-SQL writers
 * (bypassing the service) cannot both pass the overlap check and both insert an
 * overlapping range. Uses two independent PostgreSQL connections coordinated by
 * lock_timeout (no sleeps): while connection A holds the lock, connection B's
 * conflicting write is aborted with SQLSTATE 55P03 (lock_not_available). After A
 * commits, the retry sees the committed row and is rejected by the overlap check
 * (SQLSTATE 23P01, exclusion_violation).
 *
 * Fixtures are committed on raw connections (so both connections see them) and
 * removed in tearDown; RefreshDatabase guarantees the schema exists.
 */
class PayrollOverlapLockTest extends TestCase
{
    use RefreshDatabase;

    private const LOCK_TIMEOUT = "SET LOCAL lock_timeout = '250ms'";

    private const SQLSTATE_LOCK_NOT_AVAILABLE = '55P03';

    private const SQLSTATE_EXCLUSION = '23P01';

    /** @var list<PDO> */
    private array $conns = [];

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        if (\DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Advisory-lock concurrency test requires PostgreSQL.');
        }
        $this->tenantId = (string) Str::ulid();
    }

    protected function tearDown(): void
    {
        // Roll back and close every raw connection, then delete the committed fixtures.
        foreach ($this->conns as $c) {
            try {
                if ($c->inTransaction()) {
                    $c->rollBack();
                }
            } catch (\Throwable) {
            }
        }
        $this->conns = [];

        try {
            $cleanup = $this->newConnection();
            $this->useTenant($cleanup, $this->tenantId);
            $cleanup->exec('DELETE FROM employee_compensation_components');
            $cleanup->exec('DELETE FROM employee_compensations');
            $cleanup->exec('DELETE FROM employees');
            $del = $cleanup->prepare('DELETE FROM tenants WHERE id = ?');
            $del->execute([$this->tenantId]);
        } catch (\Throwable) {
            // Best-effort cleanup; the DB is a disposable test database.
        }

        parent::tearDown();
    }

    private function newConnection(): PDO
    {
        $c = config('database.connections.pgsql');
        $pdo = new PDO(
            "pgsql:host={$c['host']};port={$c['port']};dbname={$c['database']}",
            $c['username'],
            $c['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $this->conns[] = $pdo;

        return $pdo;
    }

    /** Set the RLS tenant GUC on a raw connection (session scope, like TenantContext). */
    private function useTenant(PDO $pdo, string $tenantId): void
    {
        $s = $pdo->prepare("SELECT set_config('app.tenant_id', ?, false)");
        $s->execute([$tenantId]);
    }

    private function seedTenantAndEmployee(string $employeeNumber): string
    {
        $employeeId = (string) Str::ulid();
        $c = $this->newConnection();
        $this->useTenant($c, $this->tenantId);

        // tenants has no RLS; insert once (idempotent-guard on duplicate).
        $exists = $c->prepare('SELECT 1 FROM tenants WHERE id = ?');
        $exists->execute([$this->tenantId]);
        if ($exists->fetchColumn() === false) {
            $c->prepare('INSERT INTO tenants (id, name, slug, created_at, updated_at) VALUES (?, ?, ?, now(), now())')
                ->execute([$this->tenantId, 'LockTest', 'lock-'.strtolower($this->tenantId)]);
        }

        $c->prepare('INSERT INTO employees (id, tenant_id, employee_number, first_name, last_name, employment_status, employment_type, status, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?, now(), now())')
            ->execute([$employeeId, $this->tenantId, $employeeNumber, 'Lock', 'Test', 'active', 'full_time', 'active']);

        return $employeeId;
    }

    private function seedComponent(): string
    {
        $componentId = (string) Str::ulid();
        $c = $this->newConnection();
        $this->useTenant($c, $this->tenantId);
        $c->prepare('INSERT INTO payroll_components (id, tenant_id, code, name, type, calculation_mode, active, sort_order, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?, now(), now())')
            ->execute([$componentId, $this->tenantId, 'C'.substr($componentId, -8), 'Component', 'earning', 'fixed', 'true', 0]);

        return $componentId;
    }

    private function insertCompensation(PDO $pdo, string $employeeId, string $from, ?string $to): void
    {
        $pdo->prepare('INSERT INTO employee_compensations (id, tenant_id, employee_id, currency, base_amount_minor, effective_from, effective_to, version, created_at, updated_at) VALUES (?,?,?,?,?,?,?,1, now(), now())')
            ->execute([(string) Str::ulid(), $this->tenantId, $employeeId, 'USD', 100000, $from, $to]);
    }

    private function insertComponentAssignment(PDO $pdo, string $employeeId, string $componentId, string $from, ?string $to): void
    {
        $pdo->prepare('INSERT INTO employee_compensation_components (id, tenant_id, employee_id, payroll_component_id, fixed_amount_minor, currency, effective_from, effective_to, version, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,1, now(), now())')
            ->execute([(string) Str::ulid(), $this->tenantId, $employeeId, $componentId, 50000, 'USD', $from, $to]);
    }

    /** @return string|null the caught SQLSTATE, or null if the closure did not throw */
    private function sqlstateOf(callable $fn): ?string
    {
        try {
            $fn();

            return null;
        } catch (PDOException $e) {
            return $e->getCode();
        }
    }

    public function test_direct_sql_writers_serialize_on_the_compensation_lock(): void
    {
        $emp = $this->seedTenantAndEmployee('E-COMP-1');

        // A: hold the lock by inserting (uncommitted) — the trigger locks the key.
        $a = $this->newConnection();
        $this->useTenant($a, $this->tenantId);
        $a->beginTransaction();
        $this->insertCompensation($a, $emp, '2026-01-01', '2026-06-30');

        // B: a conflicting direct insert must abort on the advisory lock (55P03).
        $b = $this->newConnection();
        $this->useTenant($b, $this->tenantId);
        $b->beginTransaction();
        $b->exec(self::LOCK_TIMEOUT);
        $state = $this->sqlstateOf(fn () => $this->insertCompensation($b, $emp, '2026-03-01', null));
        $this->assertSame(self::SQLSTATE_LOCK_NOT_AVAILABLE, $state, 'B must block on the trigger advisory lock while A holds it');
        $b->rollBack();

        // A commits its row.
        $a->commit();

        // Retry: now the lock is free; the trigger sees the committed row and the
        // overlap check rejects the overlapping range (23P01).
        $c = $this->newConnection();
        $this->useTenant($c, $this->tenantId);
        $c->beginTransaction();
        $overlap = $this->sqlstateOf(fn () => $this->insertCompensation($c, $emp, '2026-03-01', null));
        $this->assertSame(self::SQLSTATE_EXCLUSION, $overlap, 'overlapping range must be rejected by the trigger');
        $c->rollBack();

        // An adjacent (non-overlapping) range is accepted.
        $d = $this->newConnection();
        $this->useTenant($d, $this->tenantId);
        $d->beginTransaction();
        $adjacent = $this->sqlstateOf(fn () => $this->insertCompensation($d, $emp, '2026-07-01', null));
        $this->assertNull($adjacent, 'an adjacent range must be accepted');
        $d->commit();
    }

    public function test_different_employees_do_not_serialize_on_the_compensation_lock(): void
    {
        $emp1 = $this->seedTenantAndEmployee('E-COMP-A');
        $emp2 = $this->seedTenantAndEmployee('E-COMP-B');

        // A holds the lock for employee 1.
        $a = $this->newConnection();
        $this->useTenant($a, $this->tenantId);
        $a->beginTransaction();
        $this->insertCompensation($a, $emp1, '2026-01-01', null);

        // B writes for a DIFFERENT employee — a different key, so it must NOT block.
        $b = $this->newConnection();
        $this->useTenant($b, $this->tenantId);
        $b->beginTransaction();
        $b->exec(self::LOCK_TIMEOUT);
        $state = $this->sqlstateOf(fn () => $this->insertCompensation($b, $emp2, '2026-01-01', null));
        $this->assertNull($state, 'a different employee must not serialize on the same lock');
        $b->commit();
        $a->rollBack();
    }

    public function test_service_lock_key_matches_the_trigger_lock_key(): void
    {
        $emp = $this->seedTenantAndEmployee('E-COMP-K');

        // A acquires the lock using EXACTLY the application service's namespace
        // (PayrollLock::compensationNamespace) — no insert, just the lock.
        $namespace = PayrollLock::compensationNamespace($this->tenantId, $emp);
        $a = $this->newConnection();
        $this->useTenant($a, $this->tenantId);
        $a->beginTransaction();
        $lock = $a->prepare('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))');
        $lock->execute([$namespace]);

        // B's direct INSERT must block on the SAME key inside the trigger (55P03),
        // proving service writers and direct writers share one lock identity.
        $b = $this->newConnection();
        $this->useTenant($b, $this->tenantId);
        $b->beginTransaction();
        $b->exec(self::LOCK_TIMEOUT);
        $state = $this->sqlstateOf(fn () => $this->insertCompensation($b, $emp, '2026-01-01', null));
        $this->assertSame(self::SQLSTATE_LOCK_NOT_AVAILABLE, $state, 'the trigger must contend on the service lock key');
        $b->rollBack();
        $a->rollBack();
    }

    public function test_direct_sql_writers_serialize_on_the_component_lock(): void
    {
        $emp = $this->seedTenantAndEmployee('E-CC-1');
        $component = $this->seedComponent();

        // A holds the component lock for (employee, component).
        $a = $this->newConnection();
        $this->useTenant($a, $this->tenantId);
        $a->beginTransaction();
        $this->insertComponentAssignment($a, $emp, $component, '2026-01-01', null);

        // B: same (employee, component) conflicting insert must block (55P03).
        $b = $this->newConnection();
        $this->useTenant($b, $this->tenantId);
        $b->beginTransaction();
        $b->exec(self::LOCK_TIMEOUT);
        $state = $this->sqlstateOf(fn () => $this->insertComponentAssignment($b, $emp, $component, '2026-03-01', null));
        $this->assertSame(self::SQLSTATE_LOCK_NOT_AVAILABLE, $state, 'B must block on the component advisory lock');
        $b->rollBack();
        $a->rollBack();
    }

    public function test_different_components_for_same_employee_do_not_serialize(): void
    {
        $emp = $this->seedTenantAndEmployee('E-CC-D');
        $componentA = $this->seedComponent();
        $componentB = $this->seedComponent();

        // A holds the lock for (employee, component A).
        $a = $this->newConnection();
        $this->useTenant($a, $this->tenantId);
        $a->beginTransaction();
        $this->insertComponentAssignment($a, $emp, $componentA, '2026-01-01', null);

        // B writes for the SAME employee but component B — a different key, no block.
        $b = $this->newConnection();
        $this->useTenant($b, $this->tenantId);
        $b->beginTransaction();
        $b->exec(self::LOCK_TIMEOUT);
        $state = $this->sqlstateOf(fn () => $this->insertComponentAssignment($b, $emp, $componentB, '2026-01-01', null));
        $this->assertNull($state, 'a different component must not serialize on the same lock');
        $b->commit();
        $a->rollBack();
    }

    public function test_service_and_trigger_component_lock_keys_match(): void
    {
        $emp = $this->seedTenantAndEmployee('E-CC-K');
        $component = $this->seedComponent();

        $namespace = PayrollLock::componentNamespace($this->tenantId, $emp, $component);
        $a = $this->newConnection();
        $this->useTenant($a, $this->tenantId);
        $a->beginTransaction();
        $a->prepare('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))')->execute([$namespace]);

        $b = $this->newConnection();
        $this->useTenant($b, $this->tenantId);
        $b->beginTransaction();
        $b->exec(self::LOCK_TIMEOUT);
        $state = $this->sqlstateOf(fn () => $this->insertComponentAssignment($b, $emp, $component, '2026-01-01', null));
        $this->assertSame(self::SQLSTATE_LOCK_NOT_AVAILABLE, $state, 'component trigger must contend on the service component key');
        $b->rollBack();
        $a->rollBack();
    }
}
