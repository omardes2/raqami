<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Enums\BillingInterval;
use App\Modules\Billing\Enums\PaymentMethod;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Models\SubscriptionChange;
use App\Modules\Billing\Services\PaymentService;
use App\Modules\Billing\Services\SubscriptionManager;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithBilling;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/** Upgrade is payment-gated; downgrade stays scheduled (spec §2, §12). */
class UpgradeDowngradeTest extends TestCase
{
    use InteractsWithBilling;
    use InteractsWithTenancy;
    use RefreshDatabase;

    /** @return array{0:mixed,1:Tenant,2:Plan,3:Plan} */
    private function setup2Plans(): array
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $starter = $this->makePlan(['name' => 'Starter', 'monthly_price_minor' => 1000, 'trial_days' => 0]);
        $business = $this->makePlan(['name' => 'Business', 'monthly_price_minor' => 5000, 'trial_days' => 0]);
        $this->subscribeTenant($tenant, $starter, ['trial' => false]);

        return [$owner, $tenant, $starter, $business];
    }

    private function requestUpgrade($owner, $tenant, $business): string
    {
        return $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->postJson('/api/billing/subscription/change-plan', ['plan_id' => $business->id])
            ->assertOk()
            ->assertJsonPath('change.change_type', 'upgrade')
            ->assertJsonPath('change.status', 'pending')
            ->json('invoice.id');
    }

    public function test_unpaid_upgrade_does_not_change_plan(): void
    {
        [$owner, $tenant, $starter, $business] = $this->setup2Plans();
        $this->requestUpgrade($owner, $tenant, $business);

        $sub = $this->withinTenant($tenant, fn () => Subscription::query()->first());
        $this->assertSame($starter->id, $sub->plan_id);
    }

    public function test_partial_payment_does_not_apply_upgrade(): void
    {
        [$owner, $tenant, $starter, $business] = $this->setup2Plans();
        $invoiceId = $this->requestUpgrade($owner, $tenant, $business);

        $this->withinTenant($tenant, function () use ($invoiceId) {
            $invoice = Invoice::query()->findOrFail($invoiceId);
            app(PaymentService::class)->applyToInvoice($invoice, ['amount_minor' => 2000, 'method' => PaymentMethod::Manual]);
        });

        $sub = $this->withinTenant($tenant, fn () => Subscription::query()->first());
        $this->assertSame($starter->id, $sub->plan_id); // still not upgraded
        $change = $this->withinTenant($tenant, fn () => SubscriptionChange::query()->first());
        $this->assertSame('pending', $change->status);
    }

    public function test_full_payment_applies_upgrade(): void
    {
        [$owner, $tenant, $starter, $business] = $this->setup2Plans();
        $invoiceId = $this->requestUpgrade($owner, $tenant, $business);
        $invoice = $this->withinTenant($tenant, fn () => Invoice::query()->findOrFail($invoiceId));
        $this->payInvoiceFully($tenant, $invoice);

        $sub = $this->withinTenant($tenant, fn () => Subscription::query()->first());
        $this->assertSame($business->id, $sub->plan_id);
        $this->assertSame('active', $sub->status->value);
        $change = $this->withinTenant($tenant, fn () => SubscriptionChange::query()->first());
        $this->assertSame('applied', $change->status);
    }

    public function test_upgrade_cannot_be_applied_twice(): void
    {
        [$owner, $tenant, $starter, $business] = $this->setup2Plans();
        $invoiceId = $this->requestUpgrade($owner, $tenant, $business);
        $invoice = $this->withinTenant($tenant, fn () => Invoice::query()->findOrFail($invoiceId));
        $this->payInvoiceFully($tenant, $invoice);

        // A re-run of the applier is a no-op (change already applied).
        $this->withinTenant($tenant, function () use ($invoiceId) {
            $inv = Invoice::query()->findOrFail($invoiceId);
            app(SubscriptionManager::class)->applyPendingChangeForInvoice($inv);
        });

        $applied = $this->withinTenant($tenant, fn () => SubscriptionChange::query()->where('status', 'applied')->count());
        $this->assertSame(1, $applied);
    }

    public function test_downgrade_remains_scheduled_with_no_invoice(): void
    {
        [$owner, $tenant, $starter, $business] = $this->setup2Plans();
        // Move to business first (so downgrade to starter is cheaper). Do it via
        // the service to avoid the payment dance.
        $this->withinTenant($tenant, function () use ($business) {
            $sub = Subscription::query()->first();
            app(SubscriptionManager::class)->requestUpgrade($sub, $business, BillingInterval::Monthly);
            $sub->update(['plan_id' => $business->id]); // simulate applied upgrade for the downgrade test
        });

        $response = $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->postJson('/api/billing/subscription/change-plan', ['plan_id' => $starter->id])
            ->assertOk()
            ->assertJsonPath('change.change_type', 'downgrade')
            ->assertJsonPath('change.status', 'scheduled');
        $this->assertNull($response->json('invoice'));
    }
}
