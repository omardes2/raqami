<?php

namespace Tests\Feature\Payroll;

use App\Modules\Attendance\Services\WorkScheduleService;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Payroll\Calculation\PayrollStaleInputService;
use App\Modules\Payroll\Models\PayrollAdjustment;
use App\Modules\Payroll\Models\PayrollEntry;
use App\Modules\Payroll\Models\PayrollEntryLine;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Services\EmployeeCompensationService;
use App\Modules\Payroll\Services\PayrollAdjustmentService;
use App\Modules\Payroll\Services\PayrollApprovalService;
use App\Modules\Payroll\Services\PayrollCalculationService;
use App\Modules\Payroll\Services\PayrollPeriodService;
use App\Modules\Payroll\Services\PayrollRunService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Manual adjustments are authoritative calculation INPUTS keyed by (run, employee):
 * they feed an ADJUSTMENT line, survive recalculation, and make the entry stale until
 * recalculated. Reason is mandatory; self-payroll is blocked; changes are refused
 * once the run leaves the pre-approval states.
 */
class PayrollAdjustmentTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function employee(Tenant $tenant, $owner, int $base = 300000): Employee
    {
        return $this->withinTenant($tenant, function () use ($owner, $base) {
            $e = app(EmployeeService::class)->create(['first_name' => 'A', 'last_name' => 'B', 'employment_status' => 'active', 'hire_date' => '2020-01-01']);
            $days = [];
            for ($w = 0; $w <= 6; $w++) {
                $days[] = ['weekday' => $w, 'is_working_day' => true, 'segments' => [['start_time' => '08:00', 'end_time' => '16:00']]];
            }
            $s = app(WorkScheduleService::class)->create(['name' => 'S'.$e->getKey(), 'code' => 'S'.$e->getKey(), 'timezone' => 'UTC'], $days);
            app(WorkScheduleService::class)->assign($s, ['scope_type' => 'employee', 'scope_id' => (string) $e->getKey(), 'effective_from' => '2020-01-01']);
            app(EmployeeCompensationService::class)->create($owner, (string) $e->getKey(), ['currency' => 'USD', 'base_amount_minor' => $base, 'effective_from' => '2020-01-01']);

            return $e->fresh();
        });
    }

    private function makeRun(Tenant $tenant, $owner): PayrollRun
    {
        return $this->withinTenant($tenant, function () use ($owner) {
            $period = app(PayrollPeriodService::class)->create($owner, ['period_start' => '2026-09-01']);

            return app(PayrollRunService::class)->create($owner, $period);
        });
    }

    private function calc(Tenant $tenant, $owner, PayrollRun $run, string $method = 'calculate'): void
    {
        $this->withinTenant($tenant, function () use ($owner, $run, $method) {
            Queue::fake();
            app(PayrollCalculationService::class)->{$method}($owner, $run->fresh());
            app(PayrollCalculationService::class)->execute((string) $run->getKey());
        });
    }

    private function entry(Tenant $tenant, PayrollRun $run, Employee $emp): PayrollEntry
    {
        return $this->withinTenant($tenant, fn () => PayrollEntry::query()->where('payroll_run_id', $run->getKey())->where('employee_id', $emp->getKey())->firstOrFail());
    }

    public function test_earning_adjustment_folds_into_recalculated_entry(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $emp = $this->employee($tenant, $owner);
        $run = $this->makeRun($tenant, $owner);
        $this->calc($tenant, $owner, $run);
        $this->assertSame(300000, $this->entry($tenant, $run, $emp)->gross_minor);

        $this->withinTenant($tenant, fn () => app(PayrollAdjustmentService::class)->create($owner, $run->fresh(), (string) $emp->getKey(), [
            'label' => 'Bonus', 'direction' => 'earning', 'amount_minor' => 50000, 'currency' => 'USD', 'reason' => 'Spot bonus',
        ]));

        // Adding an adjustment makes the entry stale until recalculated.
        $this->withinTenant($tenant, fn () => $this->assertTrue(app(PayrollStaleInputService::class)->isStale($this->entry($tenant, $run, $emp))));

        $this->calc($tenant, $owner, $run, 'recalculate');

        $entry = $this->entry($tenant, $run, $emp);
        $this->assertSame(350000, $entry->gross_minor);
        $this->assertSame(0, $entry->deduction_minor);
        $this->assertSame(350000, $entry->net_minor);
        $this->withinTenant($tenant, fn () => $this->assertFalse(app(PayrollStaleInputService::class)->isStale($entry)));

        $line = $this->withinTenant($tenant, fn () => PayrollEntryLine::query()->where('payroll_entry_id', $entry->getKey())->where('line_code', 'ADJUSTMENT_EARNING')->firstOrFail());
        $this->assertSame(50000, (int) $line->amount_minor);
        $this->assertSame('Bonus', $line->label_snapshot);
    }

    public function test_deduction_adjustment_folds_into_recalculated_entry(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $emp = $this->employee($tenant, $owner);
        $run = $this->makeRun($tenant, $owner);
        $this->calc($tenant, $owner, $run);

        $this->withinTenant($tenant, fn () => app(PayrollAdjustmentService::class)->create($owner, $run->fresh(), (string) $emp->getKey(), [
            'label' => 'Loan', 'direction' => 'deduction', 'amount_minor' => 40000, 'currency' => 'USD', 'reason' => 'Loan repayment',
        ]));
        $this->calc($tenant, $owner, $run, 'recalculate');

        $entry = $this->entry($tenant, $run, $emp);
        $this->assertSame(300000, $entry->gross_minor);
        $this->assertSame(40000, $entry->deduction_minor);
        $this->assertSame(260000, $entry->net_minor);
    }

    public function test_adjustment_survives_recalculation(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $emp = $this->employee($tenant, $owner);
        $run = $this->makeRun($tenant, $owner);
        $this->calc($tenant, $owner, $run);
        $this->withinTenant($tenant, fn () => app(PayrollAdjustmentService::class)->create($owner, $run->fresh(), (string) $emp->getKey(), [
            'label' => 'Bonus', 'direction' => 'earning', 'amount_minor' => 50000, 'currency' => 'USD', 'reason' => 'x',
        ]));
        $this->calc($tenant, $owner, $run, 'recalculate');
        $this->calc($tenant, $owner, $run, 'recalculate');

        // The adjustment row still exists and is still folded in after two recalcs.
        $this->withinTenant($tenant, fn () => $this->assertSame(1, PayrollAdjustment::query()->where('payroll_run_id', $run->getKey())->count()));
        $this->assertSame(350000, $this->entry($tenant, $run, $emp)->gross_minor);
    }

    public function test_delete_adjustment_makes_entry_stale_until_recalculated(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $emp = $this->employee($tenant, $owner);
        $run = $this->makeRun($tenant, $owner);
        $this->calc($tenant, $owner, $run);
        $adj = $this->withinTenant($tenant, fn () => app(PayrollAdjustmentService::class)->create($owner, $run->fresh(), (string) $emp->getKey(), [
            'label' => 'Bonus', 'direction' => 'earning', 'amount_minor' => 50000, 'currency' => 'USD', 'reason' => 'x',
        ]));
        $this->calc($tenant, $owner, $run, 'recalculate');
        $this->assertSame(350000, $this->entry($tenant, $run, $emp)->gross_minor);

        $this->withinTenant($tenant, fn () => app(PayrollAdjustmentService::class)->delete($owner, $adj->fresh()));
        $this->withinTenant($tenant, fn () => $this->assertTrue(app(PayrollStaleInputService::class)->isStale($this->entry($tenant, $run, $emp))));

        $this->calc($tenant, $owner, $run, 'recalculate');
        $this->assertSame(300000, $this->entry($tenant, $run, $emp)->gross_minor);
    }

    public function test_self_payroll_adjustment_is_blocked(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $emp = $this->employee($tenant, $owner);
        // Link the owner to the employee → self payroll.
        $this->withinTenant($tenant, fn () => $emp->forceFill(['user_id' => (string) $owner->getKey()])->save());
        $run = $this->makeRun($tenant, $owner);
        $this->calc($tenant, $owner, $run);

        $this->expectException(HttpException::class);
        $this->withinTenant($tenant, fn () => app(PayrollAdjustmentService::class)->create($owner, $run->fresh(), (string) $emp->getKey(), [
            'label' => 'x', 'direction' => 'earning', 'amount_minor' => 1, 'currency' => 'USD', 'reason' => 'x',
        ]));
    }

    public function test_adjustment_blocked_once_run_approved(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $emp = $this->employee($tenant, $owner);
        $run = $this->makeRun($tenant, $owner);
        $this->calc($tenant, $owner, $run);
        // Approve with a distinct actor (four-eyes off by default, so requester may approve too).
        $this->withinTenant($tenant, fn () => app(PayrollApprovalService::class)->approve($owner, $run->fresh()));

        $this->expectException(ValidationException::class);
        $this->withinTenant($tenant, fn () => app(PayrollAdjustmentService::class)->create($owner, $run->fresh(), (string) $emp->getKey(), [
            'label' => 'x', 'direction' => 'earning', 'amount_minor' => 1, 'currency' => 'USD', 'reason' => 'x',
        ]));
    }
}
