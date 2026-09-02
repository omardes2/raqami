<?php

namespace Tests\Feature\Leave;

use App\Modules\Attendance\Services\WorkScheduleService;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Identity\Models\User;
use App\Modules\Leave\Services\LeaveBalanceService;
use App\Modules\Leave\Services\LeaveEntitlementPeriodService;
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
 * HTTP surface: self-service submit/list, admin type CRUD, scoped approval, and
 * permission gating (403 without leave.view).
 */
class LeaveApiTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function seedLeave(Tenant $tenant, User $empUser): string
    {
        return $this->withinTenant($tenant, function () use ($empUser) {
            $employee = app(EmployeeService::class)->create(['first_name' => 'Ap', 'last_name' => 'Ie', 'employment_status' => 'active']);
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
                'entitlement_method' => 'none', 'approval_flow' => 'manager',
            ]);
            app(LeavePolicyAssignmentService::class)->assign($policy, ['scope_type' => 'company', 'effective_from' => '2026-01-01']);

            $period = app(LeaveEntitlementPeriodService::class)
                ->resolveOrCreate($employee->fresh(), $type->getKey(), $policy, CarbonImmutable::parse('2027-06-15'));
            $svc = app(LeaveBalanceService::class);
            DB::transaction(fn () => $svc->withLockedBalance($period, fn ($b) => $svc->grant($b, 4800)));

            return $type->getKey();
        });
    }

    public function test_owner_creates_leave_type_via_api(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->actingAs($owner)
            ->postJson('/api/leave/types', ['code' => 'SICK', 'name' => 'Sick'], $this->tenantHeaders($tenant))
            ->assertStatus(201)
            ->assertJsonPath('code', 'SICK');
    }

    public function test_self_service_submit_and_owner_approve(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $empUser = $this->memberWithRole($tenant, 'employee');
        $typeId = $this->seedLeave($tenant, $empUser);

        $created = $this->actingAs($empUser)->postJson('/api/leave/requests', [
            'leave_type_id' => $typeId, 'request_kind' => 'full_day',
            'starts_on' => '2027-06-15', 'ends_on' => '2027-06-15',
        ], $this->tenantHeaders($tenant))->assertStatus(201)->assertJsonPath('status', 'pending');

        $id = $created->json('id');

        $this->actingAs($empUser)->getJson('/api/leave/me/requests', $this->tenantHeaders($tenant))
            ->assertOk()->assertJsonPath('data.0.id', $id);

        $this->actingAs($owner)->postJson("/api/leave/requests/{$id}/approve", [], $this->tenantHeaders($tenant))
            ->assertOk()->assertJsonPath('status', 'approved');
    }

    public function test_index_requires_leave_view_permission(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $empUser = $this->memberWithRole($tenant, 'employee'); // employee role has no leave.view

        $this->actingAs($empUser)->getJson('/api/leave/requests', $this->tenantHeaders($tenant))
            ->assertStatus(403);
    }
}
