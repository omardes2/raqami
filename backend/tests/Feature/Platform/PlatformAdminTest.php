<?php

namespace Tests\Feature\Platform;

use App\Modules\Platform\Models\PlatformAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class PlatformAdminTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_platform_admin_can_log_in(): void
    {
        $admin = PlatformAdmin::factory()->create(['password' => Hash::make('Password123!')]);

        $this->postJson('/api/platform/login', [
            'email' => $admin->email,
            'password' => 'Password123!',
        ])->assertOk()->assertJsonPath('is_platform_admin', true);
    }

    public function test_tenant_user_cannot_access_platform_routes(): void
    {
        [$owner] = $this->createCompanyWithOwner();

        // A fully authenticated TENANT user is still not a platform admin.
        $this->actingAs($owner)
            ->getJson('/api/platform/tenants')
            ->assertForbidden();
    }

    public function test_platform_admin_is_not_a_tenant_rbac_role(): void
    {
        $admin = PlatformAdmin::factory()->create();

        // Authenticated on the platform guard, the admin cannot use tenant
        // endpoints (which require the sanctum/web user guard).
        $this->actingAs($admin, 'platform')
            ->getJson('/api/company')
            ->assertUnauthorized();
    }

    public function test_platform_admin_can_list_tenants_with_counts(): void
    {
        $this->createCompanyWithOwner(['name' => 'Alpha']);
        $this->createCompanyWithOwner(['name' => 'Beta']);
        $admin = PlatformAdmin::factory()->create();

        $this->actingAs($admin, 'platform')
            ->getJson('/api/platform/tenants')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.members_count', 1);
    }
}
