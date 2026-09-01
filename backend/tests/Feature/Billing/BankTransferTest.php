<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Enums\BankTransferStatus;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Models\BankTransferSubmission;
use App\Modules\Billing\Models\Payment;
use App\Modules\Platform\Models\PlatformAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithBilling;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class BankTransferTest extends TestCase
{
    use InteractsWithBilling;
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function submitProof($owner, $tenant, $invoice, int $amount)
    {
        return $this->actingAs($owner)->withHeaders($this->tenantHeaders($tenant))
            ->postJson('/api/billing/bank-transfers', [
                'invoice_id' => $invoice->id,
                'amount_minor' => $amount,
                'currency' => 'USD',
                'transfer_reference' => 'REF-1',
                'proof' => UploadedFile::fake()->create('receipt.pdf', 50, 'application/pdf'),
            ]);
    }

    public function test_tenant_submits_and_receipt_key_is_never_exposed(): void
    {
        Storage::fake('local');
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $invoice = $this->makeInvoice($tenant);

        $response = $this->submitProof($owner, $tenant, $invoice, $invoice->total_minor)->assertCreated();
        $response->assertJsonPath('status', 'pending_review');
        $this->assertStringNotContainsString('proof_storage_key', $response->getContent());
        $this->assertStringNotContainsString('tenants/', $response->getContent());
    }

    public function test_tenant_cannot_approve_a_bank_transfer(): void
    {
        Storage::fake('local');
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $invoice = $this->makeInvoice($tenant);
        $this->submitProof($owner, $tenant, $invoice, $invoice->total_minor)->assertCreated();
        $submission = $this->withinTenant($tenant, fn () => BankTransferSubmission::query()->first());

        // A tenant user is not a platform admin — the review route is off-limits.
        $this->actingAs($owner)
            ->postJson("/api/platform/bank-transfers/{$submission->id}/approve")
            ->assertForbidden();
    }

    public function test_platform_approval_creates_payment_and_pays_invoice(): void
    {
        Storage::fake('local');
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $plan = $this->makePlan(['trial_days' => 0, 'monthly_price_minor' => 1999]);
        $sub = $this->subscribeTenant($tenant, $plan, ['trial' => false]);
        $invoice = $this->makeInvoice($tenant, ['subscription_id' => $sub->id,
            'items' => [['description' => 'Business', 'quantity' => 1, 'unit_amount_minor' => 1999]]]);
        $this->submitProof($owner, $tenant, $invoice, 1999)->assertCreated();
        $submission = $this->withinTenant($tenant, fn () => BankTransferSubmission::query()->first());
        $admin = PlatformAdmin::factory()->create();

        $this->actingAs($admin, 'platform')
            ->postJson("/api/platform/bank-transfers/{$submission->id}/approve")
            ->assertOk()
            ->assertJsonPath('payment.status', 'succeeded');

        $this->withinTenant($tenant, function () use ($invoice) {
            $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
            $this->assertSame(1, Payment::query()->count());
        });
    }

    public function test_double_approval_is_blocked(): void
    {
        Storage::fake('local');
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $invoice = $this->makeInvoice($tenant);
        $this->submitProof($owner, $tenant, $invoice, $invoice->total_minor)->assertCreated();
        $submission = $this->withinTenant($tenant, fn () => BankTransferSubmission::query()->first());
        $admin = PlatformAdmin::factory()->create();

        $this->actingAs($admin, 'platform')->postJson("/api/platform/bank-transfers/{$submission->id}/approve")->assertOk();
        // Second approval is rejected and creates no second payment.
        $this->actingAs($admin, 'platform')->postJson("/api/platform/bank-transfers/{$submission->id}/approve")->assertStatus(422);

        $this->withinTenant($tenant, fn () => $this->assertSame(1, Payment::query()->count()));
    }

    public function test_rejection_flow(): void
    {
        Storage::fake('local');
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $invoice = $this->makeInvoice($tenant);
        $this->submitProof($owner, $tenant, $invoice, $invoice->total_minor)->assertCreated();
        $submission = $this->withinTenant($tenant, fn () => BankTransferSubmission::query()->first());
        $admin = PlatformAdmin::factory()->create();

        $this->actingAs($admin, 'platform')
            ->postJson("/api/platform/bank-transfers/{$submission->id}/reject", ['reason' => 'Amount mismatch'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->withinTenant($tenant, function () use ($submission) {
            $this->assertSame(BankTransferStatus::Rejected, $submission->fresh()->status);
            $this->assertSame(0, Payment::query()->count());
        });
    }

    public function test_cross_tenant_proof_access_is_blocked(): void
    {
        Storage::fake('local');
        [$ownerA, $tenantA] = $this->createCompanyWithOwner(['name' => 'A']);
        [$ownerB, $tenantB] = $this->createCompanyWithOwner(['name' => 'B']);
        $invoiceA = $this->makeInvoice($tenantA);
        $this->submitProof($ownerA, $tenantA, $invoiceA, $invoiceA->total_minor)->assertCreated();
        $submissionA = $this->withinTenant($tenantA, fn () => BankTransferSubmission::query()->first());

        $this->actingAs($ownerB)->withHeaders($this->tenantHeaders($tenantB))
            ->getJson("/api/billing/bank-transfers/{$submissionA->id}/proof")->assertNotFound();
    }
}
