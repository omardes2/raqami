<?php

namespace Tests\Concerns;

use App\Modules\Billing\Models\BankAccount;
use App\Modules\Billing\Models\Coupon;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Services\InvoiceService;
use App\Modules\Billing\Services\SubscriptionManager;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Str;

/** Test helpers for the Sprint 2 billing domain (plans, coupons, subscriptions). */
trait InteractsWithBilling
{
    /** Platform-global plan (no tenant context needed). */
    protected function makePlan(array $overrides = []): Plan
    {
        return Plan::query()->create(array_merge([
            'name' => 'Business',
            'slug' => 'business-'.Str::lower(Str::random(6)),
            'status' => 'active',
            'visibility' => 'public',
            'monthly_price_minor' => 1999,
            'annual_price_minor' => 19990,
            'currency' => 'USD',
            'trial_days' => 14,
            'employee_limit' => null,
            'sort_order' => 0,
        ], $overrides));
    }

    protected function makeCoupon(array $overrides = []): Coupon
    {
        return Coupon::query()->create(array_merge([
            'code' => Coupon::normalizeCode('SAVE'.Str::upper(Str::random(4))),
            'name' => 'Promo',
            'type' => 'percentage',
            'percentage' => 20,
            'status' => 'active',
            'redeemed_count' => 0,
        ], $overrides));
    }

    protected function makeBankAccount(array $overrides = []): BankAccount
    {
        return BankAccount::query()->create(array_merge([
            'label' => 'Primary USD',
            'bank_name' => 'Global Bank',
            'account_holder' => 'Raqmi Dawam',
            'account_number' => 'US00 1234 5678',
            'currency' => 'USD',
            'status' => 'active',
            'instructions' => 'Include the invoice number as reference.',
            'internal_notes' => 'ops-only note',
        ], $overrides));
    }

    /** Start a subscription for a tenant via the real service (in tenant context). */
    protected function subscribeTenant(Tenant $tenant, Plan $plan, array $opts = []): Subscription
    {
        return $this->withinTenant($tenant, fn () => app(SubscriptionManager::class)
            ->start($plan, $opts['interval'] ?? 'monthly', $opts));
    }

    /** Create an issued invoice for a tenant via the real service (in tenant context). */
    protected function makeInvoice(Tenant $tenant, array $data = []): Invoice
    {
        return $this->withinTenant($tenant, fn () => app(InvoiceService::class)->create(array_merge([
            'currency' => 'USD',
            'items' => [['description' => 'Business (monthly)', 'quantity' => 1, 'unit_amount_minor' => 1999]],
            'issue' => true,
        ], $data)));
    }
}
