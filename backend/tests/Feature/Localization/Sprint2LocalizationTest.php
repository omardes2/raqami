<?php

namespace Tests\Feature\Localization;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithBilling;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class Sprint2LocalizationTest extends TestCase
{
    use InteractsWithBilling;
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_billing_messages_are_localized_ar_and_en(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $plan = $this->makePlan(['trial_days' => 0]);

        // An invalid coupon triggers a billing validation error in each locale.
        $arOwner = $this->memberWithRole($tenant, 'admin', 'company', null, ['locale' => 'ar']);
        $ar = $this->actingAs($arOwner)->withHeaders($this->tenantHeaders($tenant))
            ->postJson('/api/billing/subscription', [
                'plan_id' => $plan->id, 'interval' => 'monthly', 'trial' => false, 'coupon_code' => 'BADCODE',
            ])->assertStatus(422)->json('errors.coupon_code.0');
        $this->assertStringContainsString('القسيمة', $ar);

        $enOwner = $this->memberWithRole($tenant, 'admin', 'company', null, ['locale' => 'en']);
        $en = $this->actingAs($enOwner)->withHeaders($this->tenantHeaders($tenant))
            ->postJson('/api/billing/subscription', [
                'plan_id' => $plan->id, 'interval' => 'monthly', 'trial' => false, 'coupon_code' => 'BADCODE',
            ])->assertStatus(422)->json('errors.coupon_code.0');
        $this->assertStringContainsString('coupon', $en);
    }
}
