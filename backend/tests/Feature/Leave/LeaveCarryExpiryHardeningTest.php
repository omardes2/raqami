<?php

namespace Tests\Feature\Leave;

use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Leave\Http\Resources\LeavePolicyResource;
use App\Modules\Leave\Models\LeaveBalance;
use App\Modules\Leave\Models\LeaveBalanceTransaction;
use App\Modules\Leave\Models\LeaveEntitlementPeriod;
use App\Modules\Leave\Models\LeavePolicy;
use App\Modules\Leave\Services\LeaveBalanceService;
use App\Modules\Leave\Services\LeaveEntitlementPeriodService;
use App\Modules\Leave\Services\LeavePeriodClosureService;
use App\Modules\Leave\Services\LeavePolicyAssignmentService;
use App\Modules\Leave\Services\LeavePolicyService;
use App\Modules\Leave\Services\LeaveTypeService;
use App\Modules\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Period close: the carry maximum is respected, the non-carried excess expires at
 * period end, and re-running the closure never duplicates a carry or an expiry
 * (stable ledger keys + closed-period skip). Also proves the reserved
 * `carry_forward_expiry_days` column is not accepted by the policy service nor
 * returned by its resource.
 */
class LeaveCarryExpiryHardeningTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    /** @return array{0:Employee,1:string,2:LeavePolicy} */
    private function seedCarry(Tenant $tenant, array $policyOverrides = []): array
    {
        return $this->withinTenant($tenant, function () use ($policyOverrides) {
            $employee = app(EmployeeService::class)->create(['first_name' => 'Ca', 'last_name' => 'Rry', 'employment_status' => 'active']);
            $type = app(LeaveTypeService::class)->create(['code' => 'ANN', 'name' => 'Annual']);
            $policy = app(LeavePolicyService::class)->create(array_merge([
                'leave_type_id' => $type->getKey(), 'name' => 'P', 'effective_from' => '2026-01-01',
                'entitlement_method' => 'none', 'approval_flow' => 'none', 'period_basis' => 'calendar_year',
                'carry_forward_enabled' => true, 'carry_forward_max_minutes' => 600,
            ], $policyOverrides));
            app(LeavePolicyAssignmentService::class)->assign($policy, ['scope_type' => 'company', 'effective_from' => '2026-01-01']);

            // 2026 period, grant 1000, no usage → available 1000.
            $period = app(LeaveEntitlementPeriodService::class)->resolveOrCreate($employee->fresh(), $type->getKey(), $policy, CarbonImmutable::parse('2026-06-15'));
            $svc = app(LeaveBalanceService::class);
            DB::transaction(fn () => $svc->withLockedBalance($period, fn ($b) => $svc->grant($b, 1000)));

            return [$employee->fresh(), $type->getKey(), $policy];
        });
    }

    public function test_carry_max_and_excess_expiry_are_idempotent(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        [$employee, $typeId] = $this->seedCarry($tenant);

        $this->withinTenant($tenant, function () {
            $closure = app(LeavePeriodClosureService::class);

            // Close the 2026 period from an early-2027 processing date.
            $counts = $closure->processForDate(CarbonImmutable::parse('2027-01-05'));
            $this->assertSame(1, $counts['closed']);
            $this->assertSame(600, $counts['carried']); // capped at the max
            $this->assertSame(400, $counts['expired']); // 1000 − 600 non-carried excess

            $old = LeaveEntitlementPeriod::query()->where('starts_on', '2026-01-01')->first();
            $this->assertSame('closed', $old->status);
            $this->assertSame(400, LeaveBalance::query()->where('entitlement_period_id', $old->getKey())->first()->expired_minutes);

            // The carried 600 lands in the 2027 period.
            $next = LeaveEntitlementPeriod::query()->where('starts_on', '2027-01-01')->first();
            $this->assertNotNull($next);
            $nextBalance = LeaveBalance::query()->where('entitlement_period_id', $next->getKey())->first();
            $this->assertSame(600, $nextBalance->carried_minutes);
            $this->assertSame(600, $nextBalance->available_minutes);

            $carryCount = fn () => LeaveBalanceTransaction::query()->where('transaction_type', 'carry_forward')->count();
            $expiryCount = fn () => LeaveBalanceTransaction::query()->where('transaction_type', 'expiry')->count();
            $this->assertSame(1, $carryCount());
            $this->assertSame(1, $expiryCount());

            // Re-run: the 2026 period is closed (skipped); nothing duplicates.
            $again = $closure->processForDate(CarbonImmutable::parse('2027-01-05'));
            $this->assertSame(0, $again['closed']);
            $this->assertSame(1, $carryCount());
            $this->assertSame(1, $expiryCount());
            $this->assertSame(600, LeaveBalance::query()->where('entitlement_period_id', $next->getKey())->first()->carried_minutes);
            $this->assertSame(400, LeaveBalance::query()->where('entitlement_period_id', $old->getKey())->first()->expired_minutes);
        });
    }

    public function test_unlimited_carry_moves_full_balance_no_expiry(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        // carry_forward_max_minutes null → carry everything, expire nothing.
        [$employee, $typeId] = $this->seedCarry($tenant, ['carry_forward_max_minutes' => null]);

        $this->withinTenant($tenant, function () {
            $counts = app(LeavePeriodClosureService::class)->processForDate(CarbonImmutable::parse('2027-01-05'));
            $this->assertSame(1000, $counts['carried']);
            $this->assertSame(0, $counts['expired']);
        });
    }

    public function test_carry_disabled_expires_entire_balance(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        [$employee, $typeId] = $this->seedCarry($tenant, ['carry_forward_enabled' => false]);

        $this->withinTenant($tenant, function () {
            $counts = app(LeavePeriodClosureService::class)->processForDate(CarbonImmutable::parse('2027-01-05'));
            $this->assertSame(0, $counts['carried']);
            $this->assertSame(1000, $counts['expired']);
        });
    }

    public function test_carry_forward_expiry_days_is_not_accepted_or_returned(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () {
            $type = app(LeaveTypeService::class)->create(['code' => 'ANN', 'name' => 'Annual']);
            // Attempt to set the reserved field via the service — it must be ignored.
            $policy = app(LeavePolicyService::class)->create([
                'leave_type_id' => $type->getKey(), 'name' => 'P', 'effective_from' => '2026-01-01',
                'entitlement_method' => 'none', 'approval_flow' => 'none',
                'carry_forward_expiry_days' => 30,
            ]);

            $this->assertNull($policy->fresh()->carry_forward_expiry_days); // not writable

            $resource = (new LeavePolicyResource($policy->fresh()))
                ->toArray(request());
            $this->assertArrayNotHasKey('carry_forward_expiry_days', $resource); // not exposed
        });
    }
}
