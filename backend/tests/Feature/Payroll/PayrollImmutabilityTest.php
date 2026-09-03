<?php

namespace Tests\Feature\Payroll;

use App\Modules\Attendance\Services\WorkScheduleService;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Payroll\Models\PayrollEntry;
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
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * DB-level immutability of FINALIZED / CLOSED payroll records enforced by triggers,
 * proven against DIRECT SQL (bypassing the application). Runs WITHOUT RefreshDatabase
 * so finalization commits real rows at transaction level 0 before the tamper attempts.
 * Every mutation must be rejected (SQLSTATE 23001, restrict_violation).
 */
class PayrollImmutabilityTest extends TestCase
{
    use InteractsWithTenancy;

    /** @return array{0:mixed,1:Tenant,2:Employee,3:PayrollRun} */
    private function finalizedRun(bool $withAdjustment = false): array
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $emp = $this->withinTenant($tenant, function () use ($owner) {
            $e = app(EmployeeService::class)->create(['first_name' => 'A', 'last_name' => 'B', 'employment_status' => 'active', 'hire_date' => '2020-01-01']);
            $days = [];
            for ($w = 0; $w <= 6; $w++) {
                $days[] = ['weekday' => $w, 'is_working_day' => true, 'segments' => [['start_time' => '08:00', 'end_time' => '16:00']]];
            }
            $s = app(WorkScheduleService::class)->create(['name' => 'S'.$e->getKey(), 'code' => 'S'.$e->getKey(), 'timezone' => 'UTC'], $days);
            app(WorkScheduleService::class)->assign($s, ['scope_type' => 'employee', 'scope_id' => (string) $e->getKey(), 'effective_from' => '2020-01-01']);
            app(EmployeeCompensationService::class)->create($owner, (string) $e->getKey(), ['currency' => 'USD', 'base_amount_minor' => 300000, 'effective_from' => '2020-01-01']);

            return $e->fresh();
        });

        $run = $this->withinTenant($tenant, function () use ($owner) {
            $period = app(PayrollPeriodService::class)->create($owner, ['period_start' => '2026-09-01']);

            return app(PayrollRunService::class)->create($owner, $period);
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
            $this->withinTenant($tenant, fn () => app(PayrollAdjustmentService::class)->create($owner, $run->fresh(), (string) $emp->getKey(), [
                'label' => 'Bonus', 'direction' => 'earning', 'amount_minor' => 50000, 'currency' => 'USD', 'reason' => 'x',
            ]));
            $calc('recalculate');
        }

        $this->withinTenant($tenant, fn () => app(PayrollFinalizationService::class)->finalize($owner, $run->fresh()));

        return [$owner, $tenant, $emp, $run];
    }

    public function test_finalized_run_cannot_be_updated_by_direct_sql(): void
    {
        [, $tenant, , $run] = $this->finalizedRun();
        $this->expectException(QueryException::class);
        $this->withinTenant($tenant, fn () => DB::table('payroll_runs')->where('id', $run->getKey())->update(['status' => 'approved']));
    }

    public function test_finalized_run_cannot_be_deleted_by_direct_sql(): void
    {
        [, $tenant, , $run] = $this->finalizedRun();
        $this->expectException(QueryException::class);
        $this->withinTenant($tenant, fn () => DB::table('payroll_runs')->where('id', $run->getKey())->delete());
    }

    public function test_finalized_entry_cannot_be_updated_by_direct_sql(): void
    {
        [, $tenant, , $run] = $this->finalizedRun();
        $this->expectException(QueryException::class);
        $this->withinTenant($tenant, fn () => DB::table('payroll_entries')->where('payroll_run_id', $run->getKey())->update(['net_minor' => 0]));
    }

    public function test_finalized_entry_cannot_be_deleted_by_direct_sql(): void
    {
        [, $tenant, , $run] = $this->finalizedRun();
        $this->expectException(QueryException::class);
        $this->withinTenant($tenant, fn () => DB::table('payroll_entries')->where('payroll_run_id', $run->getKey())->delete());
    }

    public function test_finalized_entry_line_cannot_be_updated_by_direct_sql(): void
    {
        [, $tenant, , $run] = $this->finalizedRun();
        $this->expectException(QueryException::class);
        $this->withinTenant($tenant, function () use ($run) {
            $entryId = PayrollEntry::query()->where('payroll_run_id', $run->getKey())->value('id');
            DB::table('payroll_entry_lines')->where('payroll_entry_id', $entryId)->update(['amount_minor' => 1]);
        });
    }

    public function test_finalized_entry_line_cannot_be_inserted_by_direct_sql(): void
    {
        [, $tenant, , $run] = $this->finalizedRun();
        $this->expectException(QueryException::class);
        $this->withinTenant($tenant, function () use ($run, $tenant) {
            $entryId = PayrollEntry::query()->where('payroll_run_id', $run->getKey())->value('id');
            DB::table('payroll_entry_lines')->insert([
                'id' => (string) Str::ulid(), 'tenant_id' => $tenant->id, 'payroll_entry_id' => $entryId,
                'line_code' => 'ADJUSTMENT_EARNING', 'line_type' => 'ADJUSTMENT_EARNING', 'direction' => 'earning',
                'source_type' => 'payroll_adjustment', 'label_snapshot' => 'x', 'amount_minor' => 999, 'sort_order' => 99,
                'created_at' => now(),
            ]);
        });
    }

    public function test_closed_period_cannot_be_reopened_by_direct_sql(): void
    {
        [, $tenant, , $run] = $this->finalizedRun();
        $this->expectException(QueryException::class);
        $this->withinTenant($tenant, fn () => DB::table('payroll_periods')
            ->where('id', PayrollRun::query()->where('id', $run->getKey())->value('payroll_period_id'))
            ->update(['status' => 'open']));
    }

    public function test_adjustment_in_a_closed_period_cannot_be_updated_or_deleted(): void
    {
        [, $tenant, , $run] = $this->finalizedRun(withAdjustment: true);

        $this->withinTenant($tenant, function () use ($run) {
            $adjId = DB::table('payroll_adjustments')->where('payroll_run_id', $run->getKey())->value('id');

            try {
                DB::table('payroll_adjustments')->where('id', $adjId)->update(['amount_minor' => 1]);
                $this->fail('updating an adjustment in a closed period must be rejected');
            } catch (QueryException) {
                // expected
            }

            try {
                DB::table('payroll_adjustments')->where('id', $adjId)->delete();
                $this->fail('deleting an adjustment in a closed period must be rejected');
            } catch (QueryException) {
                // expected
            }

            // The adjustment survived both rejected mutations unchanged.
            $this->assertSame(50000, (int) DB::table('payroll_adjustments')->where('id', $adjId)->value('amount_minor'));
        });
    }

    public function test_adjustment_cannot_be_inserted_into_a_closed_period(): void
    {
        [, $tenant, $emp, $run] = $this->finalizedRun();
        $this->expectException(QueryException::class);
        $this->withinTenant($tenant, fn () => DB::table('payroll_adjustments')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $tenant->id, 'payroll_run_id' => (string) $run->getKey(),
            'employee_id' => (string) $emp->getKey(), 'label' => 'x', 'direction' => 'earning', 'amount_minor' => 1,
            'currency' => 'USD', 'reason' => 'x', 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]));
    }
}
