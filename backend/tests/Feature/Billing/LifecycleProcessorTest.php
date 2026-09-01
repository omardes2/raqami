<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Enums\BillingInterval;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Models\SubscriptionChange;
use App\Modules\Billing\Services\SubscriptionLifecycleProcessor;
use App\Modules\Billing\Services\SubscriptionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithBilling;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/** Idempotent scheduled-lifecycle processing (spec §10). */
class LifecycleProcessorTest extends TestCase
{
    use InteractsWithBilling;
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function process(): array
    {
        return app(SubscriptionLifecycleProcessor::class)->processDue();
    }

    public function test_due_trial_expires(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $sub = $this->subscribeTenant($tenant, $this->makePlan(['trial_days' => 14]));
        $this->withinTenant($tenant, fn () => Subscription::query()->whereKey($sub->id)->update(['trial_ends_at' => now()->subDay()]));

        $result = $this->process();

        $this->assertSame(1, $result['trials_expired']);
        $this->assertSame('expired', $this->withinTenant($tenant, fn () => Subscription::query()->first())->status->value);
    }

    public function test_not_due_trial_is_untouched_and_run_is_idempotent(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $this->subscribeTenant($tenant, $this->makePlan(['trial_days' => 14])); // ends in the future

        $this->assertSame(0, $this->process()['trials_expired']);
        $this->assertSame('trialing', $this->withinTenant($tenant, fn () => Subscription::query()->first())->status->value);
        // Second run also a no-op.
        $this->assertSame(0, $this->process()['trials_expired']);
    }

    public function test_due_grace_suspends(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $sub = $this->subscribeTenant($tenant, $this->makePlan(['trial_days' => 0]), ['trial' => false]);
        $this->withinTenant($tenant, function () use ($sub) {
            $m = app(SubscriptionManager::class);
            $m->markPastDue($sub);
            $m->enterGracePeriod($sub);
            Subscription::query()->whereKey($sub->id)->update(['grace_ends_at' => now()->subDay()]);
        });

        $this->assertSame(1, $this->process()['grace_suspended']);
        $this->assertSame('suspended', $this->withinTenant($tenant, fn () => Subscription::query()->first())->status->value);
    }

    public function test_due_scheduled_cancellation_cancels(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $sub = $this->subscribeTenant($tenant, $this->makePlan(['trial_days' => 0]), ['trial' => false]);
        $this->withinTenant($tenant, function () use ($sub) {
            app(SubscriptionManager::class)->scheduleCancellation($sub);
            Subscription::query()->whereKey($sub->id)->update(['current_period_end' => now()->subDay()]);
        });

        $this->assertSame(1, $this->process()['cancellations']);
        $this->assertSame('canceled', $this->withinTenant($tenant, fn () => Subscription::query()->first())->status->value);
    }

    public function test_due_scheduled_downgrade_applies(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $big = $this->makePlan(['name' => 'Big', 'monthly_price_minor' => 5000, 'trial_days' => 0]);
        $small = $this->makePlan(['name' => 'Small', 'monthly_price_minor' => 1000, 'trial_days' => 0]);
        $sub = $this->subscribeTenant($tenant, $big, ['trial' => false]);
        $this->withinTenant($tenant, function () use ($sub, $small) {
            $change = app(SubscriptionManager::class)->scheduleDowngrade($sub, $small, BillingInterval::Monthly);
            SubscriptionChange::query()->whereKey($change->id)->update(['effective_at' => now()->subDay()]);
        });

        $this->assertSame(1, $this->process()['downgrades']);
        $this->assertSame($small->id, $this->withinTenant($tenant, fn () => Subscription::query()->first())->plan_id);
        $this->assertSame('applied', $this->withinTenant($tenant, fn () => SubscriptionChange::query()->first())->status);
    }
}
