<?php

namespace Tests\Feature\Leave;

use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Leave\Models\LeaveBalance;
use App\Modules\Leave\Services\LeaveBalanceService;
use App\Modules\Leave\Services\LeaveEntitlementPeriodService;
use App\Modules\Leave\Services\LeaveTypeService;
use App\Modules\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Proves the immutable ledger + maintained projection: signed buckets, the
 * reservation→usage "deduct exactly once" invariant, reversals, adjustments,
 * accrual idempotency, and projection == SUM(ledger).
 */
class LeaveBalanceLedgerTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function makeContext(Tenant $tenant): array
    {
        return $this->withinTenant($tenant, function () {
            $employee = app(EmployeeService::class)->create(['first_name' => 'Led', 'last_name' => 'Ger']);
            $type = app(LeaveTypeService::class)->create(['code' => 'ANN', 'name' => 'Annual']);
            $period = app(LeaveEntitlementPeriodService::class)
                ->resolveOrCreate($employee, $type->getKey(), null, CarbonImmutable::parse('2027-06-15'));

            return [$employee, $type, $period];
        });
    }

    public function test_grant_reserve_release_usage_reversal_and_available(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        [, , $period] = $this->makeContext($tenant);
        $svc = app(LeaveBalanceService::class);

        $this->withinTenant($tenant, function () use ($svc, $period) {
            // Grant 10 days @ 480.
            DB::transaction(fn () => $svc->withLockedBalance($period, fn ($b) => $svc->grant($b, 4800)));
            $this->assertSame(4800, LeaveBalance::query()->first()->available_minutes);

            // Reserve 480 → availability drops immediately.
            DB::transaction(fn () => $svc->withLockedBalance($period, fn ($b) => $svc->reserve($b, 480)));
            $b = LeaveBalance::query()->first();
            $this->assertSame(480, $b->reserved_minutes);
            $this->assertSame(4320, $b->available_minutes);

            // Final approval: release reservation + usage in one step. Net once.
            DB::transaction(fn () => $svc->withLockedBalance($period, function ($b) use ($svc) {
                $svc->releaseReservation($b, 480);
                $svc->consume($b, 480);
            }));
            $b = LeaveBalance::query()->first();
            $this->assertSame(0, $b->reserved_minutes);
            $this->assertSame(480, $b->used_minutes);
            $this->assertSame(4320, $b->available_minutes); // still only −480 from grant

            // Cancellation: reverse usage exactly once.
            DB::transaction(fn () => $svc->withLockedBalance($period, fn ($b) => $svc->reverseUsage($b, 480)));
            $b = LeaveBalance::query()->first();
            $this->assertSame(0, $b->used_minutes);
            $this->assertSame(4800, $b->available_minutes);

            // Negative adjustment reduces availability.
            DB::transaction(fn () => $svc->withLockedBalance($period, fn ($b) => $svc->adjust($b, -600, ['reason' => 'correction'])));
            $b = LeaveBalance::query()->first();
            $this->assertSame(-600, $b->adjusted_minutes);
            $this->assertSame(4200, $b->available_minutes);
        });
    }

    public function test_projection_equals_ledger_sum(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        [, , $period] = $this->makeContext($tenant);
        $svc = app(LeaveBalanceService::class);

        $this->withinTenant($tenant, function () use ($svc, $period) {
            DB::transaction(fn () => $svc->withLockedBalance($period, function ($b) use ($svc) {
                $svc->grant($b, 4800);
                $svc->reserve($b, 960);
                $svc->releaseReservation($b, 480);
                $svc->consume($b, 480);
            }));

            $before = LeaveBalance::query()->first()->only([
                'granted_minutes', 'used_minutes', 'reserved_minutes', 'available_minutes',
            ]);

            $rebuilt = $svc->rebuildForPeriod($period->fresh());

            $this->assertSame($before['granted_minutes'], $rebuilt->granted_minutes);
            $this->assertSame($before['used_minutes'], $rebuilt->used_minutes);
            $this->assertSame($before['reserved_minutes'], $rebuilt->reserved_minutes);
            $this->assertSame($before['available_minutes'], $rebuilt->available_minutes);
        });
    }

    public function test_accrual_idempotency_key_prevents_double_count(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        [, , $period] = $this->makeContext($tenant);
        $svc = app(LeaveBalanceService::class);

        $this->withinTenant($tenant, function () use ($svc, $period) {
            $key = 'accrual:'.$period->getKey().':2027-06';
            DB::transaction(fn () => $svc->withLockedBalance($period, fn ($b) => $svc->accrue($b, 400, ['idempotency_key' => $key])));
            DB::transaction(fn () => $svc->withLockedBalance($period, fn ($b) => $svc->accrue($b, 400, ['idempotency_key' => $key])));

            $b = LeaveBalance::query()->first();
            $this->assertSame(400, $b->accrued_minutes); // second run was a no-op
            $this->assertSame(400, $b->available_minutes);
        });
    }
}
