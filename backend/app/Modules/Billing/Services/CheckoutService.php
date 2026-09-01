<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Models\SubscriptionChange;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates tenant checkout: selecting a plan starts the subscription and,
 * for a paid period, issues an invoice (optionally applying a coupon). Plan
 * UPGRADES and REACTIVATIONS are payment-gated — a pending subscription_change
 * is linked to the issued invoice and applied by PaymentService only when that
 * invoice is fully paid. All money is computed by InvoiceService. No card
 * provider is involved (invoices settle via bank transfer / manual).
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
     * @param  array{interval?:string, trial?:bool, coupon_code?:?string}  $opts
     * @return array{subscription:Subscription, invoice:?Invoice, change:?SubscriptionChange}
     */
    public function subscribe(Plan $plan, array $opts, User $actor): array
    {
        $interval = $opts['interval'] ?? 'monthly';

        return DB::transaction(function () use ($plan, $interval, $opts, $actor) {
            $subscription = $this->subscriptions->start($plan, $interval, $opts, $actor);

            $invoice = $subscription->onTrial()
                ? null
                : $this->issueInvoiceForPlan($subscription, $plan, $opts, $actor);

            return ['subscription' => $subscription, 'invoice' => $invoice, 'change' => null];
        });
    }

    /**
     * Change plan. An upgrade records a pending change and issues an invoice for
     * the TARGET plan (applied on full payment); a downgrade is scheduled with no
     * invoice.
     *
     * @return array{subscription:Subscription, invoice:?Invoice, change:SubscriptionChange}
     */
    public function changePlan(Subscription $subscription, Plan $toPlan, ?string $interval, array $opts, User $actor): array
    {
        return DB::transaction(function () use ($subscription, $toPlan, $interval, $opts, $actor) {
            $change = $this->subscriptions->changePlan($subscription, $toPlan, $interval, $actor);

            $invoice = null;
            if ($change->change_type === 'upgrade') {
                $invoice = $this->issueInvoiceForPlan($subscription, $toPlan, $opts, $actor, $change);
            }

            return ['subscription' => $subscription->fresh('plan'), 'invoice' => $invoice, 'change' => $change];
        });
    }

    /**
     * Reactivate a terminal subscription with an explicit new purchase (no trial;
     * payment required). Applied on full payment of the linked invoice.
     *
     * @return array{subscription:Subscription, invoice:Invoice, change:SubscriptionChange}
     */
    public function reactivate(Subscription $subscription, Plan $toPlan, ?string $interval, array $opts, User $actor): array
    {
        return DB::transaction(function () use ($subscription, $toPlan, $interval, $opts, $actor) {
            $change = $this->subscriptions->requestReactivation($subscription, $toPlan, $interval, $actor);
            $invoice = $this->issueInvoiceForPlan($subscription, $toPlan, $opts, $actor, $change);

            return ['subscription' => $subscription->fresh(), 'invoice' => $invoice, 'change' => $change];
        });
    }

    /** Issue an invoice for the subscription's CURRENT plan/period (e.g. pay now). */
    public function issuePlanInvoice(Subscription $subscription, array $opts, User $actor): Invoice
    {
        return $this->issueInvoiceForPlan($subscription, $subscription->plan, $opts, $actor);
    }

    /**
     * Issue an invoice for a specific plan, optionally linking it to a pending
     * subscription_change (upgrade/reactivation) and applying a coupon.
     *
     * @param  array{coupon_code?:?string}  $opts
     */
    private function issueInvoiceForPlan(Subscription $subscription, Plan $plan, array $opts, User $actor, ?SubscriptionChange $change = null): Invoice
    {
        $interval = $subscription->billing_interval->value;
        $currency = $subscription->currency;
        $unit = $plan->priceMinorFor($interval);

        return DB::transaction(function () use ($subscription, $plan, $interval, $currency, $unit, $opts, $actor, $change) {
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

            if ($change !== null) {
                $change->invoice_id = $invoice->getKey();
                $change->save();
            }

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
