<?php

namespace App\Modules\Billing\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Http\Requests\ManualPaymentRequest;
use App\Modules\Billing\Http\Resources\PaymentResource;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Services\ManualPaymentService;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Super Admin payment views + manual/cash payment recording. Recording a
 * succeeded payment is PLATFORM-ONLY (a tenant user can never do it) and is
 * applied transactionally inside the target tenant's context.
 */
class PlatformPaymentController extends Controller
{
    public function index(TenantContext $context): JsonResponse
    {
        $data = $context->runAsPlatform(fn () => PaymentResource::collection(
            Payment::query()->orderByDesc('created_at')->limit(200)->get()
        )->resolve());

        return response()->json(['data' => $data]);
    }

    public function manual(ManualPaymentRequest $request, ManualPaymentService $service): JsonResponse
    {
        $payment = $service->record($request->validated(), Auth::guard('platform')->user());

        return (new PaymentResource($payment))->response()->setStatusCode(201);
    }
}
