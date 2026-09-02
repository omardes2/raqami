<?php

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Models\OvertimeApproval;
use App\Modules\Attendance\Services\AttendanceSettingsService;
use App\Modules\Attendance\Services\CheckInService;
use App\Modules\Attendance\Services\CheckOutService;
use App\Modules\Attendance\Services\WorkScheduleService;
use App\Modules\Attendance\Support\PunchInput;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Overtime OVERRIDE (approving above the calculated amount) requires the distinct
 * attendance.overtime.override permission — plain review can never over-approve.
 */
class OvertimeOverrideApiTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function pendingOvertime(Tenant $tenant, mixed $owner): OvertimeApproval
    {
        return $this->withinTenant($tenant, function () use ($owner) {
            app(AttendanceSettingsService::class)->update([
                'overtime_tracking_enabled' => true, 'overtime_requires_approval' => true, 'allow_late_check_in' => true,
            ], $owner);

            $employee = app(EmployeeService::class)->create([
                'first_name' => 'OT', 'last_name' => 'Emp', 'employment_status' => 'active',
            ]);
            $days = [];
            for ($w = 0; $w <= 6; $w++) {
                $days[] = ['weekday' => $w, 'is_working_day' => true, 'start_time' => '08:00', 'end_time' => '16:00'];
            }
            $schedule = app(WorkScheduleService::class)->create(
                ['name' => 'S', 'code' => 'S', 'timezone' => 'UTC', 'overtime_after_minutes' => 0], $days,
            );
            app(WorkScheduleService::class)->assign($schedule, ['scope_type' => 'company', 'effective_from' => '2026-01-01']);
            $employee = $employee->fresh();

            app(CheckInService::class)->checkIn($employee, new PunchInput, $owner, CarbonImmutable::parse('2026-03-02 08:00:00', 'UTC'));
            app(CheckOutService::class)->checkOut($employee, new PunchInput, $owner, CarbonImmutable::parse('2026-03-02 18:00:00', 'UTC'));

            return OvertimeApproval::query()->firstOrFail(); // 120 calculated
        });
    }

    public function test_admin_can_override_above_calculated(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $admin = $this->memberWithRole($tenant, 'admin');
        $ot = $this->pendingOvertime($tenant, $owner);

        $this->actingAs($admin)->withHeaders($this->tenantHeaders($tenant))
            ->postJson("/api/attendance/overtime/{$ot->id}/approve", ['approved_minutes' => 200, 'allow_override' => true])
            ->assertOk()
            ->assertJsonPath('approved_minutes', 200)
            ->assertJsonPath('calculated_minutes', 120);
    }

    public function test_hr_manager_cannot_override_without_permission(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $hr = $this->memberWithRole($tenant, 'hr-manager');
        $ot = $this->pendingOvertime($tenant, $owner);

        $this->actingAs($hr)->withHeaders($this->tenantHeaders($tenant))
            ->postJson("/api/attendance/overtime/{$ot->id}/approve", ['approved_minutes' => 200, 'allow_override' => true])
            ->assertForbidden();

        $this->withinTenant($tenant, fn () => $this->assertSame(
            'pending', OvertimeApproval::query()->findOrFail($ot->id)->status->value, // unchanged
        ));
    }

    public function test_hr_manager_can_approve_within_calculated(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $hr = $this->memberWithRole($tenant, 'hr-manager');
        $ot = $this->pendingOvertime($tenant, $owner);

        $this->actingAs($hr)->withHeaders($this->tenantHeaders($tenant))
            ->postJson("/api/attendance/overtime/{$ot->id}/approve", ['approved_minutes' => 90])
            ->assertOk()
            ->assertJsonPath('approved_minutes', 90);
    }

    public function test_over_approve_without_flag_is_rejected(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $admin = $this->memberWithRole($tenant, 'admin');
        $ot = $this->pendingOvertime($tenant, $owner);

        // Even an override-capable actor must pass allow_override to exceed calc.
        $this->actingAs($admin)->withHeaders($this->tenantHeaders($tenant))
            ->postJson("/api/attendance/overtime/{$ot->id}/approve", ['approved_minutes' => 200])
            ->assertStatus(422);
    }

    public function test_out_of_scope_reviewer_denied(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $ot = $this->pendingOvertime($tenant, $owner);
        // Team leader scoped to a team the employee isn't in → no review access.
        $leader = $this->memberWithRole($tenant, 'team-leader', 'team', (string) Str::ulid());

        $this->actingAs($leader)->withHeaders($this->tenantHeaders($tenant))
            ->postJson("/api/attendance/overtime/{$ot->id}/approve", ['approved_minutes' => 90])
            ->assertStatus(403);
    }
}
