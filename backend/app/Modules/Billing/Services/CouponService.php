<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Enums\CouponType;
use App\Modules\Billing\Models\Coupon;
use App\Modules\Billing\Models\CouponRedemption;
use App\Modules\Billing\Models\Plan;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Validates and redeems platform coupons (spec §16). Global limits are enforced
 * against the coupon's own redeemed_count (no cross-tenant reads); per-tenant
 * limits against this tenant's coupon_redemptions (RLS-scoped). No coupon
 * stacking in Sprint 2 — a caller applies at most one coupon.
 */
class CouponService
{
    /**
     * Resolve and validate a coupon for the given plan/currency. Throws a
     * localized ValidationException when the code is invalid or not applicable.
     */
    public function validate(string $code, ?Plan $plan = null, ?string $currency = null): Coupon
    {
        $coupon = Coupon::query()->where('code', Coupon::normalizeCode($code))->first();

        $fail = fn (string $key) => throw ValidationException::withMessages([
            'coupon_code' => [__("billing.{$key}")],
        ]);

        if ($coupon === null || $coupon->status !== 'active') {
            $fail('coupon_invalid');
        }

        $now = now();
        if ($coupon->starts_at !== null && $now->lt($coupon->starts_at)) {
            $fail('coupon_not_started');
        }
        if ($coupon->ends_at !== null && $now->gt($coupon->ends_at)) {
            $fail('coupon_expired');
        }
        if ($coupon->max_redemptions !== null && $coupon->redeemed_count >= $coupon->max_redemptions) {
            $fail('coupon_exhausted');
        }
        if ($coupon->applicable_plan_id !== null && $plan !== null && $coupon->applicable_plan_id !== $plan->getKey()) {
            $fail('coupon_plan_mismatch');
        }
        if ($coupon->type === CouponType::FixedAmount && $currency !== null
            && $coupon->currency !== null && $coupon->currency !== $currency) {
            $fail('coupon_currency_mismatch');
        }

        // Per-tenant redemption limit (RLS-scoped to the active tenant).
        if ($coupon->per_tenant_limit !== null) {
            $used = CouponRedemption::query()->where('coupon_id', $coupon->getKey())->count();
            if ($used >= $coupon->per_tenant_limit) {
                $fail('coupon_tenant_limit');
            }
        }

        return $coupon;
    }

    /** Discount (minor units) a coupon yields against a base amount. */
    public function computeDiscount(Coupon $coupon, int $baseMinor): int
    {
        $discount = $coupon->type === CouponType::Percentage
            ? (int) round($baseMinor * (int) $coupon->percentage / 100)
            : (int) $coupon->amount_minor;

        return max(0, min($discount, $baseMinor));
    }

    /**
     * Redeem the coupon: atomically bump the global tally (re-checking
     * max_redemptions to avoid a race) and record a tenant redemption row.
     * Returns the discount applied. Call inside a transaction.
     */
    public function redeem(Coupon $coupon, int $baseMinor, array $context = [], mixed $actor = null): int
    {
        $discount = $this->computeDiscount($coupon, $baseMinor);

        // Conditional atomic increment: only succeeds while under the cap.
        $affected = DB::table('coupons')
            ->where('id', $coupon->getKey())
            ->when($coupon->max_redemptions !== null, fn ($q) => $q->whereRaw('redeemed_count < max_redemptions'))
            ->update(['redeemed_count' => DB::raw('redeemed_count + 1'), 'updated_at' => now()]);

        if ($affected === 0) {
            throw ValidationException::withMessages([
                'coupon_code' => [__('billing.coupon_exhausted')],
            ]);
        }

        CouponRedemption::query()->create([
            'coupon_id' => $coupon->getKey(),
            'coupon_code' => $coupon->code,
            'subscription_id' => $context['subscription_id'] ?? null,
            'invoice_id' => $context['invoice_id'] ?? null,
            'redeemed_by_user_id' => $actor instanceof Model && ! str_contains($actor::class, 'PlatformAdmin')
                ? (string) $actor->getKey() : null,
            'discount_minor' => $discount,
        ]);

        return $discount;
    }
}
