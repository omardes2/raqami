<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates tenant checkout: selecting a plan starts the subscription and,
 * for a paid (non-trial) period, issues an invoice — optionally applying a
 * coupon. Keeps controllers thin; all money is computed by InvoiceService. No
 * card provider is involved (invoices are settled via bank transfer / manual).
 *
 * @phpstan-type CheckoutResult array{subscription:Subscription, invoice:?Invoice}
 */
class CheckoutService
{
    public function __construct(
        private readonly SubscriptionManager $subscriptions,
        private readonly InvoiceService $invoices,
        private readonly CouponService $coupons,
    ) {}

    /**
     * Start the tenant's subscription for a plan. Trials incur no invoice; a
     * non-trial start issues an invoice for the first period.
     *
     * @param  array{interval?:string, currency?:string, trial?:bool, coupon_code?:?string}  $opts
     * @return array{subscription:Subscription, invoice:?Invoice}
     */
    public function subscribe(Plan $plan, array $opts, User $actor): array
    {
        $interval = $opts['interval'] ?? 'monthly';

        return DB::transaction(function () use ($plan, $interval, $opts, $actor) {
            $subscription = $this->subscriptions->start($plan, $interval, $opts, $actor);

            $invoice = $subscription->onTrial()
                ? null
                : $this->issuePlanInvoice($subscription, $opts, $actor);

            return ['subscription' => $subscription, 'invoice' => $invoice];
        });
    }

    /**
     * Issue an invoice for the subscription's current plan/interval/period,
     * optionally applying a coupon. Returns the issued invoice.
     *
     * @param  array{coupon_code?:?string}  $opts
     */
    public function issuePlanInvoice(Subscription $subscription, array $opts, User $actor): Invoice
    {
        $plan = $subscription->plan;
        $interval = $subscription->billing_interval->value;
        $currency = $subscription->currency;
        $unit = $plan->priceMinorFor($interval);

        return DB::transaction(function () use ($subscription, $plan, $interval, $currency, $unit, $opts, $actor) {
            $couponCode = $opts['coupon_code'] ?? null;
            $discount = 0;
            $coupon = null;
            if ($couponCode) {
                $coupon = $this->coupons->validate($couponCode, $plan, $currency);
                $discount = $this->coupons->computeDiscount($coupon, $unit);
            }

            $invoice = $this->invoices->create([
                'currency' => $currency,
                'subscription_id' => $subscription->getKey(),
                'items' => [[
                    'description' => __('billing.invoice_line_plan', ['plan' => $plan->name, 'interval' => $interval]),
                    'quantity' => 1,
                    'unit_amount_minor' => $unit,
                    'metadata' => ['plan_id' => $plan->getKey(), 'interval' => $interval],
                ]],
                'discount_minor' => $discount,
                'coupon_id' => $coupon?->getKey(),
                'coupon_code' => $coupon?->code,
                'due_at' => now()->addDays((int) config('billing.invoice_due_days', 7)),
                'billing_period_start' => $subscription->current_period_start,
                'billing_period_end' => $subscription->current_period_end,
                'issue' => true,
            ]);

            if ($coupon) {
                $this->coupons->redeem($coupon, $unit, [
                    'subscription_id' => $subscription->getKey(),
                    'invoice_id' => $invoice->getKey(),
                ], $actor);
            }

            return $invoice;
        });
    }
}
