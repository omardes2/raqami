<?php

namespace App\Modules\Billing\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Http\Requests\CouponRequest;
use App\Modules\Billing\Http\Resources\CouponResource;
use App\Modules\Billing\Models\Coupon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/** Super Admin coupon management (platform-global). */
class PlatformCouponController extends Controller
{
    private function admin()
    {
        return Auth::guard('platform')->user();
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => CouponResource::collection(
            Coupon::query()->orderByDesc('created_at')->get()
        )->resolve()]);
    }

    public function store(CouponRequest $request, AuditLogger $audit): JsonResponse
    {
        $coupon = Coupon::query()->create($request->validated());
        $audit->log('coupon.created', ['actor' => $this->admin(), 'tenant_id' => null, 'subject' => $coupon,
            'metadata' => ['code' => $coupon->code]]);

        return (new CouponResource($coupon))->response()->setStatusCode(201);
    }

    public function update(CouponRequest $request, Coupon $coupon, AuditLogger $audit): CouponResource
    {
        $coupon->update($request->validated());
        $audit->log('coupon.updated', ['actor' => $this->admin(), 'tenant_id' => null, 'subject' => $coupon,
            'metadata' => ['fields' => array_keys($request->validated())]]);

        return new CouponResource($coupon);
    }

    public function archive(Coupon $coupon, AuditLogger $audit): JsonResponse
    {
        $coupon->update(['status' => 'archived']);
        $audit->log('coupon.archived', ['actor' => $this->admin(), 'tenant_id' => null, 'subject' => $coupon]);

        return response()->json(['id' => $coupon->id, 'status' => $coupon->status]);
    }
}
