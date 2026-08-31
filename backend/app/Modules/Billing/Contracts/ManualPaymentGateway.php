<?php

namespace App\Modules\Billing\Contracts;

use BadMethodCallException;

/**
 * Inert default driver for Sprint 0. It exposes the contract surface but does
 * NOT process payments or contact any provider — real processing (card/bank/
 * manual persistence) is implemented in the Billing sprint.
 */
class ManualPaymentGateway implements PaymentGateway
{
    public function identifier(): string
    {
        return 'manual';
    }

    public function supports(string $method): bool
    {
        return in_array($method, config('billing.methods', []), true);
    }

    public function charge(array $payload): PaymentResult
    {
        throw new BadMethodCallException(
            'Payment processing is implemented in the Billing sprint (ADR-010). '.
            'Sprint 0 provides the gateway contract only.'
        );
    }
}
