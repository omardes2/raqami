<?php

namespace Tests\Feature\Notifications;

use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Services\AttendanceCorrectionService;
use App\Modules\Attendance\Services\AttendanceSettingsService;
use App\Modules\Attendance\Services\CheckInService;
use App\Modules\Attendance\Services\CheckOutService;
use App\Modules\Attendance\Services\WorkScheduleService;
use App\Modules\Attendance\Support\PunchInput;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Identity\Models\TenantMembership;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Sprint 8B — attendance correction review notifies the requester with only a
 * result flag; the reviewer's rejection reason never enters the payload.
 */
class AttendanceNotificationTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function employee(Tenant $tenant): Employee
    {
        return $this->withinTenant($tenant, function () {
            $employee = app(EmployeeService::class)->create([
                'first_name' => 'Cor', 'last_name' => 'R', 'employment_status' => 'active',
            ]);
            $days = [];
            for ($w = 0; $w <= 6; $w++) {
                $days[] = ['weekday' => $w, 'is_working_day' => true, 'segments' => [['start_time' => '08:00', 'end_time' => '16:00']]];
            }
            $schedule = app(WorkScheduleService::class)->create(
                ['name' => 'S', 'code' => 'S', 'timezone' => 'UTC', 'grace_minutes' => 10, 'overtime_after_minutes' => 0],
                $days,
            );
            app(WorkScheduleService::class)->assign($schedule, ['scope_type' => 'company', 'effective_from' => '2026-01-01']);

            return $employee->fresh();
        });
    }

    private function reviewerMember(Tenant $tenant): User
    {
        $user = User::factory()->create();
        $this->withinTenant($tenant, fn () => TenantMembership::create(['user_id' => $user->id, 'status' => 'active']));

        return $user;
    }

    private function oneSessionDay(Tenant $tenant, Employee $employee, User $requester): AttendanceRecord
    {
        return $this->withinTenant($tenant, function () use ($employee, $requester) {
            app(AttendanceSettingsService::class)->update(
                ['allow_multiple_sessions' => true, 'attendance_correction_enabled' => true], $requester
            );
            app(CheckInService::class)->checkIn($employee, new PunchInput, $requester, CarbonImmutable::parse('2026-03-02 08:00:00', 'UTC'));

            return app(CheckOutService::class)->checkOut($employee, new PunchInput, $requester, CarbonImmutable::parse('2026-03-02 16:00:00', 'UTC'));
        });
    }

    private function inboxRows(Tenant $tenant, string $userId, string $type): array
    {
        DB::statement("select set_config('app.tenant_id', ?, false)", [(string) $tenant->getKey()]);
        DB::statement("select set_config('app.user_id', ?, false)", [$userId]);
        DB::statement("select set_config('app.platform_readonly', 'off', false)");
        try {
            return DB::table('notifications')->where('type', $type)->get()->all();
        } finally {
            app(TenantContext::class)->clear();
        }
    }

    public function test_approval_notifies_requester(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();   // owner is the requester + member
        $employee = $this->employee($tenant);
        $reviewer = $this->reviewerMember($tenant);
        $record = $this->oneSessionDay($tenant, $employee, $owner);

        $this->withinTenant($tenant, function () use ($record, $owner, $reviewer) {
            $session = $record->sessions()->orderBy('sequence')->first();
            $correction = app(AttendanceCorrectionService::class)->request($record, [
                'attendance_session_id' => $session->getKey(),
                'requested_check_out_at' => '2026-03-02 17:00:00',
                'reason' => 'stayed late',
            ], $owner);
            app(AttendanceCorrectionService::class)->approve($correction, $reviewer);
        });

        $rows = $this->inboxRows($tenant, (string) $owner->id, 'attendance.correction.reviewed');
        $this->assertCount(1, $rows);
        $this->assertStringContainsString('approved', $rows[0]->data);
    }

    public function test_rejection_notifies_requester_without_leaking_reason(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->employee($tenant);
        $reviewer = $this->reviewerMember($tenant);
        $record = $this->oneSessionDay($tenant, $employee, $owner);
        $secret = 'CONFIDENTIAL-REVIEWER-NOTE-XYZ';

        $this->withinTenant($tenant, function () use ($record, $owner, $reviewer, $secret) {
            $session = $record->sessions()->orderBy('sequence')->first();
            $correction = app(AttendanceCorrectionService::class)->request($record, [
                'attendance_session_id' => $session->getKey(),
                'requested_check_out_at' => '2026-03-02 17:00:00',
                'reason' => 'stayed late',
            ], $owner);
            app(AttendanceCorrectionService::class)->rejectRequest($correction, $reviewer, $secret);
        });

        $rows = $this->inboxRows($tenant, (string) $owner->id, 'attendance.correction.reviewed');
        $this->assertCount(1, $rows);
        $this->assertStringContainsString('rejected', $rows[0]->data);
        $this->assertStringNotContainsString($secret, $rows[0]->data);
    }
}
