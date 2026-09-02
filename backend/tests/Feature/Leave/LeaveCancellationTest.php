<?php

namespace Tests\Feature\Leave;

use App\Modules\Attendance\Services\WorkScheduleService;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Identity\Models\User;
use App\Modules\Leave\Enums\LeaveRequestStatus;
use App\Modules\Leave\Models\LeaveBalance;
use App\Modules\Leave\Services\LeaveBalanceService;
use App\Modules\Leave\Services\LeaveCancellationService;
use App\Modules\Leave\Services\LeaveEntitlementPeriodService;
use App\Modules\Leave\Services\LeavePolicyAssignmentService;
use App\Modules\Leave\Services\LeavePolicyService;
use App\Modules\Leave\Services\LeaveRequestService;
use App\Modules\Leave\Services\LeaveResolver;
use App\Modules\Leave\Services\LeaveTypeService;
use App\Modules\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class LeaveCancellationTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function scenario(Tenant $tenant): array
    {
        return $this->withinTenant($tenant, function () {
            $empUser = User::factory()->create();
            $employee = app(EmployeeService::class)->create(['first_name' => 'Can', 'last_name' => 'Cel', 'employment_status' => 'active']);
            $employee->fill(['user_id' => $empUser->id])->save();

            $days = [];
            for ($w = 0; $w <= 6; $w++) {
                $days[] = ['weekday' => $w, 'is_working_day' => true, 'segments' => [['start_time' => '08:00', 'end_time' => '16:00']]];
            }
            $schedule = app(WorkScheduleService::class)->create(['name' => 'S', 'code' => 'S', 'timezone' => 'UTC'], $days);
            app(WorkScheduleService::class)->assign($schedule, ['scope_type' => 'company', 'effective_from' => '2026-01-01']);

            $type = app(LeaveTypeService::class)->create(['code' => 'ANN', 'name' => 'Annual']);
            $policy = app(LeavePolicyService::class)->create([
                'leave_type_id' => $type->getKey(), 'name' => 'P', 'effective_from' => '2026-01-01',
                'entitlement_method' => 'none', 'approval_flow' => 'none',
            ]);
            app(LeavePolicyAssignmentService::class)->assign($policy, ['scope_type' => 'company', 'effective_from' => '2026-01-01']);

            $period = app(LeaveEntitlementPeriodService::class)
                ->resolveOrCreate($employee->fresh(), $type->getKey(), $policy, CarbonImmutable::parse('2027-06-15'));
            $svc = app(LeaveBalanceService::class);
            DB::transaction(fn () => $svc->withLockedBalance($period, fn ($b) => $svc->grant($b, 4800)));

            return [$employee->fresh(), $empUser, $type->getKey()];
        });
    }

    public function test_cancellation_request_keeps_leave_active_until_finalized(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        [$employee, $empUser, $typeId] = $this->scenario($tenant);

        $this->withinTenant($tenant, function () use ($employee, $empUser, $owner, $typeId) {
            $request = app(LeaveRequestService::class)->submit($employee, [
                'leave_type_id' => $typeId, 'starts_on' => '2027-06-15', 'ends_on' => '2027-06-15',
            ], $empUser); // auto-approved (none flow)
            $this->assertSame('approved', $request->fresh()->status->value);
            $this->assertSame(480, LeaveBalance::query()->first()->used_minutes);

            // Employee requests cancellation → cancellation_pending, still ACTIVE.
            $request = app(LeaveCancellationService::class)->request($request->fresh(), $empUser);
            $this->assertSame('cancellation_pending', $request->status->value);
            $this->assertSame(480, LeaveBalance::query()->first()->used_minutes); // NOT restored yet
            $this->assertTrue(LeaveResolver::isActive(LeaveRequestStatus::CancellationPending));
            $this->assertNotNull(app(LeaveResolver::class)->resolve($employee, CarbonImmutable::parse('2027-06-15')));

            // Manager/HR finalizes → usage reversed, coverage gone.
            $request = app(LeaveCancellationService::class)->approve($request->fresh(), $owner);
            $this->assertSame('cancelled', $request->status->value);
            $b = LeaveBalance::query()->first();
            $this->assertSame(0, $b->used_minutes);
            $this->assertSame(4800, $b->available_minutes);
            $this->assertNull(app(LeaveResolver::class)->resolve($employee, CarbonImmutable::parse('2027-06-15')));
        });
    }

    public function test_direct_cancel_requires_reason(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        [$employee, $empUser, $typeId] = $this->scenario($tenant);

        $this->withinTenant($tenant, function () use ($employee, $empUser, $owner, $typeId) {
            $request = app(LeaveRequestService::class)->submit($employee, [
                'leave_type_id' => $typeId, 'starts_on' => '2027-06-15', 'ends_on' => '2027-06-15',
            ], $empUser);

            $this->expectException(ValidationException::class);
            app(LeaveCancellationService::class)->directCancel($request->fresh(), $owner, '   ');
        });
    }
}
