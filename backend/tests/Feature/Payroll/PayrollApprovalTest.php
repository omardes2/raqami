<?php

namespace Tests\Feature\Payroll;

use App\Modules\Attendance\Services\WorkScheduleService;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Payroll\Enums\PayrollRunStatus;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Services\EmployeeCompensationService;
use App\Modules\Payroll\Services\PayrollAdjustmentService;
use App\Modules\Payroll\Services\PayrollApprovalService;
use App\Modules\Payroll\Services\PayrollCalculationService;
use App\Modules\Payroll\Services\PayrollPeriodService;
use App\Modules\Payroll\Services\PayrollRunService;
use App\Modules\Payroll\Services\PayrollSettingsService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Approval takes a cleanly calculated, current, non-stale run to `approved`. Under
 * four-eyes, the approver must differ from the calculation requester. Self-payroll,
 * staleness, and cohort drift all block approval.
 */
class PayrollApprovalTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function employee(Tenant $tenant, $owner): Employee
    {
        return $this->withinTenant($tenant, function () use ($owner) {
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
    }

    private function calculatedRun(Tenant $tenant, $owner): PayrollRun
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

    private function fourEyes(Tenant $tenant, $owner): void
    {
        $this->withinTenant($tenant, fn () => app(PayrollSettingsService::class)->update($owner, ['require_four_eyes' => true]));
    }

    public function test_approve_transitions_calculated_to_approved(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $this->employee($tenant, $owner);
        $run = $this->calculatedRun($tenant, $owner);

        $approved = $this->withinTenant($tenant, fn () => app(PayrollApprovalService::class)->approve($owner, $run));
        $this->assertSame(PayrollRunStatus::Approved, $approved->status);
        $this->assertSame((string) $owner->getKey(), (string) $approved->approved_by_user_id);
    }

    public function test_approve_rejects_a_draft_run(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $run = $this->withinTenant($tenant, function () use ($owner) {
            $period = app(PayrollPeriodService::class)->create($owner, ['period_start' => '2026-09-01']);

            return app(PayrollRunService::class)->create($owner, $period);
        });

        $this->expectException(ValidationException::class);
        $this->withinTenant($tenant, fn () => app(PayrollApprovalService::class)->approve($owner, $run->fresh()));
    }

    public function test_four_eyes_blocks_the_calculation_requester_from_approving(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $this->employee($tenant, $owner);
        $this->fourEyes($tenant, $owner);
        $run = $this->calculatedRun($tenant, $owner);

        $this->expectException(ValidationException::class);
        $this->withinTenant($tenant, fn () => app(PayrollApprovalService::class)->approve($owner, $run));
    }

    public function test_four_eyes_allows_a_distinct_approver(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $this->employee($tenant, $owner);
        $this->fourEyes($tenant, $owner);
        $run = $this->calculatedRun($tenant, $owner);
        $approver = $this->makeUser();

        $approved = $this->withinTenant($tenant, fn () => app(PayrollApprovalService::class)->approve($approver, $run));
        $this->assertSame(PayrollRunStatus::Approved, $approved->status);
        $this->assertSame((string) $approver->getKey(), (string) $approved->approved_by_user_id);
    }

    public function test_approve_blocks_a_stale_run(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $emp = $this->employee($tenant, $owner);
        $run = $this->calculatedRun($tenant, $owner);

        $this->withinTenant($tenant, fn () => app(PayrollAdjustmentService::class)->create($owner, $run, (string) $emp->getKey(), [
            'label' => 'Bonus', 'direction' => 'earning', 'amount_minor' => 50000, 'currency' => 'USD', 'reason' => 'x',
        ]));

        $this->expectException(ValidationException::class);
        $this->withinTenant($tenant, fn () => app(PayrollApprovalService::class)->approve($owner, $run->fresh()));
    }

    public function test_approve_blocks_a_cohort_drift(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $this->employee($tenant, $owner);
        $run = $this->calculatedRun($tenant, $owner);

        // A new employee now overlaps the period → cohort no longer matches entries.
        $this->employee($tenant, $owner);

        $this->expectException(ValidationException::class);
        $this->withinTenant($tenant, fn () => app(PayrollApprovalService::class)->approve($owner, $run->fresh()));
    }

    public function test_self_payroll_approval_is_blocked(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $emp = $this->employee($tenant, $owner);
        $this->withinTenant($tenant, fn () => $emp->forceFill(['user_id' => (string) $owner->getKey()])->save());
        $run = $this->calculatedRun($tenant, $owner);

        $this->expectException(HttpException::class);
        $this->withinTenant($tenant, fn () => app(PayrollApprovalService::class)->approve($owner, $run));
    }
}
