<?php

namespace Tests\Feature\Payroll;

use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Payroll\Enums\PayrollComponentMode;
use App\Modules\Payroll\Enums\PayrollComponentType;
use App\Modules\Payroll\Models\EmployeeCompensationComponent;
use App\Modules\Payroll\Models\PayrollComponent;
use App\Modules\Payroll\Services\EmployeeCompensationComponentService;
use App\Modules\Payroll\Services\PayrollComponentService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * The tenant component catalog and effective-dated employee assignments. Value
 * shape is governed by the catalog component's calculation_mode; type/mode are
 * immutable; deactivation blocks new assignments but never rewrites existing ones.
 */
class PayrollComponentTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function employee(Tenant $tenant): Employee
    {
        return $this->withinTenant($tenant, fn () => app(EmployeeService::class)
            ->create(['first_name' => 'C', 'last_name' => 'C', 'employment_status' => 'active']));
    }

    public function test_create_earning_fixed_and_deduction_percent(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () use ($owner) {
            $svc = app(PayrollComponentService::class);
            $earning = $svc->create($owner, ['code' => 'HOUSING', 'name' => 'Housing', 'type' => 'earning', 'calculation_mode' => 'fixed']);
            $deduction = $svc->create($owner, ['code' => 'GOSI', 'name' => 'Social Insurance', 'type' => 'deduction', 'calculation_mode' => 'percent_of_base']);

            $this->assertSame(PayrollComponentType::Earning, $earning->type);
            $this->assertSame(PayrollComponentMode::Fixed, $earning->calculation_mode);
            $this->assertSame(PayrollComponentType::Deduction, $deduction->type);
            $this->assertSame(PayrollComponentMode::PercentOfBase, $deduction->calculation_mode);
            $this->assertTrue((bool) $earning->active);
        });
    }

    public function test_type_and_mode_are_immutable_on_update(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () use ($owner) {
            $svc = app(PayrollComponentService::class);
            $c = $svc->create($owner, ['code' => 'BONUS', 'name' => 'Bonus', 'type' => 'earning', 'calculation_mode' => 'fixed']);

            // Only name/sort_order/active are mutable; type/mode are ignored.
            $updated = $svc->update($owner, $c, ['name' => 'Annual Bonus', 'type' => 'deduction', 'calculation_mode' => 'percent_of_base', 'active' => false]);

            $this->assertSame('Annual Bonus', $updated->name);
            $this->assertFalse((bool) $updated->active);
            $this->assertSame(PayrollComponentType::Earning, $updated->type);
            $this->assertSame(PayrollComponentMode::Fixed, $updated->calculation_mode);
        });
    }

    public function test_fixed_component_requires_amount_and_currency(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $emp = $this->employee($tenant);

        $this->withinTenant($tenant, function () use ($owner, $emp) {
            $component = app(PayrollComponentService::class)->create($owner, ['code' => 'HOUSING', 'name' => 'Housing', 'type' => 'earning', 'calculation_mode' => 'fixed']);
            $svc = app(EmployeeCompensationComponentService::class);

            // Missing amount/currency → rejected.
            try {
                $svc->assign($owner, (string) $emp->getKey(), ['payroll_component_id' => (string) $component->getKey(), 'rate_bps' => 500, 'effective_from' => '2026-01-01']);
                $this->fail('fixed component without amount/currency should be rejected');
            } catch (ValidationException) {
            }

            // Proper fixed shape stores amount + normalized currency, no bps.
            $row = $svc->assign($owner, (string) $emp->getKey(), ['payroll_component_id' => (string) $component->getKey(), 'fixed_amount_minor' => 50000, 'currency' => 'usd', 'effective_from' => '2026-01-01']);
            $this->assertSame(50000, $row->fixed_amount_minor);
            $this->assertSame('USD', $row->currency);
            $this->assertNull($row->rate_bps);
        });
    }

    public function test_percent_component_requires_positive_bps_and_stores_no_currency(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $emp = $this->employee($tenant);

        $this->withinTenant($tenant, function () use ($owner, $emp) {
            $component = app(PayrollComponentService::class)->create($owner, ['code' => 'GOSI', 'name' => 'GOSI', 'type' => 'deduction', 'calculation_mode' => 'percent_of_base']);
            $svc = app(EmployeeCompensationComponentService::class);

            try {
                $svc->assign($owner, (string) $emp->getKey(), ['payroll_component_id' => (string) $component->getKey(), 'rate_bps' => 0, 'effective_from' => '2026-01-01']);
                $this->fail('percent component with non-positive bps should be rejected');
            } catch (ValidationException) {
            }

            // 9.5% == 950 basis points; currency is meaningless for a percentage.
            $row = $svc->assign($owner, (string) $emp->getKey(), ['payroll_component_id' => (string) $component->getKey(), 'rate_bps' => 950, 'currency' => 'USD', 'effective_from' => '2026-01-01']);
            $this->assertSame(950, $row->rate_bps);
            $this->assertNull($row->fixed_amount_minor);
            $this->assertNull($row->currency);
        });
    }

    public function test_inactive_component_cannot_be_newly_assigned_but_existing_is_preserved(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $emp = $this->employee($tenant);

        $this->withinTenant($tenant, function () use ($owner, $emp) {
            $components = app(PayrollComponentService::class);
            $assignments = app(EmployeeCompensationComponentService::class);

            $component = $components->create($owner, ['code' => 'TRANSPORT', 'name' => 'Transport', 'type' => 'earning', 'calculation_mode' => 'fixed']);
            $existing = $assignments->assign($owner, (string) $emp->getKey(), ['payroll_component_id' => (string) $component->getKey(), 'fixed_amount_minor' => 20000, 'currency' => 'USD', 'effective_from' => '2026-01-01', 'effective_to' => '2026-06-30']);

            // Deactivate the catalog component.
            $components->update($owner, $component, ['active' => false]);

            // The existing assignment is untouched.
            $this->assertNotNull($existing->fresh());
            $this->assertSame(20000, $existing->fresh()->fixed_amount_minor);

            // A NEW assignment of the inactive component is rejected.
            try {
                $assignments->assign($owner, (string) $emp->getKey(), ['payroll_component_id' => (string) $component->getKey(), 'fixed_amount_minor' => 20000, 'currency' => 'USD', 'effective_from' => '2026-07-01']);
                $this->fail('assigning an inactive component should be rejected');
            } catch (ValidationException) {
            }
        });
    }

    public function test_overlap_is_per_component_and_distinct_components_are_independent(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $emp = $this->employee($tenant);

        $this->withinTenant($tenant, function () use ($owner, $emp) {
            $components = app(PayrollComponentService::class);
            $assignments = app(EmployeeCompensationComponentService::class);

            $housing = $components->create($owner, ['code' => 'HOUSING', 'name' => 'Housing', 'type' => 'earning', 'calculation_mode' => 'fixed']);
            $transport = $components->create($owner, ['code' => 'TRANSPORT', 'name' => 'Transport', 'type' => 'earning', 'calculation_mode' => 'fixed']);

            $assignments->assign($owner, (string) $emp->getKey(), ['payroll_component_id' => (string) $housing->getKey(), 'fixed_amount_minor' => 50000, 'currency' => 'USD', 'effective_from' => '2026-01-01']);

            // Same window, DIFFERENT component → allowed.
            $assignments->assign($owner, (string) $emp->getKey(), ['payroll_component_id' => (string) $transport->getKey(), 'fixed_amount_minor' => 20000, 'currency' => 'USD', 'effective_from' => '2026-01-01']);

            // Same component, overlapping the open-ended tail → rejected.
            try {
                $assignments->assign($owner, (string) $emp->getKey(), ['payroll_component_id' => (string) $housing->getKey(), 'fixed_amount_minor' => 60000, 'currency' => 'USD', 'effective_from' => '2026-03-01']);
                $this->fail('overlapping the same component should be rejected');
            } catch (ValidationException) {
            }

            $this->assertSame(2, EmployeeCompensationComponent::query()->where('employee_id', $emp->getKey())->count());
        });
    }

    public function test_unique_code_per_tenant_is_enforced(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () use ($owner) {
            $svc = app(PayrollComponentService::class);
            $svc->create($owner, ['code' => 'HOUSING', 'name' => 'Housing', 'type' => 'earning', 'calculation_mode' => 'fixed']);

            $this->expectException(QueryException::class);
            DB::transaction(fn () => $svc->create($owner, ['code' => 'HOUSING', 'name' => 'Dup', 'type' => 'earning', 'calculation_mode' => 'fixed']));
        });
    }

    public function test_component_catalog_is_tenant_isolated(): void
    {
        [$ownerA, $tenantA] = $this->createCompanyWithOwner();
        [, $tenantB] = $this->createCompanyWithOwner();

        $this->withinTenant($tenantA, fn () => app(PayrollComponentService::class)
            ->create($ownerA, ['code' => 'HOUSING', 'name' => 'Housing', 'type' => 'earning', 'calculation_mode' => 'fixed']));

        // Tenant B never sees tenant A's catalog. Same code is free to reuse.
        $this->withinTenant($tenantB, function () {
            $this->assertSame(0, PayrollComponent::query()->count());
        });
    }
}
