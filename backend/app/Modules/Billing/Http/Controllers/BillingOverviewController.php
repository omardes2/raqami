<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Http\Resources\SubscriptionResource;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Services\EntitlementService;
use Illuminate\Http\JsonResponse;

/** Tenant billing overview (spec §23). Read-only summary for the portal. */
class BillingOverviewController extends Controller
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    public function show(): JsonResponse
    {
        $subscription = $this->entitlements->subscription();

        $outstanding = Invoice::query()
            ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value, InvoiceStatus::Overdue->value])
            ->sum('amount_due_minor');

        return response()->json([
            'subscription' => $subscription ? (new SubscriptionResource($subscription->loadMissing('plan')))->resolve() : null,
            'employee_usage' => [
                'used' => $this->entitlements->countableEmployees(),
                'limit' => $this->entitlements->employeeLimit(),
                'remaining' => $this->entitlements->remainingEmployeeSlots(),
            ],
            'outstanding_balance_minor' => (int) $outstanding,
            'currency' => $subscription?->currency,
            'next_renewal_at' => $subscription?->current_period_end,
        ]);
    }
}
