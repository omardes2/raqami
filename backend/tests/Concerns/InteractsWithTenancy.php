<?php

namespace Tests\Concerns;

use App\Modules\Authorization\Services\PermissionSync;
use App\Modules\Authorization\Services\RoleAssignmentService;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Identity\Models\TenantMembership;
use App\Modules\Identity\Models\User;
use App\Modules\Onboarding\Services\CompanyOnboardingService;
use App\Modules\Organization\Models\Branch;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Team;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantContext;

/** Test helpers for building tenants, owners, and members. */
trait InteractsWithTenancy
{
    protected function seedPermissions(): void
    {
        app(PermissionSync::class)->sync();
    }

    protected function makeUser(array $attributes = []): User
    {
        return User::factory()->create($attributes);
    }

    /** @return array{0:User,1:Tenant} */
    protected function createCompanyWithOwner(array $companyData = [], array $userAttributes = []): array
    {
        $this->seedPermissions();
        $owner = $this->makeUser($userAttributes);

        $tenant = app(CompanyOnboardingService::class)->createCompany(
            $owner,
            array_merge(['name' => 'Acme Co'], $companyData),
        );

        app(TenantContext::class)->clear();

        return [$owner, $tenant];
    }

    protected function withinTenant(Tenant $tenant, callable $callback): mixed
    {
        return app(TenantContext::class)->runAs($tenant, fn () => $callback());
    }

    /**
     * Create a member user of $tenant and assign a system role at a scope.
     * Returns the user (usable with actingAs + the X-Tenant-Id header).
     */
    protected function memberWithRole(
        Tenant $tenant,
        string $roleSlug,
        string $scopeType = 'company',
        ?string $scopeId = null,
        array $userAttributes = [],
    ): User {
        $user = $this->makeUser($userAttributes);

        $this->withinTenant($tenant, function () use ($user, $roleSlug, $scopeType, $scopeId) {
            TenantMembership::create([
                'user_id' => $user->id,
                'status' => 'active',
            ]);
            app(RoleAssignmentService::class)
                ->assignBySlug($user, $roleSlug, $scopeType, $scopeId);
        });

        return $user;
    }

    /** Build an employee inside a tenant via the real service. */
    protected function makeEmployee(Tenant $tenant, array $attributes = []): Employee
    {
        return $this->withinTenant($tenant, fn () => app(EmployeeService::class)
            ->create(array_merge(['first_name' => 'Test', 'last_name' => 'Employee'], $attributes)));
    }

    protected function makeBranch(Tenant $tenant, array $attributes = []): Branch
    {
        return $this->withinTenant($tenant, fn () => Branch::factory()->create($attributes));
    }

    protected function makeDepartment(Tenant $tenant, array $attributes = []): Department
    {
        return $this->withinTenant($tenant, fn () => Department::factory()->create($attributes));
    }

    protected function makeTeam(Tenant $tenant, array $attributes = []): Team
    {
        return $this->withinTenant($tenant, fn () => Team::factory()->create($attributes));
    }

    /** Header helper for acting within a specific tenant via the API. */
    protected function tenantHeaders(Tenant $tenant): array
    {
        return ['X-Tenant-Id' => $tenant->id];
    }
}
