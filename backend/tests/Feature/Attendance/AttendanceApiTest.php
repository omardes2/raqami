<?php

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Services\WorkScheduleService;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Employees\Services\EmployeeUserLinkService;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * HTTP-level attendance: self-service punches (auth + employee link, no
 * permission), admin config (permission-gated), and cross-tenant/scope safety.
 */
class AttendanceApiTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    /** A user linked to an employee on a company-wide Mon–Fri 08:00–16:00 schedule. */
    private function linkedEmployee(Tenant $tenant, User $user): Employee
    {
        return $this->withinTenant($tenant, function () use ($user) {
            $employee = app(EmployeeService::class)->create([
                'first_name' => 'Lina', 'last_name' => 'Nasser', 'employment_status' => 'active',
            ]);
            app(EmployeeUserLinkService::class)->link($employee, $user->id);

            $days = [];
            for ($weekday = 0; $weekday <= 6; $weekday++) {
                $working = $weekday >= 1 && $weekday <= 5;
                $days[] = [
                    'weekday' => $weekday, 'is_working_day' => $working,
                    'start_time' => $working ? '08:00' : null,
                    'end_time' => $working ? '16:00' : null,
                ];
            }
            $schedule = app(WorkScheduleService::class)->create(
                ['name' => 'Std', 'code' => 'STD', 'timezone' => 'UTC'], $days,
            );
            app(WorkScheduleService::class)->assign($schedule, [
                'scope_type' => 'company', 'effective_from' => '2026-01-01',
            ]);

            return $employee->refresh();
        });
    }

    public function test_employee_can_check_in_and_out_via_api(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $user = $this->memberWithRole($tenant, 'employee');
        $this->linkedEmployee($tenant, $user);

        $in = $this->actingAs($user)->withHeaders($this->tenantHeaders($tenant))
            ->postJson('/api/attendance/check-in', []);
        $in->assertCreated()->assertJsonPath('status', fn ($s) => in_array($s, ['present', 'late'], true));

        $out = $this->actingAs($user)->withHeaders($this->tenantHeaders($tenant))
            ->postJson('/api/attendance/check-out', []);
        $out->assertOk()->assertJsonPath('id', $in->json('id'));
        $this->assertNotNull($out->json('check_out_at'));
    }

    public function test_user_without_employee_link_cannot_check_in(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $user = $this->memberWithRole($tenant, 'employee');

        $this->actingAs($user)->withHeaders($this->tenantHeaders($tenant))
            ->postJson('/api/attendance/check-in', [])
            ->assertForbidden();
    }

    public function test_settings_require_permission(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $viewer = $this->memberWithRole($tenant, 'employee');

        // Plain employee cannot read attendance settings.
        $this->actingAs($viewer)->withHeaders($this->tenantHeaders($tenant))
            ->getJson('/api/attendance/settings')->assertForbidden();

        // Owner can.
        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->getJson('/api/attendance/settings')->assertOk()
            ->assertJsonPath('default_timezone', 'UTC');
    }

    public function test_admin_can_create_schedule_via_api(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->postJson('/api/attendance/schedules', [
                'name' => 'Night', 'code' => 'NIGHT', 'timezone' => 'UTC',
                'days' => [
                    ['weekday' => 1, 'is_working_day' => true, 'start_time' => '22:00', 'end_time' => '06:00'],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('code', 'NIGHT');
    }

    public function test_check_in_is_isolated_across_tenants(): void
    {
        [, $tenantA] = $this->createCompanyWithOwner(['name' => 'A']);
        [, $tenantB] = $this->createCompanyWithOwner(['name' => 'B']);
        $userA = $this->memberWithRole($tenantA, 'employee');
        $this->linkedEmployee($tenantA, $userA);

        // User A acting with tenant B's header is not a member there, so no tenant
        // context resolves (409) — they can never punch into another tenant.
        $this->actingAs($userA)->withHeaders($this->tenantHeaders($tenantB))
            ->postJson('/api/attendance/check-in', [])
            ->assertStatus(409);
    }
}
