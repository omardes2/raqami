<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Enums\PlanStatus;
use App\Modules\Billing\Enums\PlanVisibility;
use App\Modules\Billing\Http\Resources\PlanResource;
use App\Modules\Billing\Models\Plan;
use Illuminate\Http\JsonResponse;

/** Tenant-facing catalog of purchasable plans (active + public). */
class PlanCatalogController extends Controller
{
    public function index(): JsonResponse
    {
        $plans = Plan::query()
            ->with('features')
            ->where('status', PlanStatus::Active->value)
            ->where('visibility', PlanVisibility::Public->value)
            ->orderBy('sort_order')
            ->get();

        return response()->json(['data' => PlanResource::collection($plans)->resolve()]);
    }
}
