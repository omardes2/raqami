<?php

namespace App\Modules\Billing\Enums;

/**
 * Subscription lifecycle states and the ALLOWED transitions between them.
 * Centralizing this here keeps controllers/services from mutating status with
 * arbitrary strings (invalid transitions are rejected by SubscriptionManager).
 */
enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case GracePeriod = 'grace_period';
    case Suspended = 'suspended';
    case Canceled = 'canceled';
    case Expired = 'expired';

    /** States in which the product is considered usable (entitlements apply). */
    public function isUsable(): bool
    {
        return in_array($this, [self::Trialing, self::Active, self::GracePeriod], true);
    }

    /** Terminal states — the subscription no longer transitions on its own. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Canceled, self::Expired], true);
    }

    /**
     * Allowed next states from the current one.
     *
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Trialing => [self::Active, self::PastDue, self::Canceled, self::Expired],
            self::Active => [self::PastDue, self::GracePeriod, self::Canceled, self::Expired, self::Active],
            self::PastDue => [self::Active, self::GracePeriod, self::Suspended, self::Canceled, self::Expired],
            self::GracePeriod => [self::Active, self::Suspended, self::Canceled, self::Expired],
            self::Suspended => [self::Active, self::Canceled, self::Expired],
            self::Canceled => [],
            self::Expired => [self::Active], // reactivation via a fresh paid period
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedNext(), true);
    }
}
