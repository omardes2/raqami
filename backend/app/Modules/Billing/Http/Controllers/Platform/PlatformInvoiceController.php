<?php

namespace App\Modules\Billing\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Http\Resources\InvoiceResource;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Super Admin cross-tenant invoice list (audited read-only context). */
class PlatformInvoiceController extends Controller
{
    public function index(Request $request, TenantContext $context): JsonResponse
    {
        $status = $request->query('status');
        $data = $context->runAsPlatform(fn () => InvoiceResource::collection(
            Invoice::query()
                ->when($status, fn ($q) => $q->where('status', $status))
                ->orderByDesc('created_at')->limit(200)->get()
        )->resolve());

        return response()->json(['data' => $data]);
    }
}
