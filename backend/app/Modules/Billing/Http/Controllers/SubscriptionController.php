<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Enums\PlanStatus;
use App\Modules\Billing\Enums\PlanVisibility;
use App\Modules\Billing\Http\Requests\ChangePlanRequest;
use App\Modules\Billing\Http\Requests\SubscribeRequest;
use App\Modules\Billing\Http\Resources\InvoiceResource;
use App\Modules\Billing\Http\Resources\SubscriptionResource;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Services\CheckoutService;
use App\Modules\Billing\Services\EntitlementService;
use App\Modules\Billing\Services\SubscriptionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tenant subscription management. All status changes go through
 * SubscriptionManager (never mutated here directly). The subscription belongs to
 * the tenant; access is permission-gated by the routes.
 */
class SubscriptionController extends Controller
{
    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly SubscriptionManager $manager,
        private readonly CheckoutService $checkout,
    ) {}

    public function show(): JsonResponse
    {
        $subscription = $this->entitlements->subscription();

        return response()->json([
            'data' => $subscription ? (new SubscriptionResource($subscription->loadMissing('plan')))->resolve() : null,
        ]);
    }

    public function subscribe(SubscribeRequest $request): JsonResponse
    {
        if (Subscription::query()->exists()) {
            return response()->json(['message' => __('billing.subscription_exists')], 422);
        }

        $plan = $this->purchasablePlan($request->validated('plan_id'));

        $result = $this->checkout->subscribe($plan, $request->validated(), $request->user());

        return response()->json([
            'subscription' => (new SubscriptionResource($result['subscription']->loadMissing('plan')))->resolve(),
            'invoice' => $result['invoice'] ? (new InvoiceResource($result['invoice']->loadMissing('items')))->resolve() : null,
        ], 201);
    }

    public function changePlan(ChangePlanRequest $request): JsonResponse
    {
        $subscription = $this->requireSubscription();
        $toPlan = $this->purchasablePlan($request->validated('plan_id'));

        $change = $this->manager->changePlan($subscription, $toPlan, $request->validated('interval'), $request->user());

        // Immediate upgrade with a price issues an invoice for the new plan.
        $invoice = null;
        $subscription->refresh();
        if ($change->status === 'applied' && $subscription->plan->priceMinorFor($subscription->billing_interval->value) > 0) {
            $invoice = $this->checkout->issuePlanInvoice($subscription, [], $request->user());
        }

        return response()->json([
            'subscription' => (new SubscriptionResource($subscription->loadMissing('plan')))->resolve(),
            'change' => [
                'id' => $change->id,
                'change_type' => $change->change_type,
                'status' => $change->status,
                'effective_at' => $change->effective_at,
                'over_cap_warning' => $change->metadata['over_cap_warning'] ?? null,
            ],
            'invoice' => $invoice ? (new InvoiceResource($invoice->loadMissing('items')))->resolve() : null,
        ]);
    }

    public function cancel(Request $request): JsonResponse
    {
        $subscription = $this->requireSubscription();
        $this->manager->scheduleCancellation($subscription, $request->user());

        return response()->json(['data' => (new SubscriptionResource($subscription->loadMissing('plan')))->resolve()]);
    }

    public function resume(Request $request): JsonResponse
    {
        $subscription = $this->requireSubscription();
        $this->manager->resume($subscription, $request->user());

        return response()->json(['data' => (new SubscriptionResource($subscription->loadMissing('plan')))->resolve()]);
    }

    /** Issue an invoice for the current plan period (e.g. convert trial to paid). */
    public function invoice(Request $request): JsonResponse
    {
        $subscription = $this->requireSubscription();
        $invoice = $this->checkout->issuePlanInvoice($subscription, [], $request->user());

        return response()->json(['data' => (new InvoiceResource($invoice->loadMissing('items')))->resolve()], 201);
    }

    private function requireSubscription(): Subscription
    {
        $subscription = $this->entitlements->subscription();
        abort_if($subscription === null, 422, __('billing.no_subscription'));

        return $subscription;
    }

    private function purchasablePlan(string $planId): Plan
    {
        $plan = Plan::query()->whereKey($planId)->first();
        abort_if(
            $plan === null
                || $plan->status !== PlanStatus::Active
                || $plan->visibility === PlanVisibility::Private,
            422,
            __('billing.plan_not_available'),
        );

        return $plan;
    }
}
