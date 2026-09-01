<?php

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Services\ScheduleResolver;
use App\Modules\Attendance\Services\WorkScheduleService;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Split-shift segments and rotating (cyclic) schedules resolved deterministically.
 */
class ScheduleSegmentsTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function employee(Tenant $tenant): Employee
    {
        return $this->withinTenant($tenant, fn () => app(EmployeeService::class)->create([
            'first_name' => 'S', 'last_name' => 'S', 'employment_status' => 'active',
        ]));
    }

    public function test_split_shift_day_resolves_two_segments(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->employee($tenant);

        $this->withinTenant($tenant, function () use ($employee) {
            $days = [];
            for ($w = 0; $w <= 6; $w++) {
                $days[] = [
                    'weekday' => $w,
                    'is_working_day' => true,
                    'segments' => [
                        ['start_time' => '08:00', 'end_time' => '12:00'],
                        ['start_time' => '16:00', 'end_time' => '20:00'],
                    ],
                ];
            }
            $schedule = app(WorkScheduleService::class)->create(['name' => 'Split', 'code' => 'SPLIT', 'timezone' => 'UTC'], $days);
            app(WorkScheduleService::class)->assign($schedule, ['scope_type' => 'company', 'effective_from' => '2026-01-01']);

            $resolved = app(ScheduleResolver::class)->resolveWorkDay(
                $employee->fresh(), CarbonImmutable::parse('2026-03-02 08:00:00', 'UTC'), 'UTC',
            );

            $this->assertCount(2, $resolved->segments);
            $this->assertSame('2026-03-02 08:00:00', $resolved->segments[0]->startAt->format('Y-m-d H:i:s'));
            $this->assertSame('2026-03-02 12:00:00', $resolved->segments[0]->endAt->format('Y-m-d H:i:s'));
            $this->assertSame('2026-03-02 16:00:00', $resolved->segments[1]->startAt->format('Y-m-d H:i:s'));

            // The afternoon punch selects the 16:00 segment.
            $seg = $resolved->segmentFor(CarbonImmutable::parse('2026-03-02 16:05:00', 'UTC'));
            $this->assertSame('2026-03-02 16:00:00', $seg->startAt->format('Y-m-d H:i:s'));
        });
    }

    public function test_rotating_schedule_resolves_cycle_day(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->employee($tenant);

        $this->withinTenant($tenant, function () use ($employee) {
            // 2-day cycle: day 0 works 08:00-16:00, day 1 is off. Anchor Mon 2026-03-02.
            $days = [
                ['weekday' => 0, 'is_working_day' => true, 'segments' => [['start_time' => '08:00', 'end_time' => '16:00']]],
                ['weekday' => 1, 'is_working_day' => false],
            ];
            $schedule = app(WorkScheduleService::class)->create([
                'name' => 'Rot', 'code' => 'ROT', 'timezone' => 'UTC',
                'cycle_length_days' => 2, 'anchor_date' => '2026-03-02',
            ], $days);
            app(WorkScheduleService::class)->assign($schedule, ['scope_type' => 'company', 'effective_from' => '2026-01-01']);

            $resolver = app(ScheduleResolver::class);
            $emp = $employee->fresh();

            // Anchor day (cycle day 0) → working.
            $d0 = $resolver->resolveWorkDay($emp, CarbonImmutable::parse('2026-03-02 08:00:00', 'UTC'), 'UTC');
            $this->assertTrue($d0->isScheduledWorkingDay());
            // Next day (cycle day 1) → off.
            $d1 = $resolver->resolveWorkDay($emp, CarbonImmutable::parse('2026-03-03 08:00:00', 'UTC'), 'UTC');
            $this->assertFalse($d1->isScheduledWorkingDay());
            // Cycle wraps: +2 days back to cycle day 0 → working.
            $d2 = $resolver->resolveWorkDay($emp, CarbonImmutable::parse('2026-03-04 08:00:00', 'UTC'), 'UTC');
            $this->assertTrue($d2->isScheduledWorkingDay());
        });
    }
}
