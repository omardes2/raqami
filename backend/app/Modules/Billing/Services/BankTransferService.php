<?php

namespace App\Modules\Billing\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Enums\BankTransferStatus;
use App\Modules\Billing\Enums\PaymentMethod;
use App\Modules\Billing\Models\BankTransferSubmission;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Payment;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\PlatformAdmin;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Bank-transfer submission + review workflow (spec §13). The tenant submits proof
 * (private file) for an invoice; a PLATFORM admin (never the tenant) approves or
 * rejects. Approval is transactional and idempotent — it creates the payment,
 * applies it to the invoice, and activates/renews the subscription — with the
 * submission row locked so it cannot be approved twice.
 *
 * Writes happen inside the submission's tenant context so PostgreSQL RLS
 * (WITH CHECK tenant_id = GUC) is satisfied even though the actor is a platform
 * admin operating cross-tenant.
 */
class BankTransferService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly PaymentService $payments,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Tenant-side submission. Called within the tenant request context. The file
     * is already stored privately; only its key/metadata are passed in.
     *
     * @param  array{invoice_id:string, amount_minor:int, currency:string,
     *   transfer_reference?:?string, proof_storage_key:string,
     *   original_filename:string, mime_type:string, size:int}  $data
     */
    public function submit(array $data, User $actor): BankTransferSubmission
    {
        // Invoice must belong to the active tenant (RLS-scoped lookup).
        $invoice = Invoice::query()->whereKey($data['invoice_id'])->firstOrFail();

        return DB::transaction(function () use ($invoice, $data, $actor) {
            $submission = BankTransferSubmission::query()->create([
                'invoice_id' => $invoice->getKey(),
                'amount_minor' => (int) $data['amount_minor'],
                'currency' => $data['currency'],
                'transfer_reference' => $data['transfer_reference'] ?? null,
                'proof_storage_key' => $data['proof_storage_key'],
                'original_filename' => $data['original_filename'],
                'mime_type' => $data['mime_type'],
                'size' => (int) $data['size'],
                'status' => BankTransferStatus::PendingReview,
                'submitted_by_user_id' => (string) $actor->getKey(),
            ]);

            $this->audit->log('bank_transfer.submitted', [
                'actor' => $actor, 'subject' => $submission,
                'metadata' => ['invoice' => $invoice->invoice_number, 'amount_minor' => $submission->amount_minor],
            ]);

            return $submission;
        });
    }

    /**
     * Platform admin approval. Runs inside the submission's tenant context. Locks
     * the submission, verifies it is still pending (double-approval guard), then
     * creates + applies the payment transactionally.
     */
    public function approve(BankTransferSubmission $submission, PlatformAdmin $admin): Payment
    {
        return $this->context->runAs($submission->tenant_id, function () use ($submission, $admin) {
            return DB::transaction(function () use ($submission, $admin) {
                /** @var BankTransferSubmission $locked */
                $locked = BankTransferSubmission::query()->whereKey($submission->getKey())->lockForUpdate()->firstOrFail();

                if ($locked->status !== BankTransferStatus::PendingReview) {
                    throw new RuntimeException('Bank transfer is not pending review (already reviewed).');
                }

                $invoice = Invoice::query()->whereKey($locked->invoice_id)->firstOrFail();

                $payment = $this->payments->applyToInvoice($invoice, [
                    'amount_minor' => $locked->amount_minor,
                    'currency' => $locked->currency,
                    'method' => PaymentMethod::BankTransfer,
                    'reference' => $locked->transfer_reference,
                    'approved_by_platform_admin_id' => (string) $admin->getKey(),
                    'idempotency_key' => 'bank_transfer:'.$locked->getKey(),
                    'audit_action' => 'payment.approved',
                    'metadata' => ['source' => 'bank_transfer', 'submission_id' => $locked->getKey()],
                ], $admin);

                $locked->status = BankTransferStatus::Approved;
                $locked->reviewed_by_platform_admin_id = (string) $admin->getKey();
                $locked->reviewed_at = now();
                $locked->payment_id = $payment->getKey();
                $locked->save();

                $this->audit->log('bank_transfer.approved', [
                    'actor' => $admin, 'subject' => $locked,
                    'metadata' => ['invoice' => $invoice->invoice_number, 'payment_id' => $payment->getKey()],
                ]);

                return $payment;
            });
        });
    }

    public function reject(BankTransferSubmission $submission, PlatformAdmin $admin, ?string $reason = null): BankTransferSubmission
    {
        return $this->context->runAs($submission->tenant_id, function () use ($submission, $admin, $reason) {
            return DB::transaction(function () use ($submission, $admin, $reason) {
                /** @var BankTransferSubmission $locked */
                $locked = BankTransferSubmission::query()->whereKey($submission->getKey())->lockForUpdate()->firstOrFail();

                if ($locked->status !== BankTransferStatus::PendingReview) {
                    throw new RuntimeException('Bank transfer is not pending review (already reviewed).');
                }

                $locked->status = BankTransferStatus::Rejected;
                $locked->reviewed_by_platform_admin_id = (string) $admin->getKey();
                $locked->reviewed_at = now();
                $locked->rejection_reason = $reason;
                $locked->save();

                $this->audit->log('bank_transfer.rejected', [
                    'actor' => $admin, 'subject' => $locked,
                    'metadata' => ['reason' => $reason],
                ]);

                return $locked;
            });
        });
    }
}
