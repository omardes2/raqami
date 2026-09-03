<?php

namespace Tests\Feature\Payroll;

use App\Modules\Attendance\Services\WorkScheduleService;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Payroll\Models\PayrollEntry;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Services\EmployeeCompensationService;
use App\Modules\Payroll\Services\PayrollAdjustmentService;
use App\Modules\Payroll\Services\PayrollCalculationService;
use App\Modules\Payroll\Services\PayrollFinalizationService;
use App\Modules\Payroll\Services\PayrollPeriodService;
use App\Modules\Payroll\Services\PayrollRunService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\CommitsPayrollAtTopLevel;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * DB-level immutability of FINALIZED / CLOSED payroll records, proven against DIRECT
 * SQL (including FK-reassignment attempts). Runs WITHOUT RefreshDatabase so real rows
 * are committed. Every mutation must be rejected (SQLSTATE 23001, restrict_violation).
 */
class PayrollImmutabilityTest extends TestCase
{
    use CommitsPayrollAtTopLevel;
    use InteractsWithTenancy;

    private ?PayrollPeriod $period = null;

    private function employee(Tenant $tenant, $owner, string $start): Employee
    {
        return $this->withinTenant($tenant, function () use ($owner, $start) {
            $e = app(EmployeeService::class)->create(['first_name' => 'A', 'last_name' => 'B', 'employment_status' => 'active', 'hire_date' => '2020-01-01']);
            $days = [];
            for ($w = 0; $w <= 6; $w++) {
                $days[] = ['weekday' => $w, 'is_working_day' => true, 'segments' => [['start_time' => '08:00', 'end_time' => '16:00']]];
            }
            $s = app(WorkScheduleService::class)->create(['name' => 'S'.$e->getKey().$start, 'code' => 'S'.$e->getKey().$start, 'timezone' => 'UTC'], $days);
            app(WorkScheduleService::class)->assign($s, ['scope_type' => 'employee', 'scope_id' => (string) $e->getKey(), 'effective_from' => '2020-01-01']);
            app(EmployeeCompensationService::class)->create($owner, (string) $e->getKey(), ['currency' => 'USD', 'base_amount_minor' => 300000, 'effective_from' => '2020-01-01']);

            return $e->fresh();
        });
    }

    /** @return array{0:mixed,1:Tenant,2:Employee,3:PayrollRun} */
    private function finalizedRun(bool $withAdjustment = false): array
    {
        [$owner, $tenant] = $this->trackedCompany();
        $emp = $this->employee($tenant, $owner, '2026-09-01');

        [$run, $this->period] = $this->withinTenant($tenant, function () use ($owner) {
            $period = app(PayrollPeriodService::class)->create($owner, ['period_start' => '2026-09-01']);

            return [app(PayrollRunService::class)->create($owner, $period), $period];
        });

        $calc = function (string $method) use ($tenant, $owner, $run) {
            $this->withinTenant($tenant, function () use ($owner, $run, $method) {
                Queue::fake();
                app(PayrollCalculationService::class)->{$method}($owner, $run->fresh());
                app(PayrollCalculationService::class)->execute((string) $run->getKey());
            });
        };
        $calc('calculate');

        if ($withAdjustment) {
            $this->withinTenant($tenant, fn () => app(PayrollAdjustmentService::class)->create($owner, $this->period->fresh(), (string) $emp->getKey(), [
                'employee_visible_label' => 'Bonus', 'direction' => 'earning', 'amount_minor' => 50000, 'currency' => 'USD', 'internal_reason' => 'x',
            ]));
            $calc('recalculate');
        }

        $this->withinTenant($tenant, fn () => app(PayrollFinalizationService::class)->finalize($owner, $run->fresh()));

        return [$owner, $tenant, $emp, $run];
    }

    public function test_finalized_run_cannot_be_updated_or_deleted(): void
    {
        [, $tenant, , $run] = $this->finalizedRun();
        $this->withinTenant($tenant, function () use ($run) {
            $this->assertRejected(fn () => DB::table('payroll_runs')->where('id', $run->getKey())->update(['status' => 'approved']));
            $this->assertRejected(fn () => DB::table('payroll_runs')->where('id', $run->getKey())->delete());
        });
    }

    public function test_finalized_entry_cannot_be_updated_or_deleted(): void
    {
        [, $tenant, , $run] = $this->finalizedRun();
        $this->withinTenant($tenant, function () use ($run) {
            $this->assertRejected(fn () => DB::table('payroll_entries')->where('payroll_run_id', $run->getKey())->update(['net_minor' => 0]));
            $this->assertRejected(fn () => DB::table('payroll_entries')->where('payroll_run_id', $run->getKey())->delete());
        });
    }

    public function test_finalized_line_cannot_be_updated_inserted_or_deleted(): void
    {
        [, $tenant, $emp, $run] = $this->finalizedRun();
        $this->withinTenant($tenant, function () use ($run, $tenant) {
            $entryId = PayrollEntry::query()->where('payroll_run_id', $run->getKey())->value('id');
            $this->assertRejected(fn () => DB::table('payroll_entry_lines')->where('payroll_entry_id', $entryId)->update(['amount_minor' => 1]));
            $this->assertRejected(fn () => DB::table('payroll_entry_lines')->where('payroll_entry_id', $entryId)->delete());
            $this->assertRejected(fn () => DB::table('payroll_entry_lines')->insert([
                'id' => (string) Str::ulid(), 'tenant_id' => $tenant->id, 'payroll_entry_id' => $entryId,
                'line_code' => 'ADJUSTMENT_EARNING', 'line_type' => 'ADJUSTMENT_EARNING', 'direction' => 'earning',
                'source_type' => 'payroll_adjustment', 'label_snapshot' => 'x', 'amount_minor' => 1, 'sort_order' => 99, 'created_at' => now(),
            ]));
        });
    }

    public function test_finalized_line_cannot_be_reparented_out(): void
    {
        [$owner, $tenant, , $finRun] = $this->finalizedRun();
        // A separate, non-finalized calculated entry to try to steal the line into.
        $emp2 = $this->employee($tenant, $owner, '2026-10-01');
        $calcRun = $this->withinTenant($tenant, function () use ($owner) {
            $period = app(PayrollPeriodService::class)->create($owner, ['period_start' => '2026-10-01']);

            return app(PayrollRunService::class)->create($owner, $period);
        });
        $this->withinTenant($tenant, function () use ($owner, $calcRun) {
            Queue::fake();
            app(PayrollCalculationService::class)->calculate($owner, $calcRun->fresh());
            app(PayrollCalculationService::class)->execute((string) $calcRun->getKey());
        });

        $this->withinTenant($tenant, function () use ($finRun, $calcRun) {
            $finEntry = PayrollEntry::query()->where('payroll_run_id', $finRun->getKey())->firstOrFail();
            $finLine = DB::table('payroll_entry_lines')->where('payroll_entry_id', $finEntry->getKey())->first();
            $target = PayrollEntry::query()->where('payroll_run_id', $calcRun->getKey())->value('id');

            $this->assertRejected(fn () => DB::table('payroll_entry_lines')->where('id', $finLine->id)->update(['payroll_entry_id' => $target]));
            // The finalized entry still has its full line set.
            $this->assertSame(1, DB::table('payroll_entry_lines')->where('payroll_entry_id', $finEntry->getKey())->count());
        });
    }

    public function test_closed_period_cannot_be_reopened_or_deleted(): void
    {
        [, $tenant, , $run] = $this->finalizedRun();
        $this->withinTenant($tenant, function () use ($run) {
            $periodId = PayrollRun::query()->where('id', $run->getKey())->value('payroll_period_id');
            $this->assertRejected(fn () => DB::table('payroll_periods')->where('id', $periodId)->update(['status' => 'open']));
            $this->assertRejected(fn () => DB::table('payroll_periods')->where('id', $periodId)->delete());
        });
    }

    public function test_closed_period_adjustment_is_immutable(): void
    {
        [, $tenant, $emp] = $this->finalizedRun(withAdjustment: true);
        $closedPeriodId = (string) $this->period->getKey();

        $this->withinTenant($tenant, function () use ($closedPeriodId, $emp, $tenant) {
            $adjId = DB::table('payroll_adjustments')->where('payroll_period_id', $closedPeriodId)->value('id');
            $this->assertRejected(fn () => DB::table('payroll_adjustments')->where('id', $adjId)->update(['amount_minor' => 1]));
            $this->assertRejected(fn () => DB::table('payroll_adjustments')->where('id', $adjId)->delete());
            $this->assertRejected(fn () => DB::table('payroll_adjustments')->insert([
                'id' => (string) Str::ulid(), 'tenant_id' => $tenant->id, 'payroll_period_id' => $closedPeriodId,
                'employee_id' => (string) $emp->getKey(), 'employee_visible_label' => 'x', 'direction' => 'earning',
                'amount_minor' => 1, 'currency' => 'USD', 'internal_reason' => 'x', 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]));
            $this->assertSame(50000, (int) DB::table('payroll_adjustments')->where('id', $adjId)->value('amount_minor'));
        });
    }

    public function test_closed_period_adjustment_cannot_be_reparented_to_open_period(): void
    {
        [$owner, $tenant] = $this->finalizedRun(withAdjustment: true);
        $closedPeriodId = (string) $this->period->getKey();

        $openPeriod = $this->withinTenant($tenant, fn () => app(PayrollPeriodService::class)->create($owner, ['period_start' => '2026-11-01']));

        $this->withinTenant($tenant, function () use ($closedPeriodId, $openPeriod) {
            $adjId = DB::table('payroll_adjustments')->where('payroll_period_id', $closedPeriodId)->value('id');
            $this->assertRejected(fn () => DB::table('payroll_adjustments')->where('id', $adjId)->update(['payroll_period_id' => (string) $openPeriod->getKey()]));
            $this->assertSame($closedPeriodId, (string) DB::table('payroll_adjustments')->where('id', $adjId)->value('payroll_period_id'));
        });
    }

    public function test_direct_sql_cannot_insert_zero_amount_adjustment(): void
    {
        [$owner, $tenant] = $this->trackedCompany();
        $emp = $this->employee($tenant, $owner, '2026-09-01');
        $period = $this->withinTenant($tenant, fn () => app(PayrollPeriodService::class)->create($owner, ['period_start' => '2026-09-01']));

        $this->withinTenant($tenant, function () use ($tenant, $period, $emp) {
            try {
                DB::table('payroll_adjustments')->insert([
                    'id' => (string) Str::ulid(), 'tenant_id' => $tenant->id, 'payroll_period_id' => (string) $period->getKey(),
                    'employee_id' => (string) $emp->getKey(), 'employee_visible_label' => 'x', 'direction' => 'earning',
                    'amount_minor' => 0, 'currency' => 'USD', 'internal_reason' => 'x', 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->fail('a zero-amount adjustment must be rejected by the DB CHECK');
            } catch (QueryException $e) {
                $this->assertSame('23514', (string) ($e->errorInfo[0] ?? ''), 'expected SQLSTATE 23514 (check_violation)');
            }
        });
    }

    private function assertRejected(callable $fn): void
    {
        try {
            $fn();
            $this->fail('expected a restrict_violation rejection');
        } catch (QueryException $e) {
            $this->assertSame('23001', (string) ($e->errorInfo[0] ?? ''), 'expected SQLSTATE 23001 (restrict_violation)');
        }
    }
}
