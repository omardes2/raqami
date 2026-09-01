<?php

namespace App\Modules\Billing\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Http\Resources\InvoiceResource;
use App\Modules\Billing\Http\Resources\SubscriptionResource;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * Super Admin cross-tenant subscription views. Cross-tenant reads happen ONLY
 * through the audited platform read-only context; writes never bypass tenant
 * scope (RLS WITH CHECK remains tenant-only).
 */
class PlatformSubscriptionController extends Controller
{
    public function index(TenantContext $context): JsonResponse
    {
        $data = $context->runAsPlatform(fn () => SubscriptionResource::collection(
            Subscription::query()->with('plan')->orderByDesc('created_at')->limit(200)->get()
        )->resolve());

        return response()->json(['data' => $data]);
    }

    public function show(string $subscription, TenantContext $context): JsonResponse
    {
        $payload = $context->runAsPlatform(function () use ($subscription) {
            $model = Subscription::query()->with('plan')->findOrFail($subscription);
            $invoices = Invoice::query()->where('subscription_id', $model->getKey())->orderByDesc('created_at')->get();

            return [
                'subscription' => (new SubscriptionResource($model))->resolve(),
                'invoices' => InvoiceResource::collection($invoices)->resolve(),
            ];
        });

        return response()->json($payload);
    }
}
