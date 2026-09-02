<?php

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Models\HolidayCalendar;
use App\Modules\Attendance\Services\HolidayCalendarService;
use App\Modules\Attendance\Services\HolidayResolver;
use App\Modules\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * HolidayResolver: branch > company precedence, multi-day ranges, effective
 * assignment windows, and tenant isolation.
 */
class HolidayResolverTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function calendar(Tenant $tenant, string $code): HolidayCalendar
    {
        return $this->withinTenant($tenant, fn () => app(HolidayCalendarService::class)->createCalendar([
            'name' => $code, 'code' => $code, 'timezone' => 'UTC',
        ]));
    }

    public function test_company_holiday_is_resolved(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () use ($tenant) {
            $cal = $this->calendar($tenant, 'CO');
            app(HolidayCalendarService::class)->addHoliday($cal, ['name' => 'Founders Day', 'date' => '2026-05-01']);
            app(HolidayCalendarService::class)->assign($cal, ['scope_type' => 'company', 'effective_from' => '2026-01-01']);

            $h = app(HolidayResolver::class)->resolve(null, CarbonImmutable::parse('2026-05-01'));
            $this->assertNotNull($h);
            $this->assertSame('Founders Day', $h->name);
            $this->assertNull(app(HolidayResolver::class)->resolve(null, CarbonImmutable::parse('2026-05-02')));
        });
    }

    public function test_branch_holiday_wins_over_company(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $branch = $this->makeBranch($tenant, ['name' => 'HQ']);

        $this->withinTenant($tenant, function () use ($tenant, $branch) {
            $company = $this->calendar($tenant, 'CO');
            app(HolidayCalendarService::class)->addHoliday($company, ['name' => 'Company Day', 'date' => '2026-05-01']);
            app(HolidayCalendarService::class)->assign($company, ['scope_type' => 'company', 'effective_from' => '2026-01-01']);

            $branchCal = $this->calendar($tenant, 'BR');
            app(HolidayCalendarService::class)->addHoliday($branchCal, ['name' => 'Branch Day', 'date' => '2026-05-01']);
            app(HolidayCalendarService::class)->assign($branchCal, ['scope_type' => 'branch', 'scope_id' => $branch->id, 'effective_from' => '2026-01-01']);

            $h = app(HolidayResolver::class)->resolve($branch->id, CarbonImmutable::parse('2026-05-01'));
            $this->assertSame('Branch Day', $h->name);
        });
    }

    public function test_multi_day_holiday_covers_the_range(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () use ($tenant) {
            $cal = $this->calendar($tenant, 'CO');
            app(HolidayCalendarService::class)->addHoliday($cal, ['name' => 'Eid', 'date' => '2026-05-01', 'end_date' => '2026-05-04']);
            app(HolidayCalendarService::class)->assign($cal, ['scope_type' => 'company', 'effective_from' => '2026-01-01']);

            $resolver = app(HolidayResolver::class);
            $this->assertNotNull($resolver->resolve(null, CarbonImmutable::parse('2026-05-01')));
            $this->assertNotNull($resolver->resolve(null, CarbonImmutable::parse('2026-05-03')));
            $this->assertNotNull($resolver->resolve(null, CarbonImmutable::parse('2026-05-04')));
            $this->assertNull($resolver->resolve(null, CarbonImmutable::parse('2026-05-05')));
        });
    }

    public function test_holiday_is_isolated_across_tenants(): void
    {
        [, $tenantA] = $this->createCompanyWithOwner(['name' => 'A']);
        [, $tenantB] = $this->createCompanyWithOwner(['name' => 'B']);

        $this->withinTenant($tenantA, function () use ($tenantA) {
            $cal = $this->calendar($tenantA, 'CO');
            app(HolidayCalendarService::class)->addHoliday($cal, ['name' => 'A Day', 'date' => '2026-05-01']);
            app(HolidayCalendarService::class)->assign($cal, ['scope_type' => 'company', 'effective_from' => '2026-01-01']);
        });

        // Tenant B sees no holiday on that date (RLS + resolver scope).
        $this->withinTenant($tenantB, fn () => $this->assertNull(
            app(HolidayResolver::class)->resolve(null, CarbonImmutable::parse('2026-05-01'))
        ));
    }
}
