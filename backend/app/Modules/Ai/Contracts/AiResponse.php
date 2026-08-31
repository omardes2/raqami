<?php

namespace App\Modules\Ai\Contracts;

/** Immutable AI response DTO. */
final class AiResponse
{
    public function __construct(
        public readonly string $content,
        public readonly array $meta = [],
    ) {}
}
