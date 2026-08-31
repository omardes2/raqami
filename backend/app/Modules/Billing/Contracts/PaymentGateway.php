<?php

namespace App\Modules\Billing\Contracts;

/**
 * Provider-agnostic payment gateway contract (ADR-010). Future drivers (card
 * providers, bank transfer, manual/cash, regional gateways) implement this.
 * Billing logic depends ONLY on this interface, never on a concrete provider.
 *
 * Sprint 0 ships the contract and an inert default driver — no provider is
 * integrated and no external calls are made.
 */
interface PaymentGateway
{
    /** Stable driver identifier, e.g. "manual", "stripe", "cybersource". */
    public function identifier(): string;

    /** Whether this driver supports a given payment method. */
    public function supports(string $method): bool;

    /**
     * Create a charge. Implemented by real drivers in the Billing sprint.
     *
     * @param  array<string,mixed>  $payload
     */
    public function charge(array $payload): PaymentResult;
}
