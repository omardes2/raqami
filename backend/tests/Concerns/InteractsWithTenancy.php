<?php

namespace Tests\Concerns;

use App\Modules\Authorization\Services\PermissionSync;
use App\Modules\Identity\Models\User;
use App\Modules\Onboarding\Services\CompanyOnboardingService;
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
}
