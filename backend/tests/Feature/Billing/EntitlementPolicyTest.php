<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Services\SubscriptionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithBilling;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/** Fail-closed entitlements + default-trial onboarding (spec §1). */
class EntitlementPolicyTest extends TestCase
{
    use InteractsWithBilling;
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function createEmployee($owner, $tenant)
    {
        return $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->postJson('/api/employees', ['first_name' => 'A', 'last_name' => 'B']);
    }

    public function test_onboarding_starts_a_trial_from_the_default_trial_plan(): void
    {
        // A default trial plan configured BEFORE onboarding bootstraps a trial.
        $this->seedPermissions();
        $this->makePlan(['name' => 'Trial', 'is_default_trial' => true, 'trial_days' => 14, 'employee_limit' => 25]);

        [$owner, $tenant] = $this->createCompanyWithOwner();

        $sub = $this->withinTenant($tenant, fn () => Subscription::query()->first());
        $this->assertNotNull($sub);
        $this->assertSame('trialing', $sub->status->value);
        // The active trial grants product entitlement.
        $this->createEmployee($owner, $tenant)->assertCreated();
    }

    public function test_no_default_trial_plan_means_no_subscription_and_fail_closed(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->assertNull($this->withinTenant($tenant, fn () => Subscription::query()->first()));
        $this->createEmployee($owner, $tenant)->assertStatus(422);
    }

    public function test_expired_trial_does_not_grant_entitlement(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $sub = $this->subscribeTenant($tenant, $this->makePlan(['trial_days' => 14]));
        $this->withinTenant($tenant, fn () => app(SubscriptionManager::class)->expire($sub, 'trial_expired'));

        $this->createEmployee($owner, $tenant)->assertStatus(422);
    }

    public function test_suspended_subscription_does_not_grant_entitlement(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $sub = $this->subscribeTenant($tenant, $this->makePlan(['trial_days' => 0]), ['trial' => false]);
        $this->withinTenant($tenant, function () use ($sub) {
            $m = app(SubscriptionManager::class);
            $m->markPastDue($sub);
            $m->suspend($sub);
        });

        $this->createEmployee($owner, $tenant)->assertStatus(422);
    }

    public function test_grace_period_grants_entitlement(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $sub = $this->subscribeTenant($tenant, $this->makePlan(['trial_days' => 0]), ['trial' => false]);
        $this->withinTenant($tenant, function () use ($sub) {
            $m = app(SubscriptionManager::class);
            $m->markPastDue($sub);
            $m->enterGracePeriod($sub);
        });

        $this->createEmployee($owner, $tenant)->assertCreated();
    }

    public function test_billing_pages_reachable_without_usable_subscription(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner(); // no subscription

        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->getJson('/api/billing/overview')->assertOk();
        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->getJson('/api/billing/plans')->assertOk();
    }
}
