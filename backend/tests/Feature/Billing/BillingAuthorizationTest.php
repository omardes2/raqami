<?php

namespace Tests\Feature\Billing;

use App\Modules\Platform\Models\PlatformAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithBilling;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class BillingAuthorizationTest extends TestCase
{
    use InteractsWithBilling;
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_owner_has_full_billing_access(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->getJson('/api/billing/overview')->assertOk();
    }

    public function test_accountant_can_read_but_not_change_subscription_or_profile(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $plan = $this->makePlan();
        $accountant = $this->memberWithRole($tenant, 'accountant');

        // Read access is granted.
        $this->actingAs($accountant)->withHeaders($this->tenantHeaders($tenant))
            ->getJson('/api/billing/invoices')->assertOk();
        $this->actingAs($accountant)->withHeaders($this->tenantHeaders($tenant))
            ->getJson('/api/billing/overview')->assertOk();

        // But cannot change the subscription or edit the billing profile.
        $this->actingAs($accountant)->withHeaders($this->tenantHeaders($tenant))
            ->postJson('/api/billing/subscription', ['plan_id' => $plan->id, 'interval' => 'monthly'])
            ->assertForbidden();
        $this->actingAs($accountant)->withHeaders($this->tenantHeaders($tenant))
            ->putJson('/api/billing/profile', ['legal_name' => 'X'])->assertForbidden();
    }

    public function test_hr_and_employee_roles_have_no_billing_access(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();

        foreach (['hr-manager', 'department-manager', 'team-leader', 'employee'] as $slug) {
            $user = $this->memberWithRole($tenant, $slug);
            $this->actingAs($user)->withHeaders($this->tenantHeaders($tenant))
                ->getJson('/api/billing/overview')->assertForbidden();
        }
    }

    public function test_tenant_user_cannot_reach_platform_billing(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->getJson('/api/platform/plans')->assertForbidden();
    }

    public function test_platform_billing_is_separate_from_tenant_rbac(): void
    {
        $this->createCompanyWithOwner();
        $admin = PlatformAdmin::factory()->create();

        // Platform admin manages plans without any tenant role.
        $this->actingAs($admin, 'platform')->getJson('/api/platform/plans')->assertOk();
    }
}
