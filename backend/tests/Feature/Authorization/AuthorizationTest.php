<?php

namespace Tests\Feature\Authorization;

use App\Modules\Authorization\Models\Role;
use App\Modules\Authorization\Services\AccessService;
use App\Modules\Authorization\Services\RoleAssignmentService;
use App\Modules\Identity\Models\TenantMembership;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    /** Add a member to a tenant, optionally with a role. */
    private function addMember(Tenant $tenant, ?string $roleSlug = null): User
    {
        $user = $this->makeUser();

        $this->withinTenant($tenant, function () use ($user, $roleSlug) {
            TenantMembership::create(['user_id' => $user->id, 'status' => 'active']);
            if ($roleSlug !== null) {
                app(RoleAssignmentService::class)->assignBySlug($user, $roleSlug);
            }
        });

        return $user;
    }

    public function test_owner_can_view_company(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->actingAs($owner)
            ->withHeader('X-Tenant-Id', $tenant->id)
            ->getJson('/api/company')
            ->assertOk()
            ->assertJsonPath('id', $tenant->id);
    }

    public function test_member_without_permission_is_denied(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->addMember($tenant, 'employee'); // no management perms

        $this->actingAs($employee)
            ->withHeader('X-Tenant-Id', $tenant->id)
            ->getJson('/api/company')
            ->assertForbidden();
    }

    public function test_assigning_a_role_grants_access(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $user = $this->addMember($tenant, 'employee');

        // Before: denied.
        $this->actingAs($user)->withHeader('X-Tenant-Id', $tenant->id)
            ->getJson('/api/company')->assertForbidden();

        // Grant admin.
        $this->withinTenant($tenant, function () use ($user) {
            app(RoleAssignmentService::class)->assignBySlug($user, 'admin');
        });

        // After: allowed.
        $this->actingAs($user)->withHeader('X-Tenant-Id', $tenant->id)
            ->getJson('/api/company')->assertOk();
    }

    public function test_privilege_escalation_via_role_assignment_is_blocked(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $employee = $this->addMember($tenant, 'employee');
        $ownerRoleId = $this->withinTenant($tenant, fn () => Role::where('slug', 'owner')->value('id'));

        // An employee (no permission.assign) cannot grant themselves the owner role.
        $this->actingAs($employee)
            ->withHeader('X-Tenant-Id', $tenant->id)
            ->postJson('/api/role-assignments', [
                'user_id' => $employee->id,
                'role_id' => $ownerRoleId,
            ])
            ->assertForbidden();
    }

    public function test_company_scope_grant_does_not_leak_to_a_branch_only_grant(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $user = $this->addMember($tenant);

        $this->withinTenant($tenant, function () use ($user) {
            // Grant admin ONLY at a specific branch scope.
            app(RoleAssignmentService::class)->assignBySlug($user, 'admin', 'branch', 'branch-1');

            $access = app(AccessService::class);
            // Company-wide check must be false (branch grant is not company-wide).
            $this->assertFalse($access->has($user, 'company.view', 'company'));
            // Same permission IS granted within that branch scope.
            $this->assertTrue($access->has($user, 'company.view', 'branch', 'branch-1'));
            // And not in a different branch.
            $this->assertFalse($access->has($user, 'company.view', 'branch', 'branch-2'));
        });
    }

    public function test_owner_does_not_bypass_tenant_isolation(): void
    {
        [$ownerA, $tenantA] = $this->createCompanyWithOwner(['name' => 'Alpha']);
        [, $tenantB] = $this->createCompanyWithOwner(['name' => 'Beta']);

        // Even the Owner of A, acting with A's context, sees zero of B's data.
        $this->withinTenant($tenantA, function () use ($tenantB) {
            $this->assertSame(0, TenantMembership::query()
                ->where('tenant_id', $tenantB->id)->count());
        });
    }
}
