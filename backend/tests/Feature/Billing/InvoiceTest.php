<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\PaymentMethod;
use App\Modules\Billing\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithBilling;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class InvoiceTest extends TestCase
{
    use InteractsWithBilling;
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_totals_are_computed_server_side_with_discount_and_tax(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();

        $invoice = $this->makeInvoice($tenant, [
            'items' => [
                ['description' => 'Plan', 'quantity' => 2, 'unit_amount_minor' => 1000],
                ['description' => 'Add-on', 'quantity' => 1, 'unit_amount_minor' => 500],
            ],
            'discount_minor' => 500,
            'tax_rate' => 10, // 10% of (2500 - 500) = 200
        ]);

        $this->assertSame(2500, $invoice->subtotal_minor);
        $this->assertSame(500, $invoice->discount_minor);
        $this->assertSame(200, $invoice->tax_minor);
        $this->assertSame(2200, $invoice->total_minor);
        $this->assertSame(2200, $invoice->amount_due_minor);
        $this->assertCount(2, $invoice->items);
    }

    public function test_invoice_numbers_are_unique_and_formatted(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $a = $this->makeInvoice($tenant);
        $b = $this->makeInvoice($tenant);

        $this->assertNotSame($a->invoice_number, $b->invoice_number);
        $this->assertMatchesRegularExpression('/^INV-\d{4}-\d{6}$/', $a->invoice_number);
    }

    public function test_partial_then_full_payment_updates_status(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $invoice = $this->makeInvoice($tenant, [
            'items' => [['description' => 'Plan', 'quantity' => 1, 'unit_amount_minor' => 10000]],
        ]);

        $this->withinTenant($tenant, function () use ($invoice) {
            $payments = app(PaymentService::class);
            $payments->applyToInvoice($invoice, ['amount_minor' => 4000, 'method' => PaymentMethod::Manual]);
            $partial = $invoice->fresh();
            $this->assertSame(InvoiceStatus::PartiallyPaid, $partial->status);
            $this->assertSame(4000, $partial->amount_paid_minor);
            $this->assertSame(6000, $partial->amount_due_minor);

            $payments->applyToInvoice($partial, ['amount_minor' => 6000, 'method' => PaymentMethod::Manual]);
            $paid = $invoice->fresh();
            $this->assertSame(InvoiceStatus::Paid, $paid->status);
            $this->assertSame(0, $paid->amount_due_minor);
            $this->assertNotNull($paid->paid_at);
        });
    }

    public function test_overpayment_is_rejected(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $invoice = $this->makeInvoice($tenant, [
            'items' => [['description' => 'Plan', 'quantity' => 1, 'unit_amount_minor' => 1000]],
        ]);

        $this->withinTenant($tenant, function () use ($invoice) {
            $this->expectException(ValidationException::class);
            app(PaymentService::class)->applyToInvoice($invoice, ['amount_minor' => 1500, 'method' => PaymentMethod::Manual]);
        });
    }

    public function test_cross_tenant_invoice_access_is_blocked(): void
    {
        [, $tenantA] = $this->createCompanyWithOwner(['name' => 'A']);
        [$ownerB, $tenantB] = $this->createCompanyWithOwner(['name' => 'B']);
        $invoiceA = $this->makeInvoice($tenantA);

        $this->actingAs($ownerB)->withHeaders($this->tenantHeaders($tenantB))
            ->getJson("/api/billing/invoices/{$invoiceA->id}")->assertNotFound();
    }
}
