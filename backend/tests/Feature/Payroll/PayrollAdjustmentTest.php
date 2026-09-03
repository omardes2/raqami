<?php

namespace Tests\Feature\Payroll;

use App\Modules\Attendance\Services\WorkScheduleService;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Payroll\Calculation\PayrollStaleInputService;
use App\Modules\Payroll\Models\PayrollAdjustment;
use App\Modules\Payroll\Models\PayrollEntry;
use App\Modules\Payroll\Models\PayrollEntryLine;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Services\EmployeeCompensationComponentService;
use App\Modules\Payroll\Services\EmployeeCompensationService;
use App\Modules\Payroll\Services\PayrollAdjustmentService;
use App\Modules\Payroll\Services\PayrollApprovalService;
use App\Modules\Payroll\Services\PayrollCalculationService;
use App\Modules\Payroll\Services\PayrollComponentService;
use App\Modules\Payroll\Services\PayrollPeriodService;
use App\Modules\Payroll\Services\PayrollRunService;
use App\Modules\Payroll\Services\PayrollSettingsService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Manual adjustments are PERIOD-owned authoritative inputs: they feed an ADJUSTMENT
 * line, survive recalculation, are consumed by any run of the period, and make an
 * entry stale until recalculated. Reason is private; self-payroll blocked; eligibility
 * and mutation window enforced; amount strictly positive at the DB.
 */
class PayrollAdjustmentTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function employee(Tenant $tenant, $owner, array $attrs = []): Employee
    {
        return $this->withinTenant($tenant, function () use ($owner, $attrs) {
            $e = app(EmployeeService::class)->create(array_merge(['first_name' => 'A', 'last_name' => 'B', 'employment_status' => 'active', 'hire_date' => '2020-01-01'], $attrs));
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

    private function period(Tenant $tenant, $owner, string $start = '2026-09-01'): PayrollPeriod
    {
        return $this->withinTenant($tenant, fn () => app(PayrollPeriodService::class)->create($owner, ['period_start' => $start]));
    }

    private function makeRun(Tenant $tenant, $owner, PayrollPeriod $period): PayrollRun
    {
        return $this->withinTenant($tenant, fn () => app(PayrollRunService::class)->create($owner, $period));
    }

    private function calc(Tenant $tenant, $owner, PayrollRun $run, string $method = 'calculate'): void
    {
        $this->withinTenant($tenant, function () use ($owner, $run, $method) {
            Queue::fake();
            app(PayrollCalculationService::class)->{$method}($owner, $run->fresh());
            app(PayrollCalculationService::class)->execute((string) $run->getKey());
        });
    }

    private function addAdjustment(Tenant $tenant, $owner, PayrollPeriod $period, Employee $emp, array $overrides = []): PayrollAdjustment
    {
        return $this->withinTenant($tenant, fn () => app(PayrollAdjustmentService::class)->create($owner, $period->fresh(), (string) $emp->getKey(), array_merge([
            'employee_visible_label' => 'Bonus', 'direction' => 'earning', 'amount_minor' => 50000, 'currency' => 'USD', 'internal_reason' => 'Spot bonus',
        ], $overrides)));
    }

    private function entry(Tenant $tenant, PayrollRun $run, Employee $emp): PayrollEntry
    {
        return $this->withinTenant($tenant, fn () => PayrollEntry::query()->where('payroll_run_id', $run->getKey())->where('employee_id', $emp->getKey())->firstOrFail());
    }

    public function test_adjustment_is_period_owned_not_run_owned(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $emp = $this->employee($tenant, $owner);
        $period = $this->period($tenant, $owner);
        $run = $this->makeRun($tenant, $owner, $period);
        $adj = $this->addAdjustment($tenant, $owner, $period, $emp);

        $this->withinTenant($tenant, function () use ($adj, $period) {
            $this->assertSame((string) $period->getKey(), (string) $adj->payroll_period_id);
            $this->assertFalse(Schema::hasColumn('payroll_adjustments', 'payroll_run_id'));
        });
    }

    public function test_earning_and_deduction_adjustments_fold_in(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $emp = $this->employee($tenant, $owner);
        $period = $this->period($tenant, $owner);
        $run = $this->makeRun($tenant, $owner, $period);
        $this->calc($tenant, $owner, $run);
        $this->assertSame(300000, $this->entry($tenant, $run, $emp)->gross_minor);

        $this->addAdjustment($tenant, $owner, $period, $emp, ['direction' => 'earning', 'amount_minor' => 50000]);
        $this->addAdjustment($tenant, $owner, $period, $emp, ['direction' => 'deduction', 'amount_minor' => 20000]);
        $this->withinTenant($tenant, fn () => $this->assertTrue(app(PayrollStaleInputService::class)->isStale($this->entry($tenant, $run, $emp))));

        $this->calc($tenant, $owner, $run, 'recalculate');
        $entry = $this->entry($tenant, $run, $emp);
        $this->assertSame(350000, $entry->gross_minor);
        $this->assertSame(20000, $entry->deduction_minor);
        $this->assertSame(330000, $entry->net_minor);

        $line = $this->withinTenant($tenant, fn () => PayrollEntryLine::query()->where('payroll_entry_id', $entry->getKey())->where('line_code', 'ADJUSTMENT_EARNING')->firstOrFail());
        $this->assertSame('Bonus', $line->label_snapshot);
    }

    public function test_replacement_run_consumes_the_same_adjustment(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $emp = $this->employee($tenant, $owner);
        $period = $this->period($tenant, $owner);
        $r1 = $this->makeRun($tenant, $owner, $period);
        $adj = $this->addAdjustment($tenant, $owner, $period, $emp, ['amount_minor' => 50000]);
        $this->calc($tenant, $owner, $r1);
        $this->assertSame(350000, $this->entry($tenant, $r1, $emp)->gross_minor);

        // Cancel R1, create a replacement R2 for the SAME period.
        $this->withinTenant($tenant, fn () => app(PayrollRunService::class)->cancel($owner, $r1->fresh()));
        $r2 = $this->makeRun($tenant, $owner, $period);
        $this->calc($tenant, $owner, $r2);

        // Same adjustment id, no new row, identical amount.
        $entry2 = $this->entry($tenant, $r2, $emp);
        $this->assertSame(350000, $entry2->gross_minor);
        $this->withinTenant($tenant, function () use ($period, $adj) {
            $this->assertSame(1, PayrollAdjustment::query()->where('payroll_period_id', $period->getKey())->count());
            $this->assertNotNull(PayrollAdjustment::query()->whereKey($adj->getKey())->first());
        });
    }

    public function test_patch_updates_amount_and_bumps_version_and_restales(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $emp = $this->employee($tenant, $owner);
        $period = $this->period($tenant, $owner);
        $run = $this->makeRun($tenant, $owner, $period);
        $adj = $this->addAdjustment($tenant, $owner, $period, $emp, ['amount_minor' => 50000]);
        $this->calc($tenant, $owner, $run);
        $this->assertSame(350000, $this->entry($tenant, $run, $emp)->gross_minor);

        $updated = $this->withinTenant($tenant, fn () => app(PayrollAdjustmentService::class)->update($owner, $adj->fresh(), ['amount_minor' => 70000]));
        $this->assertSame(70000, (int) $updated->amount_minor);
        $this->assertSame(2, (int) $updated->version);
        $this->withinTenant($tenant, fn () => $this->assertTrue(app(PayrollStaleInputService::class)->isStale($this->entry($tenant, $run, $emp))));

        $this->calc($tenant, $owner, $run, 'recalculate');
        $this->assertSame(370000, $this->entry($tenant, $run, $emp)->gross_minor);
    }

    public function test_delete_makes_entry_stale(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $emp = $this->employee($tenant, $owner);
        $period = $this->period($tenant, $owner);
        $run = $this->makeRun($tenant, $owner, $period);
        $adj = $this->addAdjustment($tenant, $owner, $period, $emp, ['amount_minor' => 50000]);
        $this->calc($tenant, $owner, $run);
        $this->withinTenant($tenant, fn () => app(PayrollAdjustmentService::class)->delete($owner, $adj->fresh()));
        $this->withinTenant($tenant, fn () => $this->assertTrue(app(PayrollStaleInputService::class)->isStale($this->entry($tenant, $run, $emp))));
        $this->calc($tenant, $owner, $run, 'recalculate');
        $this->assertSame(300000, $this->entry($tenant, $run, $emp)->gross_minor);
    }

    public function test_self_payroll_adjustment_blocked(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $emp = $this->employee($tenant, $owner);
        $this->withinTenant($tenant, fn () => $emp->forceFill(['user_id' => (string) $owner->getKey()])->save());
        $period = $this->period($tenant, $owner);

        $this->expectException(HttpException::class);
        $this->addAdjustment($tenant, $owner, $period, $emp);
    }

    public function test_adjustment_blocked_once_run_approved(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $emp = $this->employee($tenant, $owner);
        $period = $this->period($tenant, $owner);
        $run = $this->makeRun($tenant, $owner, $period);
        $this->calc($tenant, $owner, $run);
        $this->withinTenant($tenant, fn () => app(PayrollSettingsService::class)->update($owner, ['require_four_eyes' => true]));
        $this->withinTenant($tenant, fn () => app(PayrollApprovalService::class)->approve($this->makeUser(), $run->fresh()));

        $this->expectException(ValidationException::class);
        $this->addAdjustment($tenant, $owner, $period, $emp);
    }

    public function test_employee_outside_period_is_rejected(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        // Terminated before the period starts → not in the period's cohort.
        $emp = $this->employee($tenant, $owner, ['termination_date' => '2026-08-01']);
        $period = $this->period($tenant, $owner);

        $this->expectException(ValidationException::class);
        $this->addAdjustment($tenant, $owner, $period, $emp);
    }

    public function test_source_entry_must_be_prior_finalized_same_employee(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $emp = $this->employee($tenant, $owner);
        $period = $this->period($tenant, $owner);
        // A non-finalized entry from the same period is not a valid source.
        $run = $this->makeRun($tenant, $owner, $period);
        $this->calc($tenant, $owner, $run);
        $badSource = (string) $this->entry($tenant, $run, $emp)->getKey();

        $this->expectException(ValidationException::class);
        $this->addAdjustment($tenant, $owner, $period, $emp, ['source_payroll_entry_id' => $badSource]);
    }

    public function test_adjustment_golden_case(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        // Base 1,000,000 with a 100,000 fixed deduction component → gross 1,000,000 / ded 100,000 / net 900,000.
        $emp = $this->withinTenant($tenant, function () use ($owner) {
            $e = app(EmployeeService::class)->create(['first_name' => 'G', 'last_name' => 'C', 'employment_status' => 'active', 'hire_date' => '2020-01-01']);
            $days = [];
            for ($w = 0; $w <= 6; $w++) {
                $days[] = ['weekday' => $w, 'is_working_day' => true, 'segments' => [['start_time' => '08:00', 'end_time' => '16:00']]];
            }
            $s = app(WorkScheduleService::class)->create(['name' => 'S'.$e->getKey(), 'code' => 'S'.$e->getKey(), 'timezone' => 'UTC'], $days);
            app(WorkScheduleService::class)->assign($s, ['scope_type' => 'employee', 'scope_id' => (string) $e->getKey(), 'effective_from' => '2020-01-01']);
            app(EmployeeCompensationService::class)->create($owner, (string) $e->getKey(), ['currency' => 'USD', 'base_amount_minor' => 1000000, 'effective_from' => '2020-01-01']);
            $c = app(PayrollComponentService::class)->create($owner, ['code' => 'DED', 'name' => 'Deduction', 'type' => 'deduction', 'calculation_mode' => 'fixed']);
            app(EmployeeCompensationComponentService::class)->assign($owner, (string) $e->getKey(), ['payroll_component_id' => (string) $c->getKey(), 'fixed_amount_minor' => 100000, 'currency' => 'USD', 'effective_from' => '2020-01-01']);

            return $e->fresh();
        });
        $period = $this->period($tenant, $owner);
        $run = $this->makeRun($tenant, $owner, $period);
        $this->calc($tenant, $owner, $run);
        $base = $this->entry($tenant, $run, $emp);
        $this->assertSame(1000000, $base->gross_minor);
        $this->assertSame(100000, $base->deduction_minor);
        $this->assertSame(900000, $base->net_minor);

        $this->addAdjustment($tenant, $owner, $period, $emp, ['direction' => 'earning', 'amount_minor' => 250000]);
        $this->addAdjustment($tenant, $owner, $period, $emp, ['direction' => 'deduction', 'amount_minor' => 50000]);
        $this->calc($tenant, $owner, $run, 'recalculate');

        $entry = $this->entry($tenant, $run, $emp);
        $this->assertSame(1250000, $entry->gross_minor);
        $this->assertSame(150000, $entry->deduction_minor);
        $this->assertSame(1100000, $entry->net_minor);
    }
}
