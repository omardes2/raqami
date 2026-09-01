<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Enums\SubscriptionStatus;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Models\SubscriptionChange;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Idempotent processor for time-based subscription lifecycle transitions
 * (spec §10): trial expiry, grace expiry, scheduled cancellation, and scheduled
 * downgrade. Safe to run repeatedly (each pass only acts on still-due items).
 * Candidates are discovered via the audited platform read-only context, then
 * each is mutated inside ITS OWN tenant context so RLS holds; a failure for one
 * tenant is isolated and does not abort the rest. No cron is configured here —
 * this is the callable foundation a scheduler will invoke.
 */
class SubscriptionLifecycleProcessor
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly SubscriptionManager $manager,
    ) {}

    /**
     * @return array{trials_expired:int, grace_suspended:int, cancellations:int, downgrades:int, errors:int}
     */
    public function processDue(?\DateTimeInterface $asOf = null): array
    {
        $now = $asOf ? Carbon::parse($asOf) : now();
        $result = ['trials_expired' => 0, 'grace_suspended' => 0, 'cancellations' => 0, 'downgrades' => 0, 'errors' => 0];

        // Discover due candidates across all tenants (read-only), capturing only ids.
        [$trialIds, $graceIds, $cancelIds, $downgradeChangeIds] = $this->context->runAsPlatform(function () use ($now) {
            $trials = Subscription::query()
                ->where('status', SubscriptionStatus::Trialing->value)
                ->whereNotNull('trial_ends_at')->where('trial_ends_at', '<=', $now)
                ->get(['id', 'tenant_id']);
            $grace = Subscription::query()
                ->where('status', SubscriptionStatus::GracePeriod->value)
                ->whereNotNull('grace_ends_at')->where('grace_ends_at', '<=', $now)
                ->get(['id', 'tenant_id']);
            $cancels = Subscription::query()
                ->where('cancel_at_period_end', true)
                ->whereNotIn('status', [SubscriptionStatus::Canceled->value, SubscriptionStatus::Expired->value])
                ->whereNotNull('current_period_end')->where('current_period_end', '<=', $now)
                ->get(['id', 'tenant_id']);
            $downgrades = SubscriptionChange::query()
                ->where('status', 'scheduled')->where('change_type', 'downgrade')
                ->where('effective_at', '<=', $now)
                ->get(['id', 'tenant_id']);

            return [
                $trials->map(fn ($s) => [$s->id, $s->tenant_id])->all(),
                $grace->map(fn ($s) => [$s->id, $s->tenant_id])->all(),
                $cancels->map(fn ($s) => [$s->id, $s->tenant_id])->all(),
                $downgrades->map(fn ($c) => [$c->id, $c->tenant_id])->all(),
            ];
        });

        foreach ($trialIds as [$id, $tenantId]) {
            $result['trials_expired'] += $this->runForSubscription($tenantId, $id, fn (Subscription $s) => $this->manager->expire($s, 'trial_expired'), $result);
        }
        foreach ($graceIds as [$id, $tenantId]) {
            $result['grace_suspended'] += $this->runForSubscription($tenantId, $id, fn (Subscription $s) => $this->manager->suspend($s), $result);
        }
        foreach ($cancelIds as [$id, $tenantId]) {
            $result['cancellations'] += $this->runForSubscription($tenantId, $id, fn (Subscription $s) => $this->manager->cancelNow($s), $result);
        }
        foreach ($downgradeChangeIds as [$changeId, $tenantId]) {
            $result['downgrades'] += $this->runForChange($tenantId, $changeId, $result);
        }

        return $result;
    }

    private function runForSubscription(string $tenantId, string $subscriptionId, callable $action, array &$result): int
    {
        try {
            return $this->context->runAs($tenantId, function () use ($subscriptionId, $action) {
                $sub = Subscription::query()->whereKey($subscriptionId)->first();
                if ($sub === null) {
                    return 0;
                }
                $action($sub);

                return 1;
            });
        } catch (Throwable) {
            $result['errors']++;

            return 0;
        }
    }

    private function runForChange(string $tenantId, string $changeId, array &$result): int
    {
        try {
            return $this->context->runAs($tenantId, function () use ($changeId) {
                $change = SubscriptionChange::query()->whereKey($changeId)->first();
                if ($change === null) {
                    return 0;
                }
                $this->manager->applyScheduledDowngrade($change);

                return 1;
            });
        } catch (Throwable) {
            $result['errors']++;

            return 0;
        }
    }
}
