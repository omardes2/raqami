<?php

namespace Tests\Feature\Payroll;

use App\Modules\Attendance\Services\WorkScheduleService;
use App\Modules\Authorization\Services\RoleAssignmentService;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Identity\Models\TenantMembership;
use App\Modules\Payroll\Models\PayrollAdjustment;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Services\EmployeeCompensationService;
use App\Modules\Payroll\Services\PayrollCalculationService;
use App\Modules\Payroll\Services\PayrollPeriodService;
use App\Modules\Payroll\Services\PayrollRunService;
use App\Modules\Payroll\Services\PayrollSettingsService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * HTTP wire-up + gating for the Phase-2B period-owned adjustment endpoints and
 * four-eyes approval. Finalization is exercised at the service level (top-level tx).
 */
class PayrollWorkflowApiTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private ?PayrollPeriod $period = null;

    private function seedEmployee(Tenant $tenant, $owner): Employee
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

    private function calculatedRun(Tenant $tenant, $owner, bool $fourEyes = false): PayrollRun
    {
        return $this->withinTenant($tenant, function () use ($owner, $fourEyes) {
            if ($fourEyes) {
                app(PayrollSettingsService::class)->update($owner, ['require_four_eyes' => true]);
            }
            $this->period = app(PayrollPeriodService::class)->create($owner, ['period_start' => '2026-09-01']);
            $run = app(PayrollRunService::class)->create($owner, $this->period);
            Queue::fake();
            app(PayrollCalculationService::class)->calculate($owner, $run->fresh());
            app(PayrollCalculationService::class)->execute((string) $run->getKey());

            return $run->fresh();
        });
    }

    private function payload(Employee $emp, array $over = []): array
    {
        return array_merge([
            'employee_id' => (string) $emp->getKey(), 'employee_visible_label' => 'Bonus',
            'direction' => 'earning', 'amount_minor' => 5000, 'currency' => 'USD', 'internal_reason' => 'Spot bonus',
        ], $over);
    }

    public function test_adjustment_requires_a_reason(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $emp = $this->seedEmployee($tenant, $owner);
        $this->calculatedRun($tenant, $owner);

        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->postJson("/api/payroll/periods/{$this->period->getKey()}/adjustments", $this->payload($emp, ['internal_reason' => '']))
            ->assertStatus(422)->assertJsonValidationErrors('internal_reason');
    }

    public function test_adjustment_create_list_patch_delete_roundtrip(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $emp = $this->seedEmployee($tenant, $owner);
        $this->calculatedRun($tenant, $owner);
        $periodId = (string) $this->period->getKey();

        $created = $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->postJson("/api/payroll/periods/{$periodId}/adjustments", $this->payload($emp))
            ->assertStatus(201)->assertJsonPath('amount_minor', 5000)->assertJsonPath('payroll_period_id', $periodId)->json();

        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->getJson("/api/payroll/periods/{$periodId}/adjustments")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.internal_reason', 'Spot bonus');

        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->patchJson("/api/payroll/adjustments/{$created['id']}", ['amount_minor' => 7000])
            ->assertOk()->assertJsonPath('amount_minor', 7000)->assertJsonPath('version', 2);

        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->deleteJson("/api/payroll/adjustments/{$created['id']}")
            ->assertNoContent();
        $this->withinTenant($tenant, fn () => $this->assertSame(0, PayrollAdjustment::query()->count()));
    }

    public function test_approve_endpoint_transitions_run_under_four_eyes(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $this->seedEmployee($tenant, $owner);
        $run = $this->calculatedRun($tenant, $owner, fourEyes: true);
        $approver = $this->memberWithRole($tenant, 'admin');

        $this->actingAs($approver)->withHeaders($this->tenantHeaders($tenant))
            ->postJson("/api/payroll/runs/{$run->getKey()}/approve")
            ->assertOk()->assertJsonPath('status', 'approved');
    }

    public function test_accountant_may_adjust_but_not_approve(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $emp = $this->seedEmployee($tenant, $owner);
        $run = $this->calculatedRun($tenant, $owner);
        $accountant = $this->memberWithRole($tenant, 'accountant');

        $this->actingAs($accountant)->withHeaders($this->tenantHeaders($tenant))
            ->postJson("/api/payroll/periods/{$this->period->getKey()}/adjustments", $this->payload($emp))
            ->assertStatus(201);

        $this->actingAs($accountant)->withHeaders($this->tenantHeaders($tenant))
            ->postJson("/api/payroll/runs/{$run->getKey()}/approve")
            ->assertStatus(403);
    }

    public function test_branch_scoped_grant_gets_scope_safe_404_on_finalize(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $this->seedEmployee($tenant, $owner);
        $run = $this->calculatedRun($tenant, $owner);
        $branch = $this->makeBranch($tenant);

        $user = $this->makeUser();
        $this->withinTenant($tenant, function () use ($user, $branch) {
            TenantMembership::create(['user_id' => $user->id, 'status' => 'active']);
            app(RoleAssignmentService::class)->assignBySlug($user, 'admin', 'branch', (string) $branch->getKey());
        });

        $this->actingAs($user)->withHeaders($this->tenantHeaders($tenant))
            ->postJson("/api/payroll/runs/{$run->getKey()}/finalize")
            ->assertStatus(404);
    }
}
