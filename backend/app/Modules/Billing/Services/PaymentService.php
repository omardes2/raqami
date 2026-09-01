<?php

namespace App\Modules\Billing\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\PaymentMethod;
use App\Modules\Billing\Enums\PaymentStatus;
use App\Modules\Billing\Enums\SubscriptionStatus;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Payment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records successful payments and applies them to invoices — the ONE place money
 * is applied to a balance (spec §11, §15, §35). Every application:
 *   - runs in a transaction with the invoice row locked (no race),
 *   - requires the payment currency to match the invoice currency (spec §18),
 *   - rejects overpayment (no account credits in Sprint 2 — spec §15),
 *   - supports partial payments (invoice → partially_paid → paid),
 *   - is idempotent when an idempotency_key is supplied,
 *   - activates/renews the linked subscription once the invoice is fully paid.
 */
class PaymentService
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly SubscriptionManager $subscriptions,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{
     *   amount_minor:int, method:PaymentMethod, currency?:string, provider?:?string,
     *   provider_payment_id?:?string, reference?:?string, idempotency_key?:?string,
     *   approved_by_platform_admin_id?:?string, recorded_by_user_id?:?string,
     *   audit_action?:string, metadata?:array
     * }  $data
     */
    public function applyToInvoice(Invoice $invoice, array $data, mixed $actor = null): Payment
    {
        $key = $data['idempotency_key'] ?? null;
        if ($key !== null) {
            $existing = Payment::query()->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                return $existing; // already applied — idempotent no-op
            }
        }

        $amount = (int) $data['amount_minor'];
        $currency = $data['currency'] ?? $invoice->currency;

        return DB::transaction(function () use ($invoice, $data, $amount, $currency, $key, $actor) {
            /** @var Invoice $locked */
            $locked = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount_minor' => [__('billing.amount_must_be_positive')]]);
            }
            if ($currency !== $locked->currency) {
                throw ValidationException::withMessages(['currency' => [__('billing.currency_mismatch')]]);
            }
            if ($locked->status === InvoiceStatus::Paid || $locked->status === InvoiceStatus::Void) {
                throw ValidationException::withMessages(['invoice' => [__('billing.invoice_not_payable')]]);
            }
            if ($amount > $locked->amount_due_minor) {
                // Overpayment rejected (no credit balance in Sprint 2).
                throw ValidationException::withMessages(['amount_minor' => [__('billing.overpayment_rejected')]]);
            }

            $payment = Payment::query()->create([
                'invoice_id' => $locked->getKey(),
                'subscription_id' => $locked->subscription_id,
                'method' => $data['method'],
                'provider' => $data['provider'] ?? null,
                'provider_payment_id' => $data['provider_payment_id'] ?? null,
                'amount_minor' => $amount,
                'currency' => $currency,
                'status' => PaymentStatus::Succeeded,
                'reference' => $data['reference'] ?? null,
                'paid_at' => now(),
                'approved_at' => now(),
                'approved_by_platform_admin_id' => $data['approved_by_platform_admin_id'] ?? null,
                'recorded_by_user_id' => $data['recorded_by_user_id'] ?? $this->actorUserId($actor),
                'idempotency_key' => $key,
                'metadata' => $data['metadata'] ?? null,
            ]);

            $this->invoices->applyPaymentAmount($locked, $amount);

            // Activate / renew the subscription once the invoice is fully paid.
            if ($locked->status === InvoiceStatus::Paid && $locked->subscription_id !== null) {
                $subscription = $locked->subscription;
                if ($subscription !== null && ! $subscription->status->isTerminal()) {
                    if ($subscription->status === SubscriptionStatus::Active) {
                        $this->subscriptions->renew($subscription, $actor);
                    } else {
                        $this->subscriptions->activate($subscription, $actor);
                    }
                }
            }

            $this->audit->log($data['audit_action'] ?? 'payment.recorded', [
                'actor' => $actor, 'subject' => $payment,
                'metadata' => [
                    'invoice' => $locked->invoice_number,
                    'amount_minor' => $amount,
                    'method' => $payment->method->value,
                    'invoice_status' => $locked->status->value,
                ],
            ]);

            return $payment;
        });
    }

    private function actorUserId(mixed $actor): ?string
    {
        return $actor instanceof Model && ! str_contains($actor::class, 'PlatformAdmin')
            ? (string) $actor->getKey()
            : null;
    }
}
