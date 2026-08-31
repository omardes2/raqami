<?php

namespace App\Modules\Ai\Contracts;

/** Immutable AI request DTO (used by future AI provider drivers). */
final class AiRequest
{
    /** @param array<int,array<string,string>> $messages */
    public function __construct(
        public readonly string $purpose,
        public readonly array $messages = [],
        public readonly array $options = [],
    ) {}
}
