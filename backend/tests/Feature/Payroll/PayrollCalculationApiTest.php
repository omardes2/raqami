<?php

namespace Tests\Feature\Payroll;

use App\Modules\Attendance\Services\WorkScheduleService;
use App\Modules\Authorization\Services\RoleAssignmentService;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Identity\Models\TenantMembership;
use App\Modules\Payroll\Jobs\PayrollCalculationJob;
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

class PayrollCalculationApiTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function seedEmployee(Tenant $tenant, $owner, string $currency = 'USD', int $base = 300000): Employee
    {
        return $this->withinTenant($tenant, function () use ($owner, $currency, $base) {
            $employee = app(EmployeeService::class)->create(['first_name' => 'A', 'last_name' => 'B', 'employment_status' => 'active', 'hire_date' => '2020-01-01']);
            $days = [];
            for ($w = 0; $w <= 6; $w++) {
                $days[] = ['weekday' => $w, 'is_working_day' => true, 'segments' => [['start_time' => '08:00', 'end_time' => '16:00']]];
            }
            $schedule = app(WorkScheduleService::class)->create(['name' => 'S'.$employee->getKey(), 'code' => 'S'.$employee->getKey(), 'timezone' => 'UTC'], $days);
            app(WorkScheduleService::class)->assign($schedule, ['scope_type' => 'employee', 'scope_id' => (string) $employee->getKey(), 'effective_from' => '2020-01-01']);
            app(EmployeeCompensationService::class)->create($owner, (string) $employee->getKey(), ['currency' => $currency, 'base_amount_minor' => $base, 'effective_from' => '2020-01-01']);

            return $employee->fresh();
        });
    }

    private function makeRun(Tenant $tenant, $owner): PayrollRun
    {
        return $this->withinTenant($tenant, function () use ($owner) {
            $period = app(PayrollPeriodService::class)->create($owner, ['period_start' => '2026-09-01']);

            return app(PayrollRunService::class)->create($owner, $period);
        });
    }

    public function test_calculate_returns_202_and_dispatches_tenant_aware_job(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $this->seedEmployee($tenant, $owner);
        $run = $this->makeRun($tenant, $owner);

        Queue::fake();
        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->postJson("/api/payroll/runs/{$run->getKey()}/calculate")
            ->assertStatus(202)->assertJsonPath('status', 'calculating');

        Queue::assertPushed(PayrollCalculationJob::class, fn (PayrollCalculationJob $job) => $job->tenantContextId() === $tenant->id);
    }

    public function test_entries_summary_and_detail_after_execution(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $emp = $this->seedEmployee($tenant, $owner);
        $run = $this->makeRun($tenant, $owner);

        // Request (faked) then execute the job body deterministically.
        Queue::fake();
        $this->withinTenant($tenant, function () use ($owner, $run) {
            app(PayrollCalculationService::class)->calculate($owner, $run->fresh());
            app(PayrollCalculationService::class)->execute((string) $run->getKey());
        });

        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->getJson("/api/payroll/runs/{$run->getKey()}/entries")
            ->assertOk()->assertJsonPath('data.0.gross_minor', 300000)->assertJsonPath('data.0.status', 'calculated');

        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->getJson("/api/payroll/runs/{$run->getKey()}/summary")
            ->assertOk()
            ->assertJsonPath('by_currency.0.currency', 'USD')
            ->assertJsonPath('by_currency.0.net_minor', 300000)
            ->assertJsonPath('counts.calculated', 1);

        $entryId = $this->withinTenant($tenant, fn () => PayrollEntry::query()->where('employee_id', $emp->getKey())->value('id'));
        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->getJson("/api/payroll/entries/{$entryId}")
            ->assertOk()->assertJsonPath('lines.0.line_code', 'BASE_SALARY');
    }

    public function test_multi_currency_run_summary_groups_per_currency(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $this->seedEmployee($tenant, $owner, 'USD', 300000);
        $this->seedEmployee($tenant, $owner, 'JOD', 600000);
        $run = $this->makeRun($tenant, $owner);

        Queue::fake();
        $this->withinTenant($tenant, function () use ($owner, $run) {
            app(PayrollCalculationService::class)->calculate($owner, $run->fresh());
            app(PayrollCalculationService::class)->execute((string) $run->getKey());
        });

        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->getJson("/api/payroll/runs/{$run->getKey()}/summary")
            ->assertOk()
            ->assertJsonCount(2, 'by_currency')
            ->assertJsonPath('counts.calculated', 2);
    }

    public function test_branch_scoped_grant_gets_scope_safe_404(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $this->seedEmployee($tenant, $owner);
        $run = $this->makeRun($tenant, $owner);
        $branch = $this->makeBranch($tenant);

        $user = $this->makeUser();
        $this->withinTenant($tenant, function () use ($user, $branch) {
            TenantMembership::create(['user_id' => $user->id, 'status' => 'active']);
            app(RoleAssignmentService::class)->assignBySlug($user, 'admin', 'branch', (string) $branch->getKey());
        });

        $this->actingAs($user)->withHeaders($this->tenantHeaders($tenant))
            ->postJson("/api/payroll/runs/{$run->getKey()}/calculate")
            ->assertStatus(404);
        $this->actingAs($user)->withHeaders($this->tenantHeaders($tenant))
            ->getJson("/api/payroll/runs/{$run->getKey()}/entries")
            ->assertStatus(404);
    }

    public function test_accountant_can_calculate_and_view_but_hr_cannot(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $this->seedEmployee($tenant, $owner);
        $run = $this->makeRun($tenant, $owner);

        Queue::fake();
        $accountant = $this->memberWithRole($tenant, 'accountant');
        $this->actingAs($accountant)->withHeaders($this->tenantHeaders($tenant))
            ->postJson("/api/payroll/runs/{$run->getKey()}/calculate")
            ->assertStatus(202);

        $hr = $this->memberWithRole($tenant, 'hr-manager');
        $this->actingAs($hr)->withHeaders($this->tenantHeaders($tenant))
            ->getJson("/api/payroll/runs/{$run->getKey()}/entries")
            ->assertStatus(403);
    }
}
