<?php

namespace App\Modules\Billing\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Enums\BillingInterval;
use App\Modules\Billing\Enums\SubscriptionStatus;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Models\SubscriptionChange;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The subscription lifecycle domain service (spec §5–§8, §26). ALL status
 * changes go through here so invalid transitions are rejected (SubscriptionStatus
 * transition map), every meaningful change writes a commercial subscription_event
 * AND a security audit entry, and no controller mutates status directly.
 *
 * Downgrades never delete data — they are recorded as a scheduled
 * subscription_change and applied at period end.
 */
class SubscriptionManager
{
    public function __construct(
        private readonly SubscriptionEventRecorder $events,
        private readonly AuditLogger $audit,
        private readonly EntitlementService $entitlements,
    ) {}

    /**
     * Create the tenant's single subscription on plan selection. Starts a trial
     * when the plan offers one; otherwise activates immediately. A subscription
     * may only be created once per tenant (enforced by unique(tenant_id)); trial
     * abuse via repeated creation is therefore impossible.
     */
    public function start(Plan $plan, string $interval, array $opts = [], mixed $actor = null): Subscription
    {
        if (Subscription::query()->exists()) {
            throw new RuntimeException('This tenant already has a subscription; use changePlan().');
        }

        $intervalEnum = BillingInterval::from($interval);
        // Currency ALWAYS derives from the plan — never from client input. The
        // invoice amount is the plan's price (in the plan's currency), so the
        // subscription/invoice currency must match it. No FX in Sprint 2.
        $currency = $plan->currency;
        $wantsTrial = ($opts['trial'] ?? true) && $plan->trial_days > 0;
        $now = now();

        return DB::transaction(function () use ($plan, $intervalEnum, $currency, $wantsTrial, $now, $actor) {
            if ($wantsTrial) {
                $trialEnds = $now->copy()->addDays($plan->trial_days);
                $subscription = Subscription::query()->create([
                    'plan_id' => $plan->getKey(),
                    'status' => SubscriptionStatus::Trialing,
                    'billing_interval' => $intervalEnum,
                    'currency' => $currency,
                    'trial_started_at' => $now,
                    'trial_ends_at' => $trialEnds,
                    'current_period_start' => $now,
                    'current_period_end' => $trialEnds,
                ]);
                $event = 'trial_started';
            } else {
                $subscription = Subscription::query()->create([
                    'plan_id' => $plan->getKey(),
                    'status' => SubscriptionStatus::Active,
                    'billing_interval' => $intervalEnum,
                    'currency' => $currency,
                    'started_at' => $now,
                    'current_period_start' => $now,
                    'current_period_end' => $intervalEnum->advance($now),
                ]);
                $event = 'activated';
            }

            $this->events->record($subscription, $event, ['plan' => $plan->slug, 'interval' => $intervalEnum->value], $actor);
            $this->audit->log('subscription.created', [
                'actor' => $actor, 'subject' => $subscription,
                'metadata' => ['plan' => $plan->slug, 'status' => $subscription->status->value],
            ]);

            return $subscription;
        });
    }

    public function activate(Subscription $subscription, mixed $actor = null): Subscription
    {
        return $this->apply($subscription, SubscriptionStatus::Active, 'activated', $actor, function (Subscription $s) {
            $now = now();
            $s->started_at ??= $now;
            $s->current_period_start = $now;
            $s->current_period_end = $s->billing_interval->advance($now);
            $s->grace_ends_at = null;
        });
    }

    public function renew(Subscription $subscription, mixed $actor = null): Subscription
    {
        return $this->apply($subscription, SubscriptionStatus::Active, 'renewed', $actor, function (Subscription $s) {
            $from = $s->current_period_end && $s->current_period_end->isFuture()
                ? $s->current_period_end
                : now();
            $s->current_period_start = $from;
            $s->current_period_end = $s->billing_interval->advance($from);
            $s->grace_ends_at = null;
        });
    }

    public function markPastDue(Subscription $subscription, mixed $actor = null): Subscription
    {
        return $this->apply($subscription, SubscriptionStatus::PastDue, 'past_due', $actor);
    }

    public function enterGracePeriod(Subscription $subscription, ?int $days = null, mixed $actor = null): Subscription
    {
        $days ??= (int) config('billing.grace_days', 3);

        return $this->apply($subscription, SubscriptionStatus::GracePeriod, 'grace_started', $actor,
            fn (Subscription $s) => $s->grace_ends_at = now()->addDays($days));
    }

    public function suspend(Subscription $subscription, mixed $actor = null): Subscription
    {
        return $this->apply($subscription, SubscriptionStatus::Suspended, 'suspended', $actor);
    }

    public function expire(Subscription $subscription, string $reason = 'expired', mixed $actor = null): Subscription
    {
        return $this->apply($subscription, SubscriptionStatus::Expired, $reason, $actor,
            fn (Subscription $s) => $s->ended_at = now());
    }

    public function cancelNow(Subscription $subscription, mixed $actor = null): Subscription
    {
        return $this->apply($subscription, SubscriptionStatus::Canceled, 'canceled', $actor, function (Subscription $s) {
            $s->canceled_at = now();
            $s->ended_at = now();
            $s->cancel_at_period_end = false;
        });
    }

    /** Schedule cancellation at period end (status unchanged until then). */
    public function scheduleCancellation(Subscription $subscription, mixed $actor = null): Subscription
    {
        if ($subscription->status->isTerminal()) {
            throw new RuntimeException('Cannot schedule cancellation on a terminal subscription.');
        }

        return DB::transaction(function () use ($subscription, $actor) {
            $subscription->cancel_at_period_end = true;
            $subscription->canceled_at = now();
            $subscription->save();

            $this->events->record($subscription, 'cancellation_scheduled',
                ['effective_at' => $subscription->current_period_end?->toIso8601String()], $actor);
            $this->audit->log('subscription.cancel_scheduled', ['actor' => $actor, 'subject' => $subscription]);

            return $subscription;
        });
    }

    /** Undo a scheduled cancellation before it takes effect. */
    public function resume(Subscription $subscription, mixed $actor = null): Subscription
    {
        if (! $subscription->cancel_at_period_end || $subscription->status->isTerminal()) {
            throw new RuntimeException('No pending cancellation to resume.');
        }

        return DB::transaction(function () use ($subscription, $actor) {
            $subscription->cancel_at_period_end = false;
            $subscription->canceled_at = null;
            $subscription->save();

            $this->events->record($subscription, 'resumed', [], $actor);
            $this->audit->log('subscription.resumed', ['actor' => $actor, 'subject' => $subscription]);

            return $subscription;
        });
    }

    /**
     * Change plan. Upgrades apply immediately (higher limits available at once);
     * downgrades are recorded and scheduled for period end and never delete data.
     * Returns the recorded SubscriptionChange.
     */
    public function changePlan(Subscription $subscription, Plan $toPlan, ?string $interval = null, mixed $actor = null): SubscriptionChange
    {
        if ($subscription->status->isTerminal()) {
            throw new RuntimeException('Cannot change plan on a terminal subscription.');
        }

        // A plan change must not silently change currency — there is no FX in
        // Sprint 2, and the invoice amount is denominated in the plan's currency.
        if ($toPlan->currency !== $subscription->currency) {
            throw ValidationException::withMessages([
                'plan_id' => [__('billing.currency_change_unsupported')],
            ]);
        }

        $intervalEnum = $interval ? BillingInterval::from($interval) : $subscription->billing_interval;
        $fromPlan = $subscription->plan;
        $current = $fromPlan?->priceMinorFor($intervalEnum->value) ?? 0;
        $target = $toPlan->priceMinorFor($intervalEnum->value);
        $isUpgrade = $target >= $current;

        return DB::transaction(function () use ($subscription, $fromPlan, $toPlan, $intervalEnum, $isUpgrade, $actor) {
            $effectiveAt = $isUpgrade
                ? now()
                : ($subscription->current_period_end ?? $intervalEnum->advance(now()));

            // Warn (never delete) if current usage exceeds the target plan cap.
            $overCap = null;
            if (! $isUpgrade && $toPlan->employee_limit !== null) {
                $count = $this->entitlements->countableEmployees();
                if ($count > $toPlan->employee_limit) {
                    $overCap = ['current_employees' => $count, 'target_limit' => $toPlan->employee_limit];
                }
            }

            $change = SubscriptionChange::query()->create([
                'subscription_id' => $subscription->getKey(),
                'from_plan_id' => $fromPlan?->getKey(),
                'to_plan_id' => $toPlan->getKey(),
                'change_type' => $isUpgrade ? 'upgrade' : 'downgrade',
                'effective_at' => $effectiveAt,
                'status' => $isUpgrade ? 'applied' : 'scheduled',
                'requested_by_user_id' => $this->actorUserId($actor),
                'metadata' => $overCap ? ['over_cap_warning' => $overCap] : null,
            ]);

            if ($isUpgrade) {
                $subscription->plan_id = $toPlan->getKey();
                $subscription->billing_interval = $intervalEnum;
                $subscription->save();
                $this->events->record($subscription, 'plan_changed',
                    ['from' => $fromPlan?->slug, 'to' => $toPlan->slug, 'type' => 'upgrade'], $actor);
            } else {
                $this->events->record($subscription, 'downgrade_scheduled',
                    ['from' => $fromPlan?->slug, 'to' => $toPlan->slug, 'effective_at' => $effectiveAt->toIso8601String(), 'over_cap' => $overCap], $actor);
            }

            $this->audit->log('subscription.plan_changed', [
                'actor' => $actor, 'subject' => $subscription,
                'metadata' => ['from' => $fromPlan?->slug, 'to' => $toPlan->slug, 'type' => $change->change_type],
            ]);

            return $change;
        });
    }

    /** Apply a validated status transition with event + audit. */
    private function apply(Subscription $subscription, SubscriptionStatus $to, string $event, mixed $actor, ?callable $mutate = null): Subscription
    {
        $from = $subscription->status;
        if (! $from->canTransitionTo($to)) {
            throw new RuntimeException("Invalid subscription transition: {$from->value} -> {$to->value}.");
        }

        return DB::transaction(function () use ($subscription, $to, $from, $event, $actor, $mutate) {
            $subscription->status = $to;
            if ($mutate) {
                $mutate($subscription);
            }
            $subscription->save();

            $this->events->record($subscription, $event, ['from' => $from->value, 'to' => $to->value], $actor);
            $this->audit->log('subscription.'.$event, [
                'actor' => $actor, 'subject' => $subscription,
                'metadata' => ['from' => $from->value, 'to' => $to->value],
            ]);

            return $subscription;
        });
    }

    private function actorUserId(mixed $actor): ?string
    {
        return $actor instanceof Model && ! str_contains($actor::class, 'PlatformAdmin')
            ? (string) $actor->getKey()
            : null;
    }
}
