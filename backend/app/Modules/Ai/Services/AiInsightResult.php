<?php

namespace App\Modules\Ai\Services;

/**
 * Sprint 9 — immutable result of an AI insight request. When AI is unavailable
 * (provider disabled, not entitled, or rate-limited) `available` is false and
 * `unavailableReason` explains why; the core product keeps working regardless.
 */
final class AiInsightResult
{
    /**
     * @param  list<string>  $highlights
     */
    public function __construct(
        public readonly string $feature,
        public readonly bool $available,
        public readonly ?string $summary = null,
        public readonly array $highlights = [],
        public readonly ?string $unavailableReason = null,
    ) {}

    public static function unavailable(string $feature, string $reason): self
    {
        return new self($feature, false, null, [], $reason);
    }

    public static function ok(string $feature, string $summary, array $highlights = []): self
    {
        return new self($feature, true, $summary, array_values($highlights));
    }

    public function toArray(): array
    {
        return [
            'feature' => $this->feature,
            'available' => $this->available,
            'summary' => $this->summary,
            'highlights' => $this->highlights,
            'unavailable_reason' => $this->unavailableReason,
        ];
    }
}
