<?php

namespace Tests\Feature\Payroll;

use App\Modules\Authorization\Services\RoleAssignmentService;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Identity\Models\TenantMembership;
use App\Modules\Identity\Models\User;
use App\Modules\Payroll\Models\EmployeeCompensation;
use App\Modules\Payroll\Services\EmployeeCompensationService;
use App\Modules\Payroll\Services\PayrollSettingsService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class PayrollCompensationTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function employee(Tenant $tenant): Employee
    {
        return $this->withinTenant($tenant, fn () => app(EmployeeService::class)
            ->create(['first_name' => 'E', 'last_name' => 'E', 'employment_status' => 'active']));
    }

    /** A user linked to a fresh employee, granted a role at the given scope. */
    private function linked(Tenant $tenant, string $role = 'admin', string $scopeType = 'company', ?string $scopeId = null): array
    {
        return $this->withinTenant($tenant, function () use ($role, $scopeType, $scopeId) {
            $u = User::factory()->create();
            TenantMembership::create(['user_id' => $u->id, 'status' => 'active']);
            $e = app(EmployeeService::class)->create(['first_name' => 'M', 'last_name' => 'M', 'employment_status' => 'active']);
            $e->fill(['user_id' => $u->id])->save();
            app(RoleAssignmentService::class)->assignBySlug($u, $role, $scopeType, $scopeId);

            return [$u, $e->fresh()];
        });
    }

    public function test_create_normalizes_currency_and_stores_integer_minor(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $emp = $this->employee($tenant);

        $this->withinTenant($tenant, function () use ($owner, $emp) {
            $row = app(EmployeeCompensationService::class)->create($owner, (string) $emp->getKey(), [
                'currency' => 'usd', 'base_amount_minor' => 400000, 'effective_from' => '2026-01-01',
            ]);
            $this->assertSame('USD', $row->currency);
            $this->assertSame(400000, $row->base_amount_minor);
            $this->assertNull($row->effective_to);
        });
    }

    public function test_effective_dated_history_is_preserved(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $emp = $this->employee($tenant);

        $this->withinTenant($tenant, function () use ($owner, $emp) {
            $svc = app(EmployeeCompensationService::class);
            $first = $svc->create($owner, (string) $emp->getKey(), ['currency' => 'USD', 'base_amount_minor' => 400000, 'effective_from' => '2026-01-01', 'effective_to' => '2026-06-30']);
            $svc->create($owner, (string) $emp->getKey(), ['currency' => 'USD', 'base_amount_minor' => 450000, 'effective_from' => '2026-07-01']);

            $history = $svc->history((string) $emp->getKey());
            $this->assertCount(2, $history);
            // Old row is unchanged (never rewritten).
            $this->assertSame(400000, $history->first()->base_amount_minor);
            $this->assertSame(450000, $history->last()->base_amount_minor);
            $this->assertSame($first->getKey(), $history->first()->getKey());
        });
    }

    public function test_overlapping_range_is_rejected_but_adjacent_is_allowed(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $emp = $this->employee($tenant);

        $this->withinTenant($tenant, function () use ($owner, $emp) {
            $svc = app(EmployeeCompensationService::class);
            $svc->create($owner, (string) $emp->getKey(), ['currency' => 'USD', 'base_amount_minor' => 400000, 'effective_from' => '2026-01-01', 'effective_to' => '2026-06-30']);

            // Adjacent (starts the day after) is allowed.
            $svc->create($owner, (string) $emp->getKey(), ['currency' => 'USD', 'base_amount_minor' => 450000, 'effective_from' => '2026-07-01']);

            // Overlapping the open-ended tail is rejected.
            try {
                $svc->create($owner, (string) $emp->getKey(), ['currency' => 'USD', 'base_amount_minor' => 500000, 'effective_from' => '2026-08-01']);
                $this->fail('overlap should be rejected');
            } catch (ValidationException) {
            }

            // Overlapping the first closed range is rejected too.
            try {
                $svc->create($owner, (string) $emp->getKey(), ['currency' => 'USD', 'base_amount_minor' => 500000, 'effective_from' => '2026-03-01', 'effective_to' => '2026-04-01']);
                $this->fail('overlap should be rejected');
            } catch (ValidationException) {
            }

            $this->assertSame(2, EmployeeCompensation::query()->where('employee_id', $emp->getKey())->count());
        });
    }

    public function test_db_trigger_backstops_overlap_when_service_check_bypassed(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $emp = $this->employee($tenant);

        $this->withinTenant($tenant, function () use ($owner, $emp) {
            app(EmployeeCompensationService::class)->create($owner, (string) $emp->getKey(), [
                'currency' => 'USD', 'base_amount_minor' => 400000, 'effective_from' => '2026-01-01',
            ]);

            // Insert an overlapping row directly (bypassing the service check). The
            // DB trigger must reject it. Wrapped in a savepoint so the outer test
            // transaction recovers.
            $this->expectException(QueryException::class);
            DB::transaction(function () use ($emp) {
                EmployeeCompensation::query()->create([
                    'employee_id' => $emp->getKey(), 'currency' => 'USD',
                    'base_amount_minor' => 999999, 'effective_from' => '2026-05-01', 'version' => 1,
                ]);
            });
        });
    }

    public function test_different_employees_are_independent(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $a = $this->employee($tenant);
        $b = $this->employee($tenant);

        $this->withinTenant($tenant, function () use ($owner, $a, $b) {
            $svc = app(EmployeeCompensationService::class);
            $svc->create($owner, (string) $a->getKey(), ['currency' => 'USD', 'base_amount_minor' => 400000, 'effective_from' => '2026-01-01']);
            // Same date range for a different employee is fine.
            $svc->create($owner, (string) $b->getKey(), ['currency' => 'USD', 'base_amount_minor' => 300000, 'effective_from' => '2026-01-01']);
            $this->assertSame(1, EmployeeCompensation::query()->where('employee_id', $a->getKey())->count());
            $this->assertSame(1, EmployeeCompensation::query()->where('employee_id', $b->getKey())->count());
        });
    }

    public function test_self_payroll_is_denied_by_default_and_allowed_when_enabled(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        [$adminUser, $adminEmp] = $this->linked($tenant, 'admin', 'company', null);

        // Default: an admin cannot manage their own compensation.
        $this->actingAs($adminUser)->withHeaders($this->tenantHeaders($tenant))
            ->postJson("/api/payroll/compensations/{$adminEmp->getKey()}", ['currency' => 'USD', 'base_amount_minor' => 500000, 'effective_from' => '2026-01-01'])
            ->assertStatus(403);

        // Enable self-payroll → now allowed.
        $this->withinTenant($tenant, fn () => app(PayrollSettingsService::class)->update($adminUser, ['allow_self_payroll' => true]));

        $this->actingAs($adminUser)->withHeaders($this->tenantHeaders($tenant))
            ->postJson("/api/payroll/compensations/{$adminEmp->getKey()}", ['currency' => 'USD', 'base_amount_minor' => 500000, 'effective_from' => '2026-01-01'])
            ->assertStatus(201);
    }

    public function test_admin_may_manage_another_employees_compensation(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $emp = $this->employee($tenant);

        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->postJson("/api/payroll/compensations/{$emp->getKey()}", ['currency' => 'USD', 'base_amount_minor' => 500000, 'effective_from' => '2026-01-01'])
            ->assertStatus(201)->assertJsonPath('base_amount_minor', 500000);
    }
}
