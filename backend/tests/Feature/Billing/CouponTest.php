<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Models\Coupon;
use App\Modules\Billing\Models\CouponRedemption;
use App\Modules\Billing\Services\CouponService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithBilling;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class CouponTest extends TestCase
{
    use InteractsWithBilling;
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function subscribeWithCoupon($owner, $tenant, $plan, string $code)
    {
        return $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->postJson('/api/billing/subscription', [
                'plan_id' => $plan->id, 'interval' => 'monthly', 'trial' => false, 'coupon_code' => $code,
            ]);
    }

    public function test_percentage_coupon_discounts_the_invoice(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $plan = $this->makePlan(['trial_days' => 0, 'monthly_price_minor' => 1000]);
        $coupon = $this->makeCoupon(['type' => 'percentage', 'percentage' => 20]);

        $this->subscribeWithCoupon($owner, $tenant, $plan, $coupon->code)
            ->assertCreated()
            ->assertJsonPath('invoice.discount_minor', 200)
            ->assertJsonPath('invoice.total_minor', 800);

        $this->assertSame(1, Coupon::query()->find($coupon->id)->redeemed_count);
    }

    public function test_fixed_amount_coupon_discounts_the_invoice(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $plan = $this->makePlan(['trial_days' => 0, 'monthly_price_minor' => 1000]);
        $coupon = $this->makeCoupon(['type' => 'fixed_amount', 'percentage' => null, 'amount_minor' => 300, 'currency' => 'USD']);

        $this->subscribeWithCoupon($owner, $tenant, $plan, $coupon->code)
            ->assertCreated()
            ->assertJsonPath('invoice.discount_minor', 300)
            ->assertJsonPath('invoice.total_minor', 700);
    }

    public function test_expired_coupon_is_rejected(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $plan = $this->makePlan(['trial_days' => 0]);
        $coupon = $this->makeCoupon(['ends_at' => now()->subDay()]);

        $this->subscribeWithCoupon($owner, $tenant, $plan, $coupon->code)->assertStatus(422);
    }

    public function test_not_yet_started_coupon_is_rejected(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $plan = $this->makePlan(['trial_days' => 0]);
        $coupon = $this->makeCoupon(['starts_at' => now()->addDay()]);

        $this->subscribeWithCoupon($owner, $tenant, $plan, $coupon->code)->assertStatus(422);
    }

    public function test_exhausted_coupon_is_rejected(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $plan = $this->makePlan(['trial_days' => 0]);
        $coupon = $this->makeCoupon(['max_redemptions' => 1, 'redeemed_count' => 1]);

        $this->subscribeWithCoupon($owner, $tenant, $plan, $coupon->code)->assertStatus(422);
    }

    public function test_plan_restricted_coupon_rejects_other_plans(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $planA = $this->makePlan(['name' => 'A', 'trial_days' => 0]);
        $planB = $this->makePlan(['name' => 'B', 'trial_days' => 0]);
        $coupon = $this->makeCoupon(['applicable_plan_id' => $planA->id]);

        $this->subscribeWithCoupon($owner, $tenant, $planB, $coupon->code)->assertStatus(422);
    }

    public function test_per_tenant_limit_is_enforced(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $coupon = $this->makeCoupon(['per_tenant_limit' => 1]);

        $this->withinTenant($tenant, function () use ($coupon) {
            // Record one redemption for this tenant, then a second validation fails.
            CouponRedemption::query()->create([
                'coupon_id' => $coupon->id, 'coupon_code' => $coupon->code, 'discount_minor' => 100,
            ]);

            $this->expectException(ValidationException::class);
            app(CouponService::class)->validate($coupon->code);
        });
    }
}
