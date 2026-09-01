<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Http\Resources\PaymentResource;
use App\Modules\Billing\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Tenant payment history (read-only). */
class PaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $page = Payment::query()
            ->orderByDesc('created_at')
            ->paginate(min((int) $request->query('per_page', 20), 100));

        return response()->json(PaymentResource::collection($page)->response()->getData(true));
    }
}
