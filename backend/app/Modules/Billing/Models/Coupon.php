<?php

namespace App\Modules\Billing\Models;

use App\Modules\Billing\Enums\CouponType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Platform-global coupon / promo code. Code is stored normalized (uppercase). */
class Coupon extends Model
{
    use HasUlids;

    protected $fillable = [
        'code', 'name', 'type', 'percentage', 'amount_minor', 'currency',
        'starts_at', 'ends_at', 'max_redemptions', 'per_tenant_limit',
        'redeemed_count', 'applicable_plan_id', 'status',
    ];

    protected function casts(): array
    {
        return [
            'type' => CouponType::class,
            'percentage' => 'integer',
            'amount_minor' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'max_redemptions' => 'integer',
            'per_tenant_limit' => 'integer',
            'redeemed_count' => 'integer',
        ];
    }

    public function applicablePlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'applicable_plan_id');
    }

    public static function normalizeCode(string $code): string
    {
        return strtoupper(trim($code));
    }
}
