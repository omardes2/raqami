<?php

namespace Tests\Feature\Payroll;

use App\Modules\Attendance\Services\WorkScheduleService;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Payroll\Enums\PayrollRunStatus;
use App\Modules\Payroll\Models\PayrollEntry;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Services\EmployeeCompensationService;
use App\Modules\Payroll\Services\PayrollCalculationService;
use App\Modules\Payroll\Services\PayrollPeriodService;
use App\Modules\Payroll\Services\PayrollRunService;
use App\Modules\Payroll\Support\PayrollRunExecutionLock;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PDO;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * A duplicate worker must not co-process a run. While another session owns the
 * run-execution lock, execute() no-ops (no cohort reconciliation, no entries, no
 * settlement); once the lock is freed, a retry executes normally.
 */
class PayrollExecutionConcurrencyTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private ?PDO $other = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (\DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL advisory locks.');
        }
    }

    protected function tearDown(): void
    {
        if ($this->other !== null) {
            try {
                $this->other->exec('SELECT pg_advisory_unlock_all()');
            } catch (\Throwable) {
            }
            $this->other = null;
        }
        parent::tearDown();
    }

    private function employee(Tenant $tenant, $owner): Employee
    {
        return $this->withinTenant($tenant, function () use ($owner) {
            $e = app(EmployeeService::class)->create(['first_name' => 'X', 'last_name' => 'Y', 'employment_status' => 'active', 'hire_date' => '2020-01-01']);
            $days = [];
            for ($w = 0; $w <= 6; $w++) {
                $days[] = ['weekday' => $w, 'is_working_day' => true, 'segments' => [['start_time' => '08:00', 'end_time' => '16:00']]];
            }
            $s = app(WorkScheduleService::class)->create(['name' => 'S'.$e->getKey(), 'code' => 'S'.$e->getKey(), 'timezone' => 'UTC'], $days);
            app(WorkScheduleService::class)->assign($s, ['scope_type' => 'employee', 'scope_id' => (string) $e->getKey(), 'effective_from' => '2020-01-01']);
            app(EmployeeCompensationService::class)->create($owner, (string) $e->getKey(), ['currency' => 'USD', 'base_amount_minor' => 300000, 'effective_from' => '2020-01-01']);

            return $e->fresh();
        });
    }

    private function heldLock(Tenant $tenant, PayrollRun $run): void
    {
        $c = config('database.connections.pgsql');
        $this->other = new PDO("pgsql:host={$c['host']};port={$c['port']};dbname={$c['database']}", $c['username'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $ns = PayrollRunExecutionLock::namespace($tenant->id, (string) $run->getKey());
        $this->other->prepare('SELECT pg_advisory_lock(hashtextextended(?, 0))')->execute([$ns]);
    }

    public function test_duplicate_worker_noops_while_another_holds_the_lock(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $emp = $this->employee($tenant, $owner);

        $run = $this->withinTenant($tenant, function () use ($owner) {
            $period = app(PayrollPeriodService::class)->create($owner, ['period_start' => '2026-09-01']);
            $r = app(PayrollRunService::class)->create($owner, $period);
            Queue::fake();
            app(PayrollCalculationService::class)->calculate($owner, $r->fresh()); // -> calculating

            return $r->fresh();
        });

        // Another worker owns execution.
        $this->heldLock($tenant, $run);

        $this->withinTenant($tenant, function () use ($run, $emp) {
            app(PayrollCalculationService::class)->execute((string) $run->getKey());
            // No-op: run stays calculating, no entries created.
            $this->assertSame(PayrollRunStatus::Calculating, $run->fresh()->status);
            $this->assertSame(0, PayrollEntry::query()->where('payroll_run_id', $run->getKey())->count());
            $this->assertSame(0, PayrollEntry::query()->where('employee_id', $emp->getKey())->count());
        });

        // Free the lock → a legitimate retry executes.
        $this->other->exec('SELECT pg_advisory_unlock_all()');
        $this->other = null;

        $this->withinTenant($tenant, function () use ($run, $emp) {
            app(PayrollCalculationService::class)->execute((string) $run->getKey());
            $this->assertSame(PayrollRunStatus::Calculated, $run->fresh()->status);
            $this->assertSame(1, PayrollEntry::query()->where('employee_id', $emp->getKey())->count());
        });
    }
}
