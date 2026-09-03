<?php

namespace Tests\Feature\Payroll;

use App\Modules\Attendance\Services\WorkScheduleService;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Payroll\Enums\PayrollEntryStatus;
use App\Modules\Payroll\Models\PayrollEntry;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Services\EmployeeCompensationService;
use App\Modules\Payroll\Services\PayrollCalculationService;
use App\Modules\Payroll\Services\PayrollPeriodService;
use App\Modules\Payroll\Services\PayrollRunService;
use App\Modules\Payroll\Services\PayrollRunSummaryService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Recalculation reconciles the cohort (B-1): employees no longer overlapping the
 * period lose their obsolete non-finalized entries; new members gain one; a
 * mid-period-terminated employee stays; a soft-deleted historical employee stays
 * (payroll entitlement is the employment interval, not UI visibility).
 */
class PayrollCohortReconciliationTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function employee(Tenant $tenant, $owner, array $attrs = []): Employee
    {
        return $this->withinTenant($tenant, function () use ($owner, $attrs) {
            $e = app(EmployeeService::class)->create(array_merge(['first_name' => 'E', 'last_name' => 'E', 'employment_status' => 'active', 'hire_date' => '2020-01-01'], $attrs));
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

    private function entryIds(Tenant $tenant, PayrollRun $run): array
    {
        return $this->withinTenant($tenant, fn () => PayrollEntry::query()->where('payroll_run_id', $run->getKey())->pluck('employee_id')->sort()->values()->all());
    }

    public function test_recalculation_removes_obsolete_and_adds_new_cohort_member(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $a = $this->employee($tenant, $owner);
        $b = $this->employee($tenant, $owner);
        $run = $this->makeRun($tenant, $owner);
        $this->calc($tenant, $owner, $run);

        $this->assertEqualsCanonicalizing([(string) $a->getKey(), (string) $b->getKey()], $this->entryIds($tenant, $run));

        // B leaves the period (hired after it); C newly enters.
        $this->withinTenant($tenant, fn () => $b->forceFill(['hire_date' => '2026-10-01'])->save());
        $c = $this->employee($tenant, $owner);

        $this->calc($tenant, $owner, $run, 'recalculate');

        $ids = $this->entryIds($tenant, $run);
        $this->assertEqualsCanonicalizing([(string) $a->getKey(), (string) $c->getKey()], $ids);
        $this->assertNotContains((string) $b->getKey(), $ids, 'obsolete B entry must be removed');

        $summary = $this->withinTenant($tenant, fn () => app(PayrollRunSummaryService::class)->summary($run->fresh()));
        $this->assertSame(2, $summary['counts']['cohort']);
        $this->assertSame(2, $summary['counts']['calculated']);
        $this->assertSame(600000, $summary['by_currency'][0]['gross_minor']); // A + C, not B
    }

    public function test_obsolete_failed_entry_is_also_reconciled(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $a = $this->employee($tenant, $owner);
        // B has no compensation → will FAIL, then leave the cohort.
        $b = $this->withinTenant($tenant, fn () => app(EmployeeService::class)->create(['first_name' => 'B', 'last_name' => 'B', 'employment_status' => 'active', 'hire_date' => '2020-01-01']));
        $run = $this->makeRun($tenant, $owner);
        $this->calc($tenant, $owner, $run);
        $this->assertContains((string) $b->getKey(), $this->entryIds($tenant, $run));

        $this->withinTenant($tenant, fn () => $b->forceFill(['termination_date' => '2020-01-01'])->save()); // ended before period
        $this->calc($tenant, $owner, $run, 'recalculate');

        $this->assertEqualsCanonicalizing([(string) $a->getKey()], $this->entryIds($tenant, $run));
    }

    public function test_mid_period_terminated_employee_is_retained(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $emp = $this->employee($tenant, $owner, ['termination_date' => '2026-09-20']);
        $run = $this->makeRun($tenant, $owner);
        $this->calc($tenant, $owner, $run);
        $this->calc($tenant, $owner, $run, 'recalculate');

        $this->assertContains((string) $emp->getKey(), $this->entryIds($tenant, $run));
    }

    public function test_soft_deleted_historical_employee_is_retained(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $emp = $this->employee($tenant, $owner);
        $run = $this->makeRun($tenant, $owner);
        $this->calc($tenant, $owner, $run);

        // Soft-delete after the period; historical payroll must still resolve them.
        $this->withinTenant($tenant, fn () => Employee::query()->whereKey($emp->getKey())->firstOrFail()->delete());

        $this->calc($tenant, $owner, $run, 'recalculate');

        $this->withinTenant($tenant, function () use ($run, $emp) {
            $entry = PayrollEntry::query()->where('payroll_run_id', $run->getKey())->where('employee_id', $emp->getKey())->first();
            $this->assertNotNull($entry, 'soft-deleted historical employee remains in the cohort');
            $this->assertSame(PayrollEntryStatus::Calculated, $entry->status);
        });
    }
}
