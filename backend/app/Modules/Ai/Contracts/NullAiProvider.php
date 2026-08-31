<?php

namespace App\Modules\Ai\Contracts;

use BadMethodCallException;

/**
 * Inert default AI driver for Sprint 0. Exposes the contract but performs no
 * inference and contacts no external service.
 */
class NullAiProvider implements AiProvider
{
    public function identifier(): string
    {
        return 'null';
    }

    public function isEnabled(): bool
    {
        return false;
    }

    public function complete(AiRequest $request): AiResponse
    {
        throw new BadMethodCallException(
            'AI provider integration is implemented in the AI sprint (ADR-011). '.
            'Sprint 0 provides the provider contract only.'
        );
    }
}
