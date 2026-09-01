<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Enums\PaymentMethod;
use App\Modules\Billing\Models\BankTransferSubmission;
use App\Modules\Billing\Models\BillingProfile;
use App\Modules\Billing\Models\CouponRedemption;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Models\SubscriptionChange;
use App\Modules\Billing\Services\PaymentService;
use App\Modules\Tenancy\Scopes\TenantScope;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\InteractsWithBilling;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class BillingIsolationTest extends TestCase
{
    use InteractsWithBilling;
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_rls_blocks_cross_tenant_billing_rows(): void
    {
        [, $tenantA] = $this->createCompanyWithOwner(['name' => 'A']);
        [, $tenantB] = $this->createCompanyWithOwner(['name' => 'B']);
        $plan = $this->makePlan();

        $subA = $this->subscribeTenant($tenantA, $plan);
        $invoiceA = $this->makeInvoice($tenantA);
        $coupon = $this->makeCoupon();
        $refs = $this->withinTenant($tenantA, function () use ($subA, $invoiceA, $plan, $coupon) {
            $profile = BillingProfile::query()->create(['legal_name' => 'A Co']);
            $transfer = BankTransferSubmission::query()->create([
                'invoice_id' => $invoiceA->id, 'amount_minor' => 100, 'currency' => 'USD',
                'proof_storage_key' => 'tenants/a/x.pdf', 'original_filename' => 'x.pdf',
                'mime_type' => 'application/pdf', 'size' => 10, 'status' => 'pending_review',
            ]);
            $redemption = CouponRedemption::query()->create([
                'coupon_id' => $coupon->id, 'coupon_code' => $coupon->code, 'discount_minor' => 5,
            ]);
            $payment = app(PaymentService::class)->applyToInvoice($invoiceA, [
                'amount_minor' => 100, 'method' => PaymentMethod::Manual,
            ]);
            $change = SubscriptionChange::query()->create([
                'subscription_id' => $subA->id, 'to_plan_id' => $plan->id,
                'change_type' => 'downgrade', 'effective_at' => now(), 'status' => 'scheduled',
            ]);
            $item = $invoiceA->items()->first();

            return [
                'profile' => $profile->id, 'transfer' => $transfer->id, 'redemption' => $redemption->id,
                'payment' => $payment->id, 'change' => $change->id, 'item' => $item->id,
            ];
        });

        $this->withinTenant($tenantB, function () use ($subA, $invoiceA, $refs) {
            // App scope removed, RLS still hides EVERY tenant A billing table from B.
            $this->assertFalse(Subscription::withoutGlobalScope(TenantScope::class)->whereKey($subA->id)->exists());
            $this->assertFalse(Invoice::withoutGlobalScope(TenantScope::class)->whereKey($invoiceA->id)->exists());
            $this->assertFalse(DB::table('subscriptions')->where('id', $subA->id)->exists());
            $this->assertFalse(DB::table('invoices')->where('id', $invoiceA->id)->exists());
            $this->assertFalse(DB::table('invoice_items')->where('id', $refs['item'])->exists());
            $this->assertFalse(DB::table('payments')->where('id', $refs['payment'])->exists());
            $this->assertFalse(DB::table('subscription_changes')->where('id', $refs['change'])->exists());
            $this->assertFalse(DB::table('billing_profiles')->where('id', $refs['profile'])->exists());
            $this->assertFalse(DB::table('bank_transfer_submissions')->where('id', $refs['transfer'])->exists());
            $this->assertFalse(DB::table('subscription_events')->where('subscription_id', $subA->id)->exists());
            $this->assertFalse(DB::table('coupon_redemptions')->where('id', $refs['redemption'])->exists());
        });
    }

    public function test_platform_readonly_context_cannot_write_tenant_billing(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $invoice = $this->makeInvoice($tenant, ['notes' => 'original']);
        $context = app(TenantContext::class);

        // Under platform read-only, RLS permits SELECT but no UPDATE/INSERT/DELETE
        // of tenant billing rows (tenant_isolation is tenant-only; readonly is
        // SELECT-only). The update matches zero rows and changes nothing.
        $affected = $context->runAsPlatform(fn () => Invoice::withoutGlobalScope(TenantScope::class)
            ->whereKey($invoice->id)->update(['notes' => 'tampered']));
        $this->assertSame(0, $affected);

        $fresh = $this->withinTenant($tenant, fn () => $invoice->fresh());
        $this->assertSame('original', $fresh->notes);
    }

    public function test_cross_tenant_write_is_blocked_by_the_tenant_guard(): void
    {
        [, $tenantA] = $this->createCompanyWithOwner(['name' => 'A']);
        [, $tenantB] = $this->createCompanyWithOwner(['name' => 'B']);

        $this->withinTenant($tenantB, function () use ($tenantA) {
            $this->expectException(RuntimeException::class);
            // Forging another tenant's id on a write is rejected.
            Invoice::query()->create([
                'tenant_id' => $tenantA->id,
                'invoice_number' => 'INV-FORGE',
                'status' => 'draft',
                'currency' => 'USD',
            ]);
        });
    }

    public function test_duplicate_idempotency_key_applies_payment_once(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $invoice = $this->makeInvoice($tenant, [
            'items' => [['description' => 'Plan', 'quantity' => 1, 'unit_amount_minor' => 5000]],
        ]);

        $this->withinTenant($tenant, function () use ($invoice) {
            $payments = app(PaymentService::class);
            $first = $payments->applyToInvoice($invoice, [
                'amount_minor' => 5000, 'method' => PaymentMethod::BankTransfer, 'idempotency_key' => 'dup-key-1',
            ]);
            $second = $payments->applyToInvoice($invoice->fresh(), [
                'amount_minor' => 5000, 'method' => PaymentMethod::BankTransfer, 'idempotency_key' => 'dup-key-1',
            ]);

            $this->assertSame($first->id, $second->id); // same payment returned
            $this->assertSame(1, Payment::query()->count());
            $this->assertSame(5000, $invoice->fresh()->amount_paid_minor); // applied once
        });
    }
}
