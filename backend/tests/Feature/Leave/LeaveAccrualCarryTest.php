<?php

namespace Tests\Feature\Leave;

use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Leave\Models\LeaveBalance;
use App\Modules\Leave\Models\LeaveEntitlementPeriod;
use App\Modules\Leave\Services\LeaveAccrualService;
use App\Modules\Leave\Services\LeaveEntitlementPeriodService;
use App\Modules\Leave\Services\LeavePeriodClosureService;
use App\Modules\Leave\Services\LeavePolicyAssignmentService;
use App\Modules\Leave\Services\LeavePolicyService;
use App\Modules\Leave\Services\LeaveTypeService;
use App\Modules\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class LeaveAccrualCarryTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function makeType(Tenant $tenant, array $policyAttrs): array
    {
        return $this->withinTenant($tenant, function () use ($policyAttrs) {
            $employee = app(EmployeeService::class)->create(['first_name' => 'Ac', 'last_name' => 'Crue', 'employment_status' => 'active']);
            $type = app(LeaveTypeService::class)->create(['code' => 'ANN', 'name' => 'Annual']);
            $policy = app(LeavePolicyService::class)->create(array_merge([
                'leave_type_id' => $type->getKey(), 'name' => 'P', 'effective_from' => '2026-01-01',
            ], $policyAttrs));
            app(LeavePolicyAssignmentService::class)->assign($policy, ['scope_type' => 'company', 'effective_from' => '2026-01-01']);

            return [$employee->fresh(), $type->getKey()];
        });
    }

    public function test_monthly_accrual_is_idempotent(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        [$employee, $typeId] = $this->makeType($tenant, [
            'entitlement_method' => 'accrual', 'accrual_frequency' => 'monthly', 'accrual_minutes' => 400,
        ]);

        $this->withinTenant($tenant, function () use ($typeId) {
            $svc = app(LeaveAccrualService::class);
            // As of 2027-03-15 → anchors Jan 1, Feb 1, Mar 1 = 3 * 400 = 1200.
            $svc->processForDate(CarbonImmutable::parse('2027-03-15'));
            $svc->processForDate(CarbonImmutable::parse('2027-03-15')); // re-run

            $balance = LeaveBalance::query()->where('leave_type_id', $typeId)->first();
            $this->assertSame(1200, $balance->accrued_minutes);
            $this->assertSame(1200, $balance->available_minutes);
        });
    }

    public function test_max_balance_caps_accrual(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        [$employee, $typeId] = $this->makeType($tenant, [
            'entitlement_method' => 'accrual', 'accrual_frequency' => 'monthly', 'accrual_minutes' => 400,
            'max_balance_minutes' => 1000,
        ]);

        $this->withinTenant($tenant, function () use ($typeId) {
            app(LeaveAccrualService::class)->processForDate(CarbonImmutable::parse('2027-03-15'));
            $balance = LeaveBalance::query()->where('leave_type_id', $typeId)->first();
            $this->assertSame(1000, $balance->available_minutes); // capped
        });
    }

    public function test_carry_forward_and_expiry_at_period_end(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        [$employee, $typeId] = $this->makeType($tenant, [
            'entitlement_method' => 'fixed', 'entitlement_minutes' => 4800,
            'carry_forward_enabled' => true, 'carry_forward_max_minutes' => 1000,
        ]);

        $this->withinTenant($tenant, function () use ($employee, $typeId) {
            // Grant into the 2027 period.
            app(LeaveAccrualService::class)->processForDate(CarbonImmutable::parse('2027-06-15'));
            $this->assertSame(4800, LeaveBalance::query()->where('leave_type_id', $typeId)->first()->available_minutes);

            // Close periods as of 2028-01-01 → carry 1000, expire 3800.
            app(LeavePeriodClosureService::class)->processForDate(CarbonImmutable::parse('2028-01-01'));

            $closing = LeaveEntitlementPeriod::query()->where('leave_type_id', $typeId)->where('starts_on', '2027-01-01')->first();
            $this->assertSame('closed', $closing->status);

            $next = app(LeaveEntitlementPeriodService::class)
                ->resolveOrCreate($employee, $typeId, null, CarbonImmutable::parse('2028-03-01'));
            $nextBalance = LeaveBalance::query()->where('entitlement_period_id', $next->getKey())->first();
            $this->assertSame(1000, $nextBalance->carried_minutes);
            $this->assertSame(1000, $nextBalance->available_minutes);
        });
    }
}
