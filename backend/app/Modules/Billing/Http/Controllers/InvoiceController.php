<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Http\Resources\InvoiceResource;
use App\Modules\Billing\Models\BillingProfile;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tenant invoice list/detail + printable HTML (PDF foundation, spec §24).
 * Route-model binding runs under tenant context, so a cross-tenant invoice id
 * resolves to a 404 (no leakage).
 */
class InvoiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Invoice::query()->orderByDesc('created_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $page = $query->paginate(min((int) $request->query('per_page', 20), 100));

        return response()->json(InvoiceResource::collection($page)->response()->getData(true));
    }

    public function show(Invoice $invoice): InvoiceResource
    {
        return new InvoiceResource($invoice->load('items'));
    }

    /** Printable HTML invoice (foundation for PDF export). */
    public function html(Invoice $invoice, TenantContext $context): View
    {
        return view('billing.invoice', [
            'invoice' => $invoice->load('items'),
            'tenant' => $context->tenant(),
            'profile' => BillingProfile::query()->first(),
        ]);
    }
}
