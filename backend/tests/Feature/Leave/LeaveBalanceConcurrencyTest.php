<?php

namespace Tests\Feature\Leave;

use App\Modules\Attendance\Services\WorkScheduleService;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Identity\Models\User;
use App\Modules\Leave\Models\LeaveBalance;
use App\Modules\Leave\Models\LeaveEntitlementPeriod;
use App\Modules\Leave\Services\LeaveBalanceService;
use App\Modules\Leave\Services\LeaveEntitlementPeriodService;
use App\Modules\Leave\Services\LeavePolicyAssignmentService;
use App\Modules\Leave\Services\LeavePolicyService;
use App\Modules\Leave\Services\LeaveRequestService;
use App\Modules\Leave\Services\LeaveTypeService;
use App\Modules\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Balance-guard + entitlement-period invariants that protect against concurrent
 * over-booking. True OS-level parallelism cannot be exercised under
 * RefreshDatabase (all work shares one wrapping transaction/connection): the
 * production guarantee is the LeaveLock transaction-scoped advisory lock plus the
 * row lock in withLockedBalance, which serialize concurrent balance writers so
 * the SECOND writer observes the first's reservation. These tests assert the
 * serialized outcome and the create-or-fetch idempotency of period resolution.
 */
class LeaveBalanceConcurrencyTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    /** @return array{0:Employee,1:User,2:string} */
    private function seedOneSlot(Tenant $tenant, int $grant = 480, array $policyOverrides = []): array
    {
        return $this->withinTenant($tenant, function () use ($grant, $policyOverrides) {
            $empUser = User::factory()->create();
            $employee = app(EmployeeService::class)->create(['first_name' => 'Sl', 'last_name' => 'Ot', 'employment_status' => 'active']);
            $employee->fill(['user_id' => $empUser->id])->save();
            $days = [];
            for ($w = 0; $w <= 6; $w++) {
                $days[] = ['weekday' => $w, 'is_working_day' => true, 'segments' => [['start_time' => '08:00', 'end_time' => '16:00']]];
            }
            $schedule = app(WorkScheduleService::class)->create(['name' => 'S', 'code' => 'S', 'timezone' => 'UTC'], $days);
            app(WorkScheduleService::class)->assign($schedule, ['scope_type' => 'company', 'effective_from' => '2026-01-01']);

            $type = app(LeaveTypeService::class)->create(['code' => 'ANN', 'name' => 'Annual']);
            $policy = app(LeavePolicyService::class)->create(array_merge([
                'leave_type_id' => $type->getKey(), 'name' => 'P', 'effective_from' => '2026-01-01',
                'entitlement_method' => 'none', 'approval_flow' => 'none',
            ], $policyOverrides));
            app(LeavePolicyAssignmentService::class)->assign($policy, ['scope_type' => 'company', 'effective_from' => '2026-01-01']);

            $period = app(LeaveEntitlementPeriodService::class)->resolveOrCreate($employee->fresh(), $type->getKey(), $policy, CarbonImmutable::parse('2027-06-15'));
            $svc = app(LeaveBalanceService::class);
            DB::transaction(fn () => $svc->withLockedBalance($period, fn ($b) => $svc->grant($b, $grant)));

            return [$employee->fresh(), $empUser, $type->getKey()];
        });
    }

    public function test_two_full_day_reservations_against_a_single_slot_only_one_succeeds(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        [$employee, $empUser, $typeId] = $this->seedOneSlot($tenant, 480);

        $this->withinTenant($tenant, function () use ($employee, $empUser, $typeId) {
            $s = app(LeaveRequestService::class);

            // First 480-minute request takes the whole balance (auto-approved).
            $s->submit($employee, ['leave_type_id' => $typeId, 'starts_on' => '2027-06-15', 'ends_on' => '2027-06-15'], $empUser);
            $this->assertSame(0, LeaveBalance::query()->first()->available_minutes);

            // A second, non-overlapping 480-minute request cannot be reserved: the
            // (row+advisory)-locked guard sees availability already at zero.
            try {
                $s->submit($employee, ['leave_type_id' => $typeId, 'starts_on' => '2027-06-16', 'ends_on' => '2027-06-16'], $empUser);
                $this->fail('expected the second reservation to exhaust the balance');
            } catch (ValidationException) {
                // expected — insufficient balance
            }

            $b = LeaveBalance::query()->first();
            $this->assertSame(480, $b->used_minutes); // only ONE request consumed the slot
            $this->assertSame(0, $b->available_minutes);
        });
    }

    public function test_negative_override_permits_the_second_reservation(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        [$employee, $empUser, $typeId] = $this->seedOneSlot($tenant, 480);

        $this->withinTenant($tenant, function () use ($employee, $empUser, $owner, $typeId) {
            $s = app(LeaveRequestService::class);
            $s->submit($employee, ['leave_type_id' => $typeId, 'starts_on' => '2027-06-15', 'ends_on' => '2027-06-15'], $empUser);

            // With the D6 override, an authorized actor may push the balance negative.
            $s->submit($employee, ['leave_type_id' => $typeId, 'starts_on' => '2027-06-16', 'ends_on' => '2027-06-16'], $owner, allowNegativeOverride: true);

            $b = LeaveBalance::query()->first();
            $this->assertSame(960, $b->used_minutes);
            $this->assertSame(-480, $b->available_minutes); // overridden into deficit
        });
    }

    public function test_resolve_or_create_is_idempotent_for_the_same_window(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        [$employee, $empUser, $typeId] = $this->seedOneSlot($tenant, 480);

        $this->withinTenant($tenant, function () use ($employee, $typeId) {
            $periods = app(LeaveEntitlementPeriodService::class);
            // Two different dates inside the SAME calendar-year window must resolve
            // to one row — the create-or-fetch path never duplicates the period.
            $a = $periods->resolveOrCreate($employee, $typeId, null, CarbonImmutable::parse('2027-02-01'));
            $b = $periods->resolveOrCreate($employee, $typeId, null, CarbonImmutable::parse('2027-11-30'));

            $this->assertSame((string) $a->getKey(), (string) $b->getKey());
            $this->assertSame(1, LeaveEntitlementPeriod::query()
                ->where('employee_id', $employee->getKey())
                ->where('leave_type_id', $typeId)
                ->where('starts_on', '2027-01-01')
                ->count());
        });
    }
}
