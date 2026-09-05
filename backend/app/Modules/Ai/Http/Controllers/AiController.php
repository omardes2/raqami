<?php

namespace App\Modules\Ai\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ai\Http\Requests\AiInsightRequest;
use App\Modules\Ai\Services\AiInsightService;
use Illuminate\Http\JsonResponse;

/**
 * Sprint 9 — read-only AI assistant endpoints. Route-gated by
 * permission.any:ai.use; each feature additionally checks the relevant report
 * permission and gathers only already-authorized aggregates. The AI never
 * mutates state.
 */
class AiController extends Controller
{
    public function __construct(private readonly AiInsightService $service) {}

    /** GET /api/ai/availability — whether AI can run for this tenant now. */
    public function availability(): JsonResponse
    {
        return response()->json(['data' => $this->service->availability()]);
    }

    /** POST /api/ai/insights — generate a read-only summary for a feature. */
    public function insight(AiInsightRequest $request): JsonResponse
    {
        $result = $this->service->generate(
            $request->user(),
            (string) $request->string('feature'),
            ['report' => (string) $request->string('report')],
        );

        return response()->json(['data' => $result->toArray()]);
    }
}
