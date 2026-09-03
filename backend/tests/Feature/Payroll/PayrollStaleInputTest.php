<?php

namespace Tests\Feature\Payroll;

use App\Modules\Attendance\Services\WorkScheduleService;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Payroll\Calculation\PayrollStaleInputService;
use App\Modules\Payroll\Models\PayrollEntry;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Services\EmployeeCompensationService;
use App\Modules\Payroll\Services\PayrollCalculationService;
use App\Modules\Payroll\Services\PayrollPeriodService;
use App\Modules\Payroll\Services\PayrollRunService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/** Staleness detection + input-snapshot shape (Phase 2A; consumed by Phase 2B). */
class PayrollStaleInputTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function employee(Tenant $tenant, $owner, array $attrs = [], ?string $compTo = null): Employee
    {
        return $this->withinTenant($tenant, function () use ($owner, $attrs, $compTo) {
            $employee = app(EmployeeService::class)->create(array_merge(['first_name' => 'S', 'last_name' => 'I', 'employment_status' => 'active', 'hire_date' => '2020-01-01'], $attrs));
            $days = [];
            for ($w = 0; $w <= 6; $w++) {
                $days[] = ['weekday' => $w, 'is_working_day' => true, 'segments' => [['start_time' => '08:00', 'end_time' => '16:00']]];
            }
            $schedule = app(WorkScheduleService::class)->create(['name' => 'S'.$employee->getKey(), 'code' => 'S'.$employee->getKey(), 'timezone' => 'UTC'], $days);
            app(WorkScheduleService::class)->assign($schedule, ['scope_type' => 'employee', 'scope_id' => (string) $employee->getKey(), 'effective_from' => '2020-01-01']);
            app(EmployeeCompensationService::class)->create($owner, (string) $employee->getKey(), ['currency' => 'USD', 'base_amount_minor' => 300000, 'effective_from' => '2020-01-01', 'effective_to' => $compTo]);

            return $employee->fresh();
        });
    }

    private function calc(Tenant $tenant, $owner): PayrollRun
    {
        return $this->withinTenant($tenant, function () use ($owner) {
            $period = app(PayrollPeriodService::class)->create($owner, ['period_start' => '2026-09-01']);
            $run = app(PayrollRunService::class)->create($owner, $period);
            Queue::fake();
            app(PayrollCalculationService::class)->calculate($owner, $run->fresh());
            app(PayrollCalculationService::class)->execute((string) $run->getKey());

            return $run->fresh();
        });
    }

    public function test_snapshot_has_canonical_shape_without_pii(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $emp = $this->employee($tenant, $owner);
        $run = $this->calc($tenant, $owner);

        $this->withinTenant($tenant, function () use ($emp) {
            $snap = PayrollEntry::query()->where('employee_id', $emp->getKey())->firstOrFail()->input_snapshot;
            $this->assertSame(1, $snap['schema_version']);
            $this->assertSame('core-v1', $snap['calculation_version']);
            $this->assertArrayHasKey('period', $snap);
            $this->assertArrayHasKey('days', $snap['schedule']);
            $this->assertArrayHasKey('id', $snap['compensations'][0]);
            $this->assertArrayHasKey('version', $snap['compensations'][0]);
            // No volatile timestamps or PII leaked into the compensation facts.
            $this->assertArrayNotHasKey('created_at', $snap['compensations'][0]);
            $this->assertArrayNotHasKey('updated_at', $snap['compensations'][0]);
        });
    }

    public function test_not_stale_when_nothing_relevant_changed(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        // Existing comp ends 2026-12-31 so a later comp can be added without overlap.
        $emp = $this->employee($tenant, $owner, [], '2026-12-31');
        $run = $this->calc($tenant, $owner);

        $this->withinTenant($tenant, function () use ($owner, $emp) {
            $entry = PayrollEntry::query()->where('employee_id', $emp->getKey())->firstOrFail();
            // A FUTURE compensation entirely outside the period must not affect it.
            app(EmployeeCompensationService::class)->create($owner, (string) $emp->getKey(), ['currency' => 'USD', 'base_amount_minor' => 999999, 'effective_from' => '2027-01-01']);
            $this->assertFalse(app(PayrollStaleInputService::class)->isStale($entry->fresh()));
        });
    }

    public function test_stale_when_employment_changes(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $emp = $this->employee($tenant, $owner);
        $run = $this->calc($tenant, $owner);

        $this->withinTenant($tenant, function () use ($emp) {
            $entry = PayrollEntry::query()->where('employee_id', $emp->getKey())->firstOrFail();
            $emp->forceFill(['hire_date' => '2026-09-16'])->save();
            $this->assertTrue(app(PayrollStaleInputService::class)->isStale($entry->fresh()));
        });
    }

    public function test_unrelated_employee_change_does_not_make_stale(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $a = $this->employee($tenant, $owner);
        $b = $this->employee($tenant, $owner);
        $run = $this->calc($tenant, $owner);

        $this->withinTenant($tenant, function () use ($a, $b) {
            $entryA = PayrollEntry::query()->where('employee_id', $a->getKey())->firstOrFail();
            $b->forceFill(['hire_date' => '2026-09-20'])->save();
            $this->assertFalse(app(PayrollStaleInputService::class)->isStale($entryA->fresh()));
        });
    }
}
