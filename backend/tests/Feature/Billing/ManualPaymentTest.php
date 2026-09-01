<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Models\Payment;
use App\Modules\Platform\Models\PlatformAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithBilling;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class ManualPaymentTest extends TestCase
{
    use InteractsWithBilling;
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_platform_admin_records_a_cash_payment_and_pays_invoice(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();
        $invoice = $this->makeInvoice($tenant, [
            'items' => [['description' => 'Business', 'quantity' => 1, 'unit_amount_minor' => 1999]],
        ]);
        $admin = PlatformAdmin::factory()->create();

        $this->actingAs($admin, 'platform')->postJson('/api/platform/payments/manual', [
            'tenant_id' => $tenant->id,
            'invoice_id' => $invoice->id,
            'amount_minor' => 1999,
            'currency' => 'USD',
            'method' => 'cash',
            'reference' => 'Receipt 42',
        ])->assertCreated()->assertJsonPath('status', 'succeeded');

        $this->withinTenant($tenant, function () use ($invoice) {
            $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
            $this->assertSame(1, Payment::query()->count());
        });
    }

    public function test_tenant_user_cannot_record_a_manual_payment(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $invoice = $this->makeInvoice($tenant);

        // The manual-payment route is on the platform guard — a tenant owner is
        // never a platform admin, so cannot manufacture a succeeded payment.
        $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->postJson('/api/platform/payments/manual', [
                'tenant_id' => $tenant->id,
                'invoice_id' => $invoice->id,
                'amount_minor' => 1999,
                'currency' => 'USD',
                'method' => 'manual',
            ])->assertForbidden();

        $this->withinTenant($tenant, fn () => $this->assertSame(0, Payment::query()->count()));
    }
}
