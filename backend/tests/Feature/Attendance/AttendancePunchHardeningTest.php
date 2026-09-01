<?php

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Models\AttendanceEvent;
use App\Modules\Attendance\Models\AttendanceRecord;
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
 * Punch-input trust boundary + check-out concurrency. Invalid GPS is rejected;
 * client-supplied geofence claims are ignored (server decides); a retried
 * check-out does not double-close or duplicate work time.
 */
class AttendancePunchHardeningTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function linkedEmployee(Tenant $tenant, User $user): Employee
    {
        return $this->withinTenant($tenant, function () use ($user) {
            $e = app(EmployeeService::class)->create(['first_name' => 'P', 'last_name' => 'Q', 'employment_status' => 'active']);
            app(EmployeeUserLinkService::class)->link($e, $user->id);
            $days = [];
            for ($w = 0; $w <= 6; $w++) {
                $days[] = ['weekday' => $w, 'is_working_day' => true, 'start_time' => '00:00', 'end_time' => '23:59'];
            }
            $s = app(WorkScheduleService::class)->create(['name' => 'S', 'code' => 'S', 'timezone' => 'UTC'], $days);
            app(WorkScheduleService::class)->assign($s, ['scope_type' => 'company', 'effective_from' => '2026-01-01']);

            return $e->fresh();
        });
    }

    private function actor(Tenant $tenant, array $userAttributes = []): User
    {
        $user = $this->memberWithRole($tenant, 'employee', 'company', null, $userAttributes);
        $this->linkedEmployee($tenant, $user);

        return $user;
    }

    public function test_business_error_is_localized_in_arabic(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $user = $this->actor($tenant, ['locale' => 'ar']);

        // Check out with no open record → localized Arabic business error.
        $this->actingAs($user)->withHeaders($this->tenantHeaders($tenant))
            ->postJson('/api/attendance/check-out', [])
            ->assertStatus(422)
            ->assertJsonPath('errors.attendance.0', __('attendance.no_open', [], 'ar'));
    }

    public function test_invalid_coordinates_are_rejected(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $user = $this->actor($tenant);
        $h = $this->tenantHeaders($tenant);

        $this->actingAs($user)->withHeaders($h)->postJson('/api/attendance/check-in', ['latitude' => 91, 'longitude' => 0])
            ->assertStatus(422)->assertJsonValidationErrors('latitude');
        $this->actingAs($user)->withHeaders($h)->postJson('/api/attendance/check-in', ['latitude' => 0, 'longitude' => 181])
            ->assertStatus(422)->assertJsonValidationErrors('longitude');
        $this->actingAs($user)->withHeaders($h)->postJson('/api/attendance/check-in', ['latitude' => 0, 'longitude' => 0, 'accuracy_meters' => -5])
            ->assertStatus(422)->assertJsonValidationErrors('accuracy_meters');
    }

    public function test_client_supplied_geofence_claim_is_ignored(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $user = $this->actor($tenant);

        // Client tries to forge the server's decision; extra keys are stripped.
        $this->actingAs($user)->withHeaders($this->tenantHeaders($tenant))
            ->postJson('/api/attendance/check-in', [
                'inside_geofence' => true,
                'distance_meters' => 0,
                'matched_location_id' => '01FORGED0000000000000000000',
            ])
            ->assertCreated()
            // No coordinates were actually sent → server records inside_geofence as null,
            // never the client's forged "true".
            ->assertJsonPath('check_in_inside_geofence', null);

        $this->withinTenant($tenant, function () {
            $event = AttendanceEvent::query()->firstWhere('event_type', 'check_in');
            $this->assertNull($event->inside_geofence);
            $this->assertNull($event->matched_location_id);
        });
    }

    public function test_duplicate_checkout_same_request_id_is_idempotent(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $user = $this->actor($tenant);
        $h = $this->tenantHeaders($tenant);

        $this->actingAs($user)->withHeaders($h)->postJson('/api/attendance/check-in', [])->assertCreated();

        $first = $this->actingAs($user)->withHeaders($h)
            ->postJson('/api/attendance/check-out', ['client_request_id' => 'co-1'])->assertOk();
        $second = $this->actingAs($user)->withHeaders($h)
            ->postJson('/api/attendance/check-out', ['client_request_id' => 'co-1'])->assertOk();

        $this->assertSame($first->json('id'), $second->json('id'));
        $this->withinTenant($tenant, function () {
            $this->assertSame(1, AttendanceEvent::query()->where('event_type', 'check_out')->count());
            $this->assertSame(1, AttendanceRecord::query()->whereNotNull('check_out_at')->count());
        });
    }

    public function test_second_checkout_new_request_id_reports_no_open_record(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $user = $this->actor($tenant);
        $h = $this->tenantHeaders($tenant);

        $this->actingAs($user)->withHeaders($h)->postJson('/api/attendance/check-in', [])->assertCreated();
        $this->actingAs($user)->withHeaders($h)->postJson('/api/attendance/check-out', ['client_request_id' => 'co-a'])->assertOk();

        // A fresh request after the record is closed finds nothing open → stable 422.
        $this->actingAs($user)->withHeaders($h)
            ->postJson('/api/attendance/check-out', ['client_request_id' => 'co-b'])
            ->assertStatus(422)->assertJsonValidationErrors('attendance');

        $this->withinTenant($tenant, fn () => $this->assertSame(
            1, AttendanceEvent::query()->where('event_type', 'check_out')->count(),
        ));
    }
}
