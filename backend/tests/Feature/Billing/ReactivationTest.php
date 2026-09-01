<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Services\SubscriptionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithBilling;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/** Terminal subscriptions can be reactivated via an explicit paid purchase (spec §6). */
class ReactivationTest extends TestCase
{
    use InteractsWithBilling;
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function terminalTenant(string $how): array
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $plan = $this->makePlan(['trial_days' => 0, 'monthly_price_minor' => 3000]);
        $sub = $this->subscribeTenant($tenant, $plan, ['trial' => false]);
        $this->withinTenant($tenant, fn () => $how === 'canceled'
            ? app(SubscriptionManager::class)->cancelNow($sub)
            : app(SubscriptionManager::class)->expire($sub));

        return [$owner, $tenant, $plan];
    }

    private function requestReactivation($owner, $tenant, $plan): string
    {
        return $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->postJson('/api/billing/subscription', ['plan_id' => $plan->id, 'interval' => 'monthly'])
            ->assertCreated()
            ->json('invoice.id');
    }

    public function test_canceled_tenant_can_reactivate_via_payment(): void
    {
        [$owner, $tenant, $plan] = $this->terminalTenant('canceled');
        $invoiceId = $this->requestReactivation($owner, $tenant, $plan);
        $this->assertNotNull($invoiceId); // payment required

        // Not usable until paid.
        $before = $this->withinTenant($tenant, fn () => Subscription::query()->first());
        $this->assertFalse($before->isUsable());

        $invoice = $this->withinTenant($tenant, fn () => Invoice::query()->findOrFail($invoiceId));
        $this->payInvoiceFully($tenant, $invoice);

        $after = $this->withinTenant($tenant, fn () => Subscription::query()->first());
        $this->assertSame('active', $after->status->value);
    }

    public function test_expired_tenant_can_reactivate(): void
    {
        [$owner, $tenant, $plan] = $this->terminalTenant('expired');
        $invoiceId = $this->requestReactivation($owner, $tenant, $plan);
        $invoice = $this->withinTenant($tenant, fn () => Invoice::query()->findOrFail($invoiceId));
        $this->payInvoiceFully($tenant, $invoice);

        $this->assertSame('active', $this->withinTenant($tenant, fn () => Subscription::query()->first())->status->value);
    }

    public function test_reactivation_does_not_start_a_new_free_trial(): void
    {
        [$owner, $tenant, $plan] = $this->terminalTenant('canceled');
        $this->requestReactivation($owner, $tenant, $plan);

        // A pending reactivation never puts the subscription back into trialing.
        $sub = $this->withinTenant($tenant, fn () => Subscription::query()->first());
        $this->assertNotSame('trialing', $sub->status->value);
    }

    public function test_unpaid_reactivation_grants_no_entitlement(): void
    {
        [$owner, $tenant, $plan] = $this->terminalTenant('canceled');
        $this->requestReactivation($owner, $tenant, $plan);

        // Still fail-closed until the reactivation invoice is paid.
        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->postJson('/api/employees', ['first_name' => 'X', 'last_name' => 'Y'])
            ->assertStatus(422);
    }

    public function test_live_subscription_cannot_be_recreated(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $plan = $this->makePlan(['trial_days' => 0]);
        $this->subscribeTenant($tenant, $plan, ['trial' => false]); // active (non-terminal)

        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->postJson('/api/billing/subscription', ['plan_id' => $plan->id, 'interval' => 'monthly'])
            ->assertStatus(422);
    }
}
