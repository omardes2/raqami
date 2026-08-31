<?php

namespace App\Modules\Billing\Contracts;

/** Immutable result of a payment attempt (used by future gateway drivers). */
final class PaymentResult
{
    public function __construct(
        public readonly string $status,      // pending|succeeded|failed
        public readonly ?string $reference = null,
        public readonly ?string $message = null,
    ) {}
}
