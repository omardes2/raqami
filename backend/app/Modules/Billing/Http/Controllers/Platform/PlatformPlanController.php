<?php

namespace App\Modules\Billing\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Http\Requests\PlanFeatureRequest;
use App\Modules\Billing\Http\Requests\PlanRequest;
use App\Modules\Billing\Http\Resources\PlanFeatureResource;
use App\Modules\Billing\Http\Resources\PlanResource;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\PlanFeature;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/** Super Admin plan + entitlement management (platform-global). */
class PlatformPlanController extends Controller
{
    private function admin()
    {
        return Auth::guard('platform')->user();
    }

    public function index(): JsonResponse
    {
        $plans = Plan::query()->with('features')->orderBy('sort_order')->get();

        return response()->json(['data' => PlanResource::collection($plans)->resolve()]);
    }

    public function store(PlanRequest $request, AuditLogger $audit): JsonResponse
    {
        $plan = Plan::query()->create($request->validated());
        $audit->log('plan.created', ['actor' => $this->admin(), 'tenant_id' => null, 'subject' => $plan,
            'metadata' => ['slug' => $plan->slug]]);

        return (new PlanResource($plan->load('features')))->response()->setStatusCode(201);
    }

    public function show(Plan $plan): PlanResource
    {
        return new PlanResource($plan->load('features'));
    }

    public function update(PlanRequest $request, Plan $plan, AuditLogger $audit): PlanResource
    {
        $plan->update($request->validated());
        $audit->log('plan.updated', ['actor' => $this->admin(), 'tenant_id' => null, 'subject' => $plan,
            'metadata' => ['fields' => array_keys($request->validated())]]);

        return new PlanResource($plan->load('features'));
    }

    public function archive(Plan $plan, AuditLogger $audit): JsonResponse
    {
        $plan->update(['status' => 'archived']);
        $audit->log('plan.archived', ['actor' => $this->admin(), 'tenant_id' => null, 'subject' => $plan]);

        return response()->json(['id' => $plan->id, 'status' => $plan->status->value]);
    }

    public function storeFeature(PlanFeatureRequest $request, Plan $plan, AuditLogger $audit): JsonResponse
    {
        $feature = $plan->features()->updateOrCreate(
            ['feature_key' => $request->validated('feature_key')],
            $request->validated(),
        );
        $audit->log('plan.updated', ['actor' => $this->admin(), 'tenant_id' => null, 'subject' => $plan,
            'metadata' => ['feature' => $feature->feature_key]]);

        return (new PlanFeatureResource($feature))->response()->setStatusCode(201);
    }

    public function destroyFeature(Plan $plan, PlanFeature $feature, AuditLogger $audit): JsonResponse
    {
        abort_unless($feature->plan_id === $plan->id, 404);
        $key = $feature->feature_key;
        $feature->delete();
        $audit->log('plan.updated', ['actor' => $this->admin(), 'tenant_id' => null, 'subject' => $plan,
            'metadata' => ['removed_feature' => $key]]);

        return response()->json(['removed' => true]);
    }
}
