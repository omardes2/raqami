<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Models\SubscriptionEvent;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes the COMMERCIAL subscription timeline (subscription_events). This is
 * distinct from the security audit log — both are written for meaningful
 * transitions, each for its own purpose (spec §27).
 */
class SubscriptionEventRecorder
{
    public function record(Subscription $subscription, string $eventType, array $metadata = [], mixed $actor = null): SubscriptionEvent
    {
        [$actorType, $actorId] = $this->describeActor($actor);

        return SubscriptionEvent::query()->create([
            'subscription_id' => $subscription->getKey(),
            'event_type' => $eventType,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);
    }

    /** @return array{0:?string,1:?string} [type, id] */
    private function describeActor(mixed $actor): array
    {
        if ($actor instanceof Model) {
            $type = str_contains($actor::class, 'PlatformAdmin') ? 'platform_admin' : 'user';

            return [$type, (string) $actor->getKey()];
        }

        return ['system', null];
    }
}
