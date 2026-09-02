<?php

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Enums\AnomalyStatus;
use App\Modules\Attendance\Enums\AnomalyType;
use App\Modules\Attendance\Models\AttendanceAnomaly;
use App\Modules\Attendance\Services\AttendanceAnomalyService;
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
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Rule-based anomaly detection (neutral language, no fraud assertions, no
 * disciplinary action): missing checkout, long session, suspicious location
 * change. Findings are idempotent (dedupe_key) and transition via human review.
 */
class AttendanceAnomalyTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function employee(Tenant $tenant): Employee
    {
        return $this->withinTenant($tenant, function () {
            $employee = app(EmployeeService::class)->create([
                'first_name' => 'Z', 'last_name' => 'Q', 'employment_status' => 'active',
            ]);
            $days = [];
            for ($w = 0; $w <= 6; $w++) {
                $days[] = ['weekday' => $w, 'is_working_day' => true, 'start_time' => '08:00', 'end_time' => '16:00'];
            }
            $schedule = app(WorkScheduleService::class)->create(['name' => 'S', 'code' => 'S', 'timezone' => 'UTC'], $days);
            app(WorkScheduleService::class)->assign($schedule, ['scope_type' => 'company', 'effective_from' => '2026-01-01']);

            return $employee->fresh();
        });
    }

    public function test_long_session_is_flagged(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->employee($tenant);

        $this->withinTenant($tenant, function () use ($employee, $owner) {
            app(AttendanceSettingsService::class)->update(['anomaly_max_session_minutes' => 60], $owner);

            app(CheckInService::class)->checkIn($employee, new PunchInput, $owner, CarbonImmutable::parse('2026-03-02 08:00:00', 'UTC'));
            app(CheckOutService::class)->checkOut($employee, new PunchInput, $owner, CarbonImmutable::parse('2026-03-02 18:00:00', 'UTC'));

            $created = app(AttendanceAnomalyService::class)->detect(
                CarbonImmutable::parse('2026-03-02', 'UTC'),
                CarbonImmutable::parse('2026-03-02 18:05:00', 'UTC'),
            );

            $this->assertSame(1, $created);
            $this->assertSame(AnomalyType::LongSession, AttendanceAnomaly::first()->type);
        });
    }

    public function test_missing_checkout_is_flagged_after_day_end(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->employee($tenant);

        $this->withinTenant($tenant, function () use ($employee, $owner) {
            app(CheckInService::class)->checkIn($employee, new PunchInput, $owner, CarbonImmutable::parse('2026-03-02 08:00:00', 'UTC'));

            $created = app(AttendanceAnomalyService::class)->detect(
                CarbonImmutable::parse('2026-03-02', 'UTC'),
                CarbonImmutable::parse('2026-03-02 17:00:00', 'UTC'), // past 16:00 end
            );

            $this->assertSame(1, $created);
            $this->assertSame(AnomalyType::MissingCheckout, AttendanceAnomaly::first()->type);
        });
    }

    public function test_suspicious_location_change_is_flagged(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->employee($tenant);

        $this->withinTenant($tenant, function () use ($employee, $owner) {
            app(AttendanceSettingsService::class)->update([
                'allow_multiple_sessions' => true, 'anomaly_gps_jump_meters' => 100,
            ], $owner);

            // Session 1 near HQ.
            app(CheckInService::class)->checkIn($employee, new PunchInput(latitude: 24.7136, longitude: 46.6753, accuracyMeters: 5), $owner, CarbonImmutable::parse('2026-03-02 08:00:00', 'UTC'));
            app(CheckOutService::class)->checkOut($employee, new PunchInput(latitude: 24.7136, longitude: 46.6753, accuracyMeters: 5), $owner, CarbonImmutable::parse('2026-03-02 12:00:00', 'UTC'));

            // Session 2 far away.
            app(CheckInService::class)->checkIn($employee, new PunchInput(latitude: 25.5000, longitude: 47.5000, accuracyMeters: 5), $owner, CarbonImmutable::parse('2026-03-02 13:00:00', 'UTC'));
            app(CheckOutService::class)->checkOut($employee, new PunchInput(latitude: 25.5000, longitude: 47.5000, accuracyMeters: 5), $owner, CarbonImmutable::parse('2026-03-02 16:00:00', 'UTC'));

            $created = app(AttendanceAnomalyService::class)->detect(
                CarbonImmutable::parse('2026-03-02', 'UTC'),
                CarbonImmutable::parse('2026-03-02 16:05:00', 'UTC'),
            );

            $this->assertGreaterThanOrEqual(1, $created);
            $this->assertTrue(AttendanceAnomaly::where('type', AnomalyType::SuspiciousLocationChange->value)->exists());
        });
    }

    public function test_detection_is_idempotent(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->employee($tenant);

        $this->withinTenant($tenant, function () use ($employee, $owner) {
            app(AttendanceSettingsService::class)->update(['anomaly_max_session_minutes' => 60], $owner);
            app(CheckInService::class)->checkIn($employee, new PunchInput, $owner, CarbonImmutable::parse('2026-03-02 08:00:00', 'UTC'));
            app(CheckOutService::class)->checkOut($employee, new PunchInput, $owner, CarbonImmutable::parse('2026-03-02 18:00:00', 'UTC'));

            $date = CarbonImmutable::parse('2026-03-02', 'UTC');
            $now = CarbonImmutable::parse('2026-03-02 18:05:00', 'UTC');

            $this->assertSame(1, app(AttendanceAnomalyService::class)->detect($date, $now));
            $this->assertSame(0, app(AttendanceAnomalyService::class)->detect($date, $now)); // no duplicate
            $this->assertSame(1, AttendanceAnomaly::count());
        });
    }

    public function test_anomaly_can_be_resolved_and_not_reopened(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->employee($tenant);
        $reviewer = $this->makeUser();

        $this->withinTenant($tenant, function () use ($employee, $owner, $reviewer) {
            app(AttendanceSettingsService::class)->update(['anomaly_max_session_minutes' => 60], $owner);
            app(CheckInService::class)->checkIn($employee, new PunchInput, $owner, CarbonImmutable::parse('2026-03-02 08:00:00', 'UTC'));
            app(CheckOutService::class)->checkOut($employee, new PunchInput, $owner, CarbonImmutable::parse('2026-03-02 18:00:00', 'UTC'));
            app(AttendanceAnomalyService::class)->detect(CarbonImmutable::parse('2026-03-02', 'UTC'), CarbonImmutable::parse('2026-03-02 18:05:00', 'UTC'));

            $anomaly = AttendanceAnomaly::first();
            $resolved = app(AttendanceAnomalyService::class)->resolve($anomaly, $reviewer, AnomalyStatus::Resolved, 'Reviewed');
            $this->assertSame(AnomalyStatus::Resolved, $resolved->status);
            $this->assertNotNull($resolved->resolved_at);

            $this->expectException(ValidationException::class);
            app(AttendanceAnomalyService::class)->resolve($resolved->fresh(), $reviewer, AnomalyStatus::Dismissed);
        });
    }
}
