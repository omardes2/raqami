<?php

namespace Tests\Feature\Leave;

use App\Modules\Attendance\Services\WorkScheduleService;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Identity\Models\User;
use App\Modules\Leave\Services\LeaveBalanceService;
use App\Modules\Leave\Services\LeaveEntitlementPeriodService;
use App\Modules\Leave\Services\LeavePolicyAssignmentService;
use App\Modules\Leave\Services\LeavePolicyService;
use App\Modules\Leave\Services\LeaveRequestService;
use App\Modules\Leave\Services\LeaveTypeService;
use App\Modules\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Sensitive (medical) leave attachments require leave.attachments.view_sensitive:
 * a manager who can view/approve leave does NOT automatically gain access; the
 * requesting employee always can; an Owner (all permissions) can.
 */
class LeaveAttachmentSecurityTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function seedRequest(Tenant $tenant, User $empUser): string
    {
        return $this->withinTenant($tenant, function () use ($empUser) {
            $employee = app(EmployeeService::class)->create(['first_name' => 'Med', 'last_name' => 'Ic', 'employment_status' => 'active']);
            $employee->fill(['user_id' => $empUser->id])->save();

            $days = [];
            for ($w = 0; $w <= 6; $w++) {
                $days[] = ['weekday' => $w, 'is_working_day' => true, 'segments' => [['start_time' => '08:00', 'end_time' => '16:00']]];
            }
            $schedule = app(WorkScheduleService::class)->create(['name' => 'S', 'code' => 'S', 'timezone' => 'UTC'], $days);
            app(WorkScheduleService::class)->assign($schedule, ['scope_type' => 'company', 'effective_from' => '2026-01-01']);

            $type = app(LeaveTypeService::class)->create(['code' => 'SICK', 'name' => 'Sick']);
            $policy = app(LeavePolicyService::class)->create([
                'leave_type_id' => $type->getKey(), 'name' => 'P', 'effective_from' => '2026-01-01',
                'entitlement_method' => 'none', 'approval_flow' => 'manager',
            ]);
            app(LeavePolicyAssignmentService::class)->assign($policy, ['scope_type' => 'company', 'effective_from' => '2026-01-01']);

            $period = app(LeaveEntitlementPeriodService::class)
                ->resolveOrCreate($employee->fresh(), $type->getKey(), $policy, CarbonImmutable::parse('2027-06-15'));
            $svc = app(LeaveBalanceService::class);
            DB::transaction(fn () => $svc->withLockedBalance($period, fn ($b) => $svc->grant($b, 4800)));

            return app(LeaveRequestService::class)->submit($employee->fresh(), [
                'leave_type_id' => $type->getKey(), 'starts_on' => '2027-06-15', 'ends_on' => '2027-06-15',
            ], $empUser)->getKey();
        });
    }

    public function test_medical_attachment_requires_sensitive_permission(): void
    {
        Storage::fake('local');
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $empUser = $this->memberWithRole($tenant, 'employee');
        $manager = $this->memberWithRole($tenant, 'department-manager'); // leave.view, NOT view_sensitive
        $id = $this->seedRequest($tenant, $empUser);

        // Employee uploads a medical certificate on their own request.
        $upload = $this->actingAs($empUser)->post(
            "/api/leave/requests/{$id}/attachments",
            ['category' => 'medical_certificate', 'file' => UploadedFile::fake()->create('cert.pdf', 50, 'application/pdf')],
            $this->tenantHeaders($tenant),
        )->assertStatus(201);
        $aid = $upload->json('id');

        // Employee (owner of the request) may download via the self route.
        $this->actingAs($empUser)
            ->get("/api/leave/me/requests/{$id}/attachments/{$aid}/download", $this->tenantHeaders($tenant))
            ->assertOk();

        // Manager with leave.view but NOT view_sensitive is refused the medical doc.
        $this->actingAs($manager)
            ->get("/api/leave/requests/{$id}/attachments/{$aid}/download", $this->tenantHeaders($tenant))
            ->assertStatus(403);

        // Owner (all permissions incl. view_sensitive) may download.
        $this->actingAs($owner)
            ->get("/api/leave/requests/{$id}/attachments/{$aid}/download", $this->tenantHeaders($tenant))
            ->assertOk();
    }
}
