<?php

namespace Tests\Feature\Dashboard;

use App\Modules\Dashboard\Services\DashboardService;
use App\Modules\Employees\Models\Employee;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Sprint 8A Phase 2 company dashboard: the composite read model returns ONLY the
 * KPI cards the caller is independently authorized and scoped to see. An
 * unauthorized card is OMITTED (never a zero, a flag, or a whole-dashboard 403);
 * the payroll card is company-wide only (a branch/dept/team-scoped grant never
 * yields salary visibility); "today" is the tenant-local date; and no card leaks
 * across tenants.
 */
class DashboardTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    /** @return array<string,mixed> */
    private function dashboard(Tenant $tenant, User $user): array
    {
        return $this->withinTenant($tenant, fn () => app(DashboardService::class)->company($user));
    }

    public function test_owner_sees_all_cards(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $data = $this->dashboard($tenant, $owner);
        $this->assertEqualsCanonicalizing(
            ['organization', 'attendance', 'leave', 'tasks', 'payroll'],
            array_keys($data),
        );
    }

    #[DataProvider('cardMatrix')]
    public function test_card_visibility_matrix(string $role, array $expectedCards): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $user = $role === 'owner' ? $owner : $this->memberWithRole($tenant, $role);

        $this->assertEqualsCanonicalizing($expectedCards, array_keys($this->dashboard($tenant, $user)));
    }

    /** @return array<string, array{0:string,1:array<int,string>}> */
    public static function cardMatrix(): array
    {
        return [
            // Admin holds every report permission company-wide (incl. payroll).
            'admin sees all' => ['admin', ['organization', 'attendance', 'leave', 'tasks', 'payroll']],
            // HR Manager: org + attendance + leave + tasks, but NO payroll (salary privacy).
            'hr-manager has no payroll' => ['hr-manager', ['organization', 'attendance', 'leave', 'tasks']],
            // Accountant: ONLY the company-wide payroll card; no operational report grants.
            'accountant payroll only' => ['accountant', ['payroll']],
            // Employee: no report permissions at all — every card omitted.
            'employee sees nothing' => ['employee', []],
        ];
    }

    public function test_scoped_payroll_grant_never_shows_payroll_card(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $branch = $this->makeBranch($tenant);

        // Same role, two scopes: company-wide sees payroll; branch-scoped does not,
        // because payroll reporting is company-wide only (never branch/dept/team).
        $companyAccountant = $this->memberWithRole($tenant, 'accountant', 'company');
        $branchAccountant = $this->memberWithRole($tenant, 'accountant', 'branch', $branch->getKey());

        $this->assertContains('payroll', array_keys($this->dashboard($tenant, $companyAccountant)));
        $this->assertNotContains('payroll', array_keys($this->dashboard($tenant, $branchAccountant)));
    }

    public function test_today_uses_tenant_timezone(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner(['timezone' => 'Asia/Hebron']);

        // An instant that is still 2026-09-04 in UTC but already 2026-09-05 in Hebron.
        $instant = CarbonImmutable::parse('2026-09-04 23:30:00', 'UTC');
        Carbon::setTestNow(Carbon::parse('2026-09-04 23:30:00', 'UTC'));
        try {
            $expected = $instant->setTimezone('Asia/Hebron')->toDateString();
            $this->assertNotSame('2026-09-04', $expected, 'guard: the instant must cross the date line');

            $data = $this->dashboard($tenant, $owner);
            $this->assertSame($expected, $data['attendance']['date']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_organization_card_is_tenant_isolated(): void
    {
        [$ownerA, $tenantA] = $this->createCompanyWithOwner();
        $this->makeEmployee($tenantA, ['first_name' => 'A', 'last_name' => 'One']);
        $this->makeEmployee($tenantA, ['first_name' => 'A', 'last_name' => 'Two']);

        [$ownerB, $tenantB] = $this->createCompanyWithOwner();
        $this->makeEmployee($tenantB, ['first_name' => 'B', 'last_name' => 'One']);

        $countA = $this->withinTenant($tenantA, fn () => Employee::query()->where('employment_status', 'active')->count());
        $countB = $this->withinTenant($tenantB, fn () => Employee::query()->where('employment_status', 'active')->count());
        $this->assertNotSame($countA, $countB, 'guard: tenants must have different headcounts');

        $this->assertSame($countA, $this->dashboard($tenantA, $ownerA)['organization']['active_employees']);
        $this->assertSame($countB, $this->dashboard($tenantB, $ownerB)['organization']['active_employees']);
    }

    public function test_unauthorized_dashboard_is_empty_not_forbidden(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->memberWithRole($tenant, 'employee');

        // The whole endpoint is not permission-gated: an employee gets 200 with an
        // empty card set, never a 403 and never zero-valued cards.
        $body = $this->actingAs($employee)->withHeaders($this->tenantHeaders($tenant))
            ->getJson('/api/dashboard/company')->assertOk()->json();
        $this->assertSame([], $body['data']);
    }
}
