<?php

namespace App\Modules\Billing\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A tenant's redemption of a platform coupon. Tenant-owned (tenant_id + RLS). */
class CouponRedemption extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'coupon_id', 'coupon_code', 'subscription_id',
        'invoice_id', 'redeemed_by_user_id', 'discount_minor',
    ];

    protected function casts(): array
    {
        return [
            'discount_minor' => 'integer',
        ];
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }
}
