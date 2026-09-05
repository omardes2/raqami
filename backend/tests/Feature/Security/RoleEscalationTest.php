<?php

namespace Tests\Feature\Security;

use App\Modules\Authorization\Models\Role;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Sprint 10 hardening — vertical privilege-escalation guard on role assignment.
 * A non-owner holding permission.assign (e.g. admin) must not be able to grant
 * the owner role or any role carrying permissions beyond their own; the owner
 * and the internal bootstrap path may grant anything within the tenant.
 */
class RoleEscalationTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function roleId(Tenant $tenant, string $slug): string
    {
        return app(TenantContext::class)->runAs($tenant, fn () => Role::query()->where('slug', $slug)->value('id'));
    }

    public function test_admin_cannot_grant_owner_role(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $admin = $this->memberWithRole($tenant, 'admin');
        $target = $this->memberWithRole($tenant, 'employee');

        $this->actingAs($admin)->withHeaders($this->tenantHeaders($tenant))
            ->postJson('/api/role-assignments', [
                'user_id' => (string) $target->id,
                'role_id' => $this->roleId($tenant, 'owner'),
            ])
            ->assertForbidden();
    }

    public function test_admin_can_grant_a_role_within_its_authority(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $admin = $this->memberWithRole($tenant, 'admin');
        $target = $this->memberWithRole($tenant, 'employee');

        // hr-manager's permissions are a subset of admin's → allowed.
        $this->actingAs($admin)->withHeaders($this->tenantHeaders($tenant))
            ->postJson('/api/role-assignments', [
                'user_id' => (string) $target->id,
                'role_id' => $this->roleId($tenant, 'hr-manager'),
            ])
            ->assertCreated();
    }

    public function test_owner_may_grant_owner_role(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $target = $this->memberWithRole($tenant, 'employee');

        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->postJson('/api/role-assignments', [
                'user_id' => (string) $target->id,
                'role_id' => $this->roleId($tenant, 'owner'),
            ])
            ->assertCreated();
    }
}
