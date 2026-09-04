<?php

namespace App\Modules\Dashboard\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Dashboard\Services\DashboardService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Company dashboard (Sprint 8A Phase 2). Any authenticated tenant user may call it;
 * the response contains only the KPI cards that user is independently authorized and
 * scoped to see (composed by DashboardService). Backend omission is authoritative —
 * an unauthorized card is absent, never a zero or a flag.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard) {}

    public function company(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->dashboard->company($request->user()),
            'meta' => [
                'generated_at' => CarbonImmutable::now()->toIso8601String(),
                'timezone' => $this->dashboard->timezone(),
            ],
        ]);
    }
}
