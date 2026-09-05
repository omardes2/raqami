<?php

namespace App\Modules\Ai\Services;

use App\Modules\Ai\Models\AiUsageEvent;
use App\Modules\Tenancy\Services\TenantContext;

/**
 * Sprint 9 — records AI usage into the append-only ai_usage_events ledger for
 * cost/observability and entitlement enforcement. Stores ONLY safe operational
 * metadata; never prompt/response content or any employee/payroll data. Tenant
 * and actor come from TenantContext, never from caller input.
 */
class AiUsageLedger
{
    public function __construct(private readonly TenantContext $context) {}

    public function record(
        string $feature,
        string $provider,
        string $model,
        int $inputUnits = 0,
        int $outputUnits = 0,
        ?int $estimatedCostMicro = null,
        string $status = 'ok',
    ): AiUsageEvent {
        return AiUsageEvent::create([
            'user_id' => $this->context->userId(),
            'provider' => $provider,
            'model' => $model,
            'feature' => $feature,
            'input_units' => max(0, $inputUnits),
            'output_units' => max(0, $outputUnits),
            'estimated_cost_micro' => $estimatedCostMicro,
            'status' => $status,
            'meta' => [],
        ]);
    }

    /** Count a tenant's AI calls since a given time (for usage limits). */
    public function countSince(\DateTimeInterface $since, ?string $feature = null): int
    {
        $query = AiUsageEvent::query()->where('created_at', '>=', $since);
        if ($feature !== null) {
            $query->where('feature', $feature);
        }

        return $query->count();
    }
}
