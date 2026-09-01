<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Models\Plan;
use App\Modules\Platform\Models\PlatformAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithBilling;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class PlanTest extends TestCase
{
    use InteractsWithBilling;
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_platform_admin_creates_plan_with_minor_unit_prices(): void
    {
        $admin = PlatformAdmin::factory()->create();

        $this->actingAs($admin, 'platform')->postJson('/api/platform/plans', [
            'name' => 'Starter',
            'slug' => 'starter',
            'status' => 'active',
            'visibility' => 'public',
            'monthly_price_minor' => 1999,
            'annual_price_minor' => 19990,
            'currency' => 'USD',
            'trial_days' => 14,
            'employee_limit' => 10,
        ])->assertCreated()
            ->assertJsonPath('monthly_price_minor', 1999)
            ->assertJsonPath('employee_limit', 10);

        $this->assertSame(1999, Plan::query()->where('slug', 'starter')->first()->monthly_price_minor);
    }

    public function test_plan_feature_limits_are_configurable(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $plan = $this->makePlan();

        $this->actingAs($admin, 'platform')
            ->postJson("/api/platform/plans/{$plan->id}/features", [
                'feature_key' => 'reports',
                'enabled' => true,
                'limit_value' => 5,
            ])->assertCreated()
            ->assertJsonPath('feature_key', 'reports')
            ->assertJsonPath('limit_value', 5);
    }

    public function test_tenant_catalog_only_shows_active_public_plans(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $this->makePlan(['name' => 'Public', 'status' => 'active', 'visibility' => 'public']);
        $this->makePlan(['name' => 'Draft', 'status' => 'draft', 'visibility' => 'public']);
        $this->makePlan(['name' => 'Private', 'status' => 'active', 'visibility' => 'private']);

        $data = $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->getJson('/api/billing/plans')->assertOk()->json('data');

        $names = collect($data)->pluck('name')->all();
        $this->assertContains('Public', $names);
        $this->assertNotContains('Draft', $names);
        $this->assertNotContains('Private', $names);
    }

    public function test_draft_plan_cannot_be_subscribed(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $draft = $this->makePlan(['status' => 'draft']);

        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->postJson('/api/billing/subscription', ['plan_id' => $draft->id, 'interval' => 'monthly'])
            ->assertStatus(422);
    }
}
