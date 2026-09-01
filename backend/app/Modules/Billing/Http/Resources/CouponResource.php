<?php

namespace App\Modules\Billing\Http\Resources;

use App\Modules\Billing\Models\Coupon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Coupon */
class CouponResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type->value,
            'percentage' => $this->percentage,
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency,
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'max_redemptions' => $this->max_redemptions,
            'per_tenant_limit' => $this->per_tenant_limit,
            'redeemed_count' => $this->redeemed_count,
            'applicable_plan_id' => $this->applicable_plan_id,
            'status' => $this->status,
        ];
    }
}
