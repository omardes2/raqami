<?php

namespace App\Modules\Ai\Contracts;

/**
 * Provider-agnostic AI contract (ADR-011). Business logic depends ONLY on this
 * interface. Guardrails: AI must never autonomously modify payroll, approve
 * payroll, change attendance, approve leave, modify financial transactions, or
 * perform destructive actions. Any future AI-assisted write requires explicit
 * authorized user confirmation.
 *
 * Sprint 0 ships the contract and an inert default driver — no AI service is
 * called.
 */
interface AiProvider
{
    public function identifier(): string;

    public function isEnabled(): bool;

    public function complete(AiRequest $request): AiResponse;
}
