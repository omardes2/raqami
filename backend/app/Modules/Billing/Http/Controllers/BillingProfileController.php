<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Http\Requests\BillingProfileRequest;
use App\Modules\Billing\Http\Resources\BillingProfileResource;
use App\Modules\Billing\Models\BillingProfile;
use Illuminate\Http\JsonResponse;

/** Tenant billing/tax profile (one per tenant). */
class BillingProfileController extends Controller
{
    public function show(): JsonResponse
    {
        $profile = BillingProfile::query()->first();

        return response()->json([
            'data' => $profile ? (new BillingProfileResource($profile))->resolve() : null,
        ]);
    }

    public function update(BillingProfileRequest $request, AuditLogger $audit): BillingProfileResource
    {
        $profile = BillingProfile::query()->first();
        if ($profile === null) {
            $profile = BillingProfile::query()->create($request->validated());
        } else {
            $profile->update($request->validated());
        }

        $audit->log('billing_profile.updated', [
            'actor' => $request->user(),
            'subject' => $profile,
            'metadata' => ['fields' => array_keys($request->validated())],
        ]);

        return new BillingProfileResource($profile);
    }
}
