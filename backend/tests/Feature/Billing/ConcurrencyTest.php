<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Enums\PaymentMethod;
use App\Modules\Billing\Models\Coupon;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Services\CouponService;
use App\Modules\Billing\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithBilling;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Financial-concurrency guarantees (spec §8). PHPUnit + RefreshDatabase cannot
 * spawn true parallel connections, so these exercise the actual DB-level guards
 * (row lock + atomic conditional update) that make concurrent duplication
 * impossible, rather than weakening the locks.
 */
class ConcurrencyTest extends TestCase
{
    use InteractsWithBilling;
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_payments_cannot_exceed_invoice_total_and_lock_is_taken(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $invoice = $this->makeInvoice($tenant, [
            'items' => [['description' => 'Plan', 'quantity' => 1, 'unit_amount_minor' => 10000]], // due = 100.00
        ]);

        $this->withinTenant($tenant, function () use ($invoice) {
            $payments = app(PaymentService::class);

            DB::enableQueryLog();
            $payments->applyToInvoice($invoice, ['amount_minor' => 7000, 'method' => PaymentMethod::Manual]);
            $log = collect(DB::getQueryLog())->pluck('query')->implode(' | ');
            // The invoice row is locked FOR UPDATE inside the transaction.
            $this->assertStringContainsStringIgnoringCase('for update', $log);

            // A second 70.00 payment would total 140.00 — rejected as overpayment.
            try {
                $payments->applyToInvoice($invoice->fresh(), ['amount_minor' => 7000, 'method' => PaymentMethod::Manual]);
                $this->fail('Expected overpayment rejection.');
            } catch (ValidationException) {
                // expected
            }

            $this->assertSame(7000, $invoice->fresh()->amount_paid_minor);
            $this->assertSame(1, Payment::query()->count());
        });
    }

    public function test_coupon_max_redemptions_is_atomic_across_tenants(): void
    {
        [, $tenantA] = $this->createCompanyWithOwner(['name' => 'A']);
        [, $tenantB] = $this->createCompanyWithOwner(['name' => 'B']);
        $coupon = $this->makeCoupon(['max_redemptions' => 1]);

        // Tenant A redeems the only slot.
        $this->withinTenant($tenantA, fn () => app(CouponService::class)->redeem($coupon->fresh(), 1000));

        // Tenant B's redemption loses the race — the atomic conditional update
        // (redeemed_count < max_redemptions) rejects it.
        $this->withinTenant($tenantB, function () use ($coupon) {
            $this->expectException(ValidationException::class);
            app(CouponService::class)->redeem($coupon->fresh(), 1000);
        });

        $this->assertSame(1, Coupon::query()->find($coupon->id)->redeemed_count);
    }
}
