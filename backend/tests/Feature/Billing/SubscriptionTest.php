<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Enums\SubscriptionStatus;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Models\SubscriptionChange;
use App\Modules\Billing\Models\SubscriptionEvent;
use App\Modules\Billing\Services\SubscriptionManager;
use App\Modules\Employees\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\InteractsWithBilling;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class SubscriptionTest extends TestCase
{
    use InteractsWithBilling;
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_subscribe_starts_a_trial_and_records_event(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $plan = $this->makePlan(['trial_days' => 14]);

        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->postJson('/api/billing/subscription', ['plan_id' => $plan->id, 'interval' => 'monthly'])
            ->assertCreated()
            ->assertJsonPath('subscription.status', 'trialing');

        $sub = $this->withinTenant($tenant, fn () => Subscription::query()->first());
        $this->assertNotNull($sub->trial_ends_at);
        $events = $this->withinTenant($tenant, fn () => SubscriptionEvent::query()->pluck('event_type')->all());
        $this->assertContains('trial_started', $events);
    }

    public function test_only_one_subscription_per_tenant(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $plan = $this->makePlan();
        $this->subscribeTenant($tenant, $plan);

        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->postJson('/api/billing/subscription', ['plan_id' => $plan->id, 'interval' => 'monthly'])
            ->assertStatus(422);
    }

    public function test_non_trial_subscribe_issues_an_invoice(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $plan = $this->makePlan(['trial_days' => 0, 'monthly_price_minor' => 4999]);

        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->postJson('/api/billing/subscription', ['plan_id' => $plan->id, 'interval' => 'monthly'])
            ->assertCreated()
            ->assertJsonPath('subscription.status', 'active')
            ->assertJsonPath('invoice.total_minor', 4999);
    }

    public function test_cancel_then_resume(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $plan = $this->makePlan();
        $this->subscribeTenant($tenant, $plan);

        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->postJson('/api/billing/subscription/cancel')->assertOk()
            ->assertJsonPath('data.cancel_at_period_end', true);

        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->postJson('/api/billing/subscription/resume')->assertOk()
            ->assertJsonPath('data.cancel_at_period_end', false);
    }

    public function test_upgrade_applies_immediately(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $starter = $this->makePlan(['name' => 'Starter', 'monthly_price_minor' => 1999]);
        $business = $this->makePlan(['name' => 'Business', 'monthly_price_minor' => 4999]);
        $this->subscribeTenant($tenant, $starter);

        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->postJson('/api/billing/subscription/change-plan', ['plan_id' => $business->id])
            ->assertOk()
            ->assertJsonPath('subscription.plan_id', $business->id)
            ->assertJsonPath('change.change_type', 'upgrade')
            ->assertJsonPath('change.status', 'applied');
    }

    public function test_downgrade_is_scheduled_and_deletes_no_data(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $business = $this->makePlan(['name' => 'Business', 'monthly_price_minor' => 4999]);
        $starter = $this->makePlan(['name' => 'Starter', 'monthly_price_minor' => 1999, 'employee_limit' => 1]);
        $this->subscribeTenant($tenant, $business);
        $this->makeEmployee($tenant, ['employee_number' => 'EMP-1']);
        $this->makeEmployee($tenant, ['employee_number' => 'EMP-2']);

        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->postJson('/api/billing/subscription/change-plan', ['plan_id' => $starter->id])
            ->assertOk()
            ->assertJsonPath('change.change_type', 'downgrade')
            ->assertJsonPath('change.status', 'scheduled');

        // Plan is NOT changed yet, and no employee was removed.
        $sub = $this->withinTenant($tenant, fn () => Subscription::query()->first());
        $this->assertSame($business->id, $sub->plan_id);
        $change = $this->withinTenant($tenant, fn () => SubscriptionChange::query()->first());
        $this->assertNotNull($change->metadata['over_cap_warning'] ?? null);
        $count = $this->withinTenant($tenant, fn () => Employee::query()->count());
        $this->assertSame(2, $count);
    }

    public function test_subscription_currency_follows_the_plan_not_the_client(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $plan = $this->makePlan(['trial_days' => 0, 'currency' => 'EUR', 'monthly_price_minor' => 5000]);

        // Even if a client crafts a mismatched currency, the invoice/subscription
        // currency must be the plan's currency (no client control, no FX).
        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->postJson('/api/billing/subscription', ['plan_id' => $plan->id, 'interval' => 'monthly', 'currency' => 'USD'])
            ->assertCreated()
            ->assertJsonPath('subscription.currency', 'EUR')
            ->assertJsonPath('invoice.currency', 'EUR')
            ->assertJsonPath('invoice.total_minor', 5000);
    }

    public function test_cross_currency_plan_change_is_rejected(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $usd = $this->makePlan(['name' => 'USD Plan', 'currency' => 'USD', 'monthly_price_minor' => 1000]);
        $eur = $this->makePlan(['name' => 'EUR Plan', 'currency' => 'EUR', 'monthly_price_minor' => 5000]);
        $this->subscribeTenant($tenant, $usd);

        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->postJson('/api/billing/subscription/change-plan', ['plan_id' => $eur->id])
            ->assertStatus(422);
    }

    public function test_invalid_transition_is_rejected(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $plan = $this->makePlan();
        $sub = $this->subscribeTenant($tenant, $plan);

        $this->withinTenant($tenant, function () use ($sub) {
            $manager = app(SubscriptionManager::class);
            $manager->cancelNow($sub);

            $this->expectException(RuntimeException::class);
            $manager->renew($sub); // canceled -> active is not allowed
        });
    }

    public function test_full_lifecycle_transitions(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $plan = $this->makePlan(['trial_days' => 0]);
        $sub = $this->subscribeTenant($tenant, $plan, ['trial' => false]);

        $this->withinTenant($tenant, function () use ($sub) {
            $manager = app(SubscriptionManager::class);
            $manager->markPastDue($sub);
            $this->assertSame(SubscriptionStatus::PastDue, $sub->fresh()->status);
            $manager->enterGracePeriod($sub);
            $this->assertSame(SubscriptionStatus::GracePeriod, $sub->fresh()->status);
            $this->assertNotNull($sub->fresh()->grace_ends_at);
            $manager->suspend($sub);
            $this->assertSame(SubscriptionStatus::Suspended, $sub->fresh()->status);
            $manager->activate($sub);
            $this->assertSame(SubscriptionStatus::Active, $sub->fresh()->status);
        });
    }
}
